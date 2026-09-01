<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.x / DOCUMENT 6.2 §3 — mandatory social recovery: M-of-N
 * guardians, a timelocked challenge window, a deliberately high-friction
 * path, NEVER an email reset.
 *
 * The design trade, stated openly: recovery converts the catastrophe of
 * total key loss into the smaller, bounded risk of a contested
 * recovery — which the timelock and challenge window mitigate but do
 * not eliminate. A completed recovery always produces a decision
 * receipt and places the account under elevated monitoring.
 *
 * Twice enforced here (the third layer is the property test):
 *  - service guards below;
 *  - DB trigger recovery_guard_completion(): born-initiated only,
 *    window must be a real interval, completion requires elapsed
 *    window + uncontested + M-of-N approvals + receipt + monitoring.
 *
 * Approvals are Ed25519 signatures by the registered guardians over
 * the attempt's binding message — a guardian's word is verifiable, not
 * assumed. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Identity\Recovery;

use App\Domain\Identity\Enums\RecoveryStatus;
use App\Domain\Identity\Exceptions\RecoveryProcessException;
use App\Domain\Identity\Models\GuardianSet;
use App\Domain\Identity\Models\Identity;
use App\Domain\Identity\Models\RecoveryAttempt;
use Illuminate\Support\Carbon;

final class SocialRecoveryService
{
    /** The minimum timelocked challenge window, in days. */
    public const MIN_CHALLENGE_WINDOW_DAYS = 7;

    /**
     * Register a user's chosen M-of-N guardian set.
     *
     * @param list<string> $guardianPublicKeys base64 Ed25519 public keys
     */
    public function registerGuardians(Identity $identity, array $guardianPublicKeys, int $threshold): GuardianSet
    {
        $keys = array_values(array_unique($guardianPublicKeys));

        if ($threshold < 2) {
            throw new RecoveryProcessException(
                'RECOVERY: the threshold must be at least 2 — recovery never collapses to one party\'s say-so.'
            );
        }

        if (count($keys) < $threshold) {
            throw new RecoveryProcessException(sprintf(
                'RECOVERY: %d guardians cannot satisfy an M-of-N threshold of %d.',
                count($keys),
                $threshold,
            ));
        }

        foreach ($keys as $key) {
            $decoded = base64_decode($key, true);

            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new RecoveryProcessException('RECOVERY: every guardian key must be a valid Ed25519 public key.');
            }
        }

        $set = GuardianSet::query()->where('identity_id', $identity->id)->first();

        if ($set !== null) {
            $set->guardian_public_keys = $keys;
            $set->threshold = $threshold;
            $set->save();

            return $set;
        }

        $set = new GuardianSet([
            'identity_id' => $identity->id,
            'guardian_public_keys' => $keys,
            'threshold' => $threshold,
        ]);
        $set->save();

        return $set;
    }

    /**
     * Initiate a recovery attempt: born Initiated, with a challenge
     * window no shorter than the floor. Nothing changes hands here —
     * initiation only starts the public, contestable clock.
     */
    public function initiate(GuardianSet $set, string $newKeyCommitment, int $windowDays = self::MIN_CHALLENGE_WINDOW_DAYS): RecoveryAttempt
    {
        if (trim($newKeyCommitment) === '') {
            throw new RecoveryProcessException('RECOVERY: a new-key commitment is required.');
        }

        if ($windowDays < self::MIN_CHALLENGE_WINDOW_DAYS) {
            throw new RecoveryProcessException(sprintf(
                'RECOVERY: the challenge window may not be shorter than %d days — the window is what makes a malicious recovery contestable.',
                self::MIN_CHALLENGE_WINDOW_DAYS,
            ));
        }

        $attempt = new RecoveryAttempt([
            'guardian_set_id' => $set->id,
            'new_key_commitment' => $newKeyCommitment,
            'status' => RecoveryStatus::Initiated,
            'initiated_at' => Carbon::now(),
            'challenge_window_ends_at' => Carbon::now()->addDays($windowDays),
            'guardian_approvals' => [],
        ]);
        $attempt->save();

        return $attempt;
    }

    /**
     * A guardian approves by SIGNING the attempt's binding message.
     * Unregistered keys and invalid signatures are refused; duplicate
     * approvals by the same guardian count once.
     */
    public function approve(RecoveryAttempt $attempt, string $guardianPublicKey, string $signatureBase64): RecoveryAttempt
    {
        if ($attempt->status !== RecoveryStatus::Initiated) {
            throw new RecoveryProcessException('RECOVERY: only an initiated, uncontested attempt accepts approvals.');
        }

        $set = GuardianSet::query()->findOrFail($attempt->guardian_set_id);

        if (! in_array($guardianPublicKey, $set->guardian_public_keys, true)) {
            throw new RecoveryProcessException('RECOVERY: that key is not a registered guardian for this identity.');
        }

        $publicKey = base64_decode($guardianPublicKey, true);
        $signature = base64_decode($signatureBase64, true);

        if (
            $publicKey === false || $signature === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, self::bindingMessage($attempt), $publicKey)
        ) {
            throw new RecoveryProcessException('RECOVERY: the guardian signature does not verify; an unverifiable approval is no approval.');
        }

        $approvals = $attempt->guardian_approvals;

        foreach ($approvals as $approval) {
            if ($approval['guardian_key'] === $guardianPublicKey) {
                return $attempt; // one guardian, one voice
            }
        }

        $approvals[] = ['guardian_key' => $guardianPublicKey, 'signature' => $signatureBase64];
        $attempt->guardian_approvals = $approvals;
        $attempt->save();

        return $attempt;
    }

    /**
     * Anyone holding the account's standing may contest during the
     * window — contesting is deliberately cheap, completing is
     * deliberately hard.
     */
    public function contest(RecoveryAttempt $attempt): RecoveryAttempt
    {
        if (! in_array($attempt->status, [RecoveryStatus::Initiated, RecoveryStatus::Contested], true)) {
            throw new RecoveryProcessException('RECOVERY: only a live attempt can be contested.');
        }

        $attempt->status = RecoveryStatus::Contested;
        $attempt->contested_at = Carbon::now();
        $attempt->save();

        return $attempt;
    }

    /**
     * Complete: only after the window, only uncontested, only at
     * threshold. Produces a decision receipt and elevated monitoring.
     * The DB trigger independently re-verifies every one of these.
     */
    public function complete(RecoveryAttempt $attempt): RecoveryAttempt
    {
        if ($attempt->status !== RecoveryStatus::Initiated) {
            throw new RecoveryProcessException(sprintf(
                'RECOVERY: a %s attempt cannot complete; only an initiated, uncontested one.',
                $attempt->status->value,
            ));
        }

        if (Carbon::now()->lessThan($attempt->challenge_window_ends_at)) {
            throw new RecoveryProcessException(
                'RECOVERY: the timelocked challenge window has not elapsed; '
                .'a malicious recovery must remain contestable for the full window.'
            );
        }

        $set = GuardianSet::query()->findOrFail($attempt->guardian_set_id);

        if (count($attempt->guardian_approvals) < $set->threshold) {
            throw new RecoveryProcessException(sprintf(
                'RECOVERY: %d of %d required guardian approvals present; sub-threshold recovery is refused.',
                count($attempt->guardian_approvals),
                $set->threshold,
            ));
        }

        $attempt->status = RecoveryStatus::Completed;
        $attempt->completed_at = Carbon::now();
        $attempt->decision_receipt = sprintf(
            'recovery %s completed at %s: %d/%d guardian approvals, window %s → %s, uncontested',
            $attempt->id,
            Carbon::now()->toIso8601String(),
            count($attempt->guardian_approvals),
            $set->threshold,
            $attempt->initiated_at->toIso8601String(),
            $attempt->challenge_window_ends_at->toIso8601String(),
        );
        $attempt->elevated_monitoring = true;
        $attempt->save();

        return $attempt;
    }

    /**
     * The message a guardian signs: binds the attempt id, the set, and
     * the new key commitment, so an approval cannot be replayed onto a
     * different attempt.
     */
    public static function bindingMessage(RecoveryAttempt $attempt): string
    {
        return implode('|', [
            'recovery-approval',
            $attempt->id,
            $attempt->guardian_set_id,
            $attempt->new_key_commitment,
        ]);
    }
}
