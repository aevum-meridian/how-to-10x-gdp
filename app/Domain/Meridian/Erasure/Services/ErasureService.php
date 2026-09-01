<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-6.5 / DOCUMENT 6.5 — reconciling append-only immutability with the
 * right to be forgotten. © Maher
 *
 * NO PII lives in immutable ledger entries. It lives HERE, off-ledger,
 * encrypted under a per-record key. "Erasure" is crypto-shredding:
 * destroy the record and its key, leave an immutable tombstone proving a
 * fact occurred without revealing who it concerned. The ledger's
 * integrity (I5) and the person's erasure right are both honored; the
 * economic record satisfies retention law forever, the personal data
 * satisfies erasure law on request.
 *
 * The one channel through which retention can override erasure is the
 * LEGAL HOLD: PII that is evidence in an open attestation_dispute cannot
 * be shredded until the case closes — and the hold is BOUNDED (a defined
 * maximum, HOLD_MAX_DAYS) and DISCLOSED (the person gets the reason and
 * the timeline). Deliberately narrow, deliberately time-bound: the
 * Coercion-Resistance intersection means a compeller must never be able
 * to abuse "open dispute" into indefinite retention.
 *
 * THE HONEST CAVEAT (constitutional, must accompany every erasure
 * claim): crypto-shredding's deletion is CRYPTOGRAPHIC, NOT PHYSICAL.
 * Encrypted copies may persist in backups; what is destroyed is the key.
 * Unrecoverable as long as the cryptography holds — conditional on the
 * crypto, never claimed as absolute physical deletion.
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Erasure\Services;

use App\Domain\Meridian\Erasure\Exceptions\LegalHoldException;
use App\Domain\Meridian\Erasure\Models\ErasureHold;
use App\Domain\Meridian\Erasure\Models\ErasureTombstone;
use App\Domain\Meridian\Erasure\Models\PiiEncryptionKey;
use App\Domain\Meridian\Erasure\Models\PiiRecord;
use Illuminate\Support\Facades\DB;

final class ErasureService
{
    /**
     * The bounded maximum of a legal hold (DOCUMENT 6.5 §3): after this
     * many days, erasure proceeds even if the proceeding is still open.
     * The bound is constitutional, not procedural.
     */
    public const HOLD_MAX_DAYS = 180;

    /**
     * The caveat that must accompany every erasure confirmation
     * (DOCUMENT 6.5 §6).
     */
    public const ERASURE_CAVEAT =
        'Deletion is cryptographic, not physical: encrypted copies may persist in backups, '
        .'but the key that could read them has been destroyed. This is unrecoverable as long '
        .'as the cryptography holds.';

    /**
     * Store PII off-ledger: encrypt the payload under a fresh per-record
     * secretbox key. The on-ledger side references the subject only by
     * opaque ULID — it NEVER embeds this payload.
     *
     * @param array<string, string> $payload
     */
    public function storePii(string $subjectReference, string $purpose, array $payload): PiiRecord
    {
        if ($payload === []) {
            throw new \InvalidArgumentException('An empty PII payload stores nothing and proves nothing.');
        }

        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);
        $ciphertext = base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $key));

        return DB::transaction(function () use ($subjectReference, $purpose, $ciphertext, $key): PiiRecord {
            $keyRow = new PiiEncryptionKey(['key_material' => base64_encode($key)]);
            $keyRow->save();

            $record = new PiiRecord([
                'subject_reference' => $subjectReference,
                'purpose' => $purpose,
                'ciphertext' => $ciphertext,
                'key_id' => $keyRow->id,
            ]);
            $record->save();

            return $record;
        });
    }

    /**
     * Decrypt a still-living record (legitimate use while it exists).
     * After shredding, this is impossible by construction: the key row
     * is gone.
     *
     * @return array<string, string>
     */
    public function readPii(PiiRecord $record): array
    {
        $keyRow = PiiEncryptionKey::query()->findOrFail($record->key_id);

        $key = base64_decode($keyRow->key_material, true);
        $blob = base64_decode($record->ciphertext, true);

        if ($key === false || $blob === false || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('The PII record is corrupt and cannot be decrypted.');
        }

        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($box, $nonce, $key);

        if ($plaintext === false) {
            throw new \RuntimeException('The PII record does not decrypt under its registered key.');
        }

        /** @var array<string, string> $payload */
        $payload = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * The erasure request. Either the record is crypto-shredded NOW
     * (tombstone written, record destroyed, key destroyed), or — if the
     * record is evidence in an open dispute — a DISCLOSED, BOUNDED hold
     * is recorded and the refusal carries the reason and the timeline to
     * the requesting person.
     *
     * @return ErasureTombstone the immutable proof the shred occurred
     */
    public function erase(PiiRecord $record, string $reason): ErasureTombstone
    {
        // The hold path lives OUTSIDE the shred transaction: the recorded
        // hold and its disclosure must SURVIVE the refusal — a rollback
        // that erased the very record of why erasure was deferred would
        // defeat the disclosure obligation.
        $openDisputeId = $this->openDisputeIdFor($record->subject_reference);

        if ($openDisputeId !== null && ! $this->holdHasExpired($record)) {
            $hold = $this->ensureDisclosedHold($record, $openDisputeId);

            throw new LegalHoldException(
                'Your erasure request is honored but DEFERRED for this record: it is evidence in the open '
                ."dispute {$openDisputeId}. Reason: {$hold->disclosed_reason} "
                .'The hold ends when the case closes, and no later than '
                .$hold->hold_expires_at->toIso8601String()
                .' (the bounded maximum). It will not be extended silently.'
            );
        }

        return DB::transaction(function () use ($record, $reason): ErasureTombstone {
            $tombstone = new ErasureTombstone([
                'pii_record_id' => $record->id,
                'subject_digest' => hash('sha256', $record->id.'|'.$record->purpose),
                'reason' => $reason.' — '.self::ERASURE_CAVEAT,
            ]);
            $tombstone->save();

            // Crypto-shredding: the record AND its key are destroyed.
            // The DB trigger independently verifies the tombstone exists
            // and no un-expired legal hold applies before admitting the
            // DELETE.
            $keyId = $record->key_id;
            $record->delete();
            PiiEncryptionKey::query()->whereKey($keyId)->delete();

            return $tombstone;
        });
    }

    /**
     * Whether a record can currently be shredded — the answer the
     * requesting person is entitled to before asking.
     */
    public function erasable(PiiRecord $record): bool
    {
        return $this->openDisputeIdFor($record->subject_reference) === null
            || $this->holdHasExpired($record);
    }

    /**
     * An open dispute whose attestation names this subject as recipient —
     * the ONLY basis for a hold.
     */
    private function openDisputeIdFor(string $subjectReference): ?string
    {
        /** @var object{id: string}|null $row */
        $row = DB::table('attestation_disputes as ad')
            ->join('attestations as a', 'a.id', '=', 'ad.attestation_id')
            ->where('a.recipient_account_id', $subjectReference)
            ->whereNotIn('ad.status', ['resolved_fraud', 'resolved_valid'])
            ->select('ad.id')
            ->orderBy('ad.created_at')
            ->first();

        return $row?->id;
    }

    /**
     * The bounded maximum: once a disclosed hold on this record has
     * reached its expiry, retention may no longer override erasure —
     * even if the proceeding is still open.
     */
    private function holdHasExpired(PiiRecord $record): bool
    {
        return ErasureHold::query()
            ->where('pii_record_id', $record->id)
            ->where('hold_expires_at', '<=', now())
            ->exists();
    }

    /**
     * Record (idempotently) the disclosed hold: dispute named, reason
     * stated, expiry fixed at creation and never silently extended.
     */
    private function ensureDisclosedHold(PiiRecord $record, string $disputeId): ErasureHold
    {
        $existing = ErasureHold::query()
            ->where('pii_record_id', $record->id)
            ->where('dispute_id', $disputeId)
            ->whereNull('released_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $hold = new ErasureHold([
            'pii_record_id' => $record->id,
            'dispute_id' => $disputeId,
            'disclosed_reason' => 'This record is evidence in an open dispute; destroying the data that proves '
                .'who committed a fraud would defeat the immune system. The hold lasts only as long as the open '
                .'proceeding, with a defined maximum of '.self::HOLD_MAX_DAYS.' days.',
            'hold_expires_at' => now()->addDays(self::HOLD_MAX_DAYS),
        ]);
        $hold->save();

        return $hold;
    }
}
