<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * DEV-7.1 — the chained stream's append-and-verify surface. Carries
 * proposals one way and confirmations the other (DOCUMENT 7.1) —
 * never commands. This service:
 *
 *  - append(): canonicalizes the payload, links prev_hash to the
 *    latest event, computes entry_hash, signs it with the originating
 *    leg's Ed25519 key, and inserts. The DB trigger independently
 *    recomputes the hash and re-verifies the link — the writer's
 *    arithmetic is never trusted. A replayed idempotency key returns
 *    the original event as a no-op.
 *
 *  - verifyChain(): walks the whole stream, recomputing every hash,
 *    re-checking every link, and verifying every signature against
 *    the registered signer keys. Tampering is detectable; the walk
 *    reports exactly where.
 *
 * This class authors NO ledger row: emitting an event moves no value.
 * A proposal is inert until Meridian's ingress validates it.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Domain\Joint\EventContract\Services;

use App\Domain\Joint\EventContract\Data\ChainVerification;
use App\Domain\Joint\EventContract\Data\EmittedEvent;
use App\Domain\Joint\EventContract\Enums\EventKind;
use App\Domain\Joint\EventContract\Enums\EventSource;
use App\Domain\Joint\EventContract\Enums\EventStatus;
use App\Domain\Joint\EventContract\Exceptions\ChainIntegrityException;
use App\Domain\Joint\EventContract\Models\CrossSystemEvent;
use App\Domain\Joint\EventContract\Models\EventSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventChainService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Append a signed, chained event. $secretKey is the ORIGINATING
     * leg's Ed25519 secret key — each leg holds its own; neither can
     * forge the other's voice.
     *
     * @param array<string, mixed> $payload
     */
    public function append(
        EventSource $source,
        EventKind $kind,
        array $payload,
        string $idempotencyKey,
        string $secretKey,
    ): EmittedEvent {
        if ($secretKey === '') {
            throw new \InvalidArgumentException(
                'EVENT CONTRACT: an empty signing key cannot speak for a system.'
            );
        }

        $existing = CrossSystemEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return new EmittedEvent(event: $existing, replayed: true);
        }

        try {
            $event = DB::transaction(function () use ($source, $kind, $payload, $idempotencyKey, $secretKey): CrossSystemEvent {
                // Serialize appends: the chain has exactly one head.
                DB::unprepared("SELECT pg_advisory_xact_lock(hashtext('cross_system_events_chain'))");

                /** @var object{entry_hash: string}|null $latest */
                $latest = DB::selectOne(
                    'SELECT entry_hash FROM cross_system_events ORDER BY seq DESC LIMIT 1'
                );
                $prevHash = $latest->entry_hash ?? self::GENESIS_HASH;

                $id = strtolower((string) Str::ulid());
                $canonical = $this->canonicalize($payload);

                $entryHash = hash('sha256', implode('|', [
                    $id, $source->value, $kind->value, $canonical, $idempotencyKey, $prevHash,
                ]));

                $signature = base64_encode(sodium_crypto_sign_detached($entryHash, $secretKey));

                $event = new CrossSystemEvent([
                    'id' => $id,
                    'source' => $source,
                    'kind' => $kind,
                    'payload' => $payload,
                    'canonical_payload' => $canonical,
                    'prev_hash' => $prevHash,
                    'entry_hash' => $entryHash,
                    'signature' => $signature,
                    'idempotency_key' => $idempotencyKey,
                    'status' => EventStatus::Emitted,
                    'created_at' => now(),
                ]);
                $event->save();

                return $event;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Concurrent replay lost the race — the stream already
            // carries the event; return the original outcome.
            $event = CrossSystemEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return new EmittedEvent(event: $event, replayed: true);
        }

        return new EmittedEvent(event: $event->refresh(), replayed: false);
    }

    /**
     * Walk the entire chain: every link, every hash, every signature.
     *
     * @return ChainVerification the detector's report — intact, or
     *                           broken with the exact defects
     */
    public function verifyChain(): ChainVerification
    {
        $defects = [];
        $expectedPrev = self::GENESIS_HASH;
        $verified = 0;

        $keysBySource = [];
        foreach (EventSigner::query()->where('status', 'active')->get() as $signer) {
            $keysBySource[$signer->source->value] = base64_decode($signer->public_key, true);
        }

        foreach (CrossSystemEvent::query()->orderBy('seq')->lazy() as $event) {
            if ($event->prev_hash !== $expectedPrev) {
                $defects[] = [
                    'seq' => $event->seq,
                    'event_id' => $event->id,
                    'defect' => "broken link: prev_hash {$event->prev_hash} does not match prior entry_hash {$expectedPrev}",
                ];
            }

            $recomputed = hash('sha256', $event->hashablePayload());

            if ($recomputed !== $event->entry_hash) {
                $defects[] = [
                    'seq' => $event->seq,
                    'event_id' => $event->id,
                    'defect' => 'entry_hash does not recompute from content — the event was tampered with',
                ];
            }

            $publicKey = $keysBySource[$event->source->value] ?? null;
            $rawSignature = base64_decode($event->signature, true);

            if (! is_string($publicKey) || $publicKey === ''
                || ! is_string($rawSignature) || $rawSignature === ''
                || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || ! sodium_crypto_sign_verify_detached($rawSignature, $event->entry_hash, $publicKey)
            ) {
                $defects[] = [
                    'seq' => $event->seq,
                    'event_id' => $event->id,
                    'defect' => "signature does not verify against {$event->source->value}'s registered key — forgery is detectable",
                ];
            }

            $expectedPrev = $event->entry_hash;
            $verified++;
        }

        return new ChainVerification(
            intact: $defects === [],
            eventsVerified: $verified,
            defects: $defects,
        );
    }

    /**
     * Require an event to be a genuine member of the chain before any
     * processing: hash recomputes, and the signature verifies against
     * the originating leg's registered key.
     */
    public function assertAuthentic(CrossSystemEvent $event): void
    {
        $recomputed = hash('sha256', $event->hashablePayload());

        if ($recomputed !== $event->entry_hash) {
            throw new ChainIntegrityException(
                'EVENT CHAIN: the event content does not match its hash; refusing to process.'
            );
        }

        $signer = EventSigner::query()
            ->where('source', $event->source->value)
            ->where('status', 'active')
            ->first();

        $publicKey = $signer !== null ? base64_decode($signer->public_key, true) : null;
        $rawSignature = base64_decode($event->signature, true);

        if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($rawSignature) || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($rawSignature, $event->entry_hash, $publicKey)
        ) {
            throw new ChainIntegrityException(
                "EVENT CHAIN: signature does not verify against {$event->source->value}'s "
                .'registered key; a forged event is refused before any processing.'
            );
        }
    }

    /**
     * Canonical JSON: sorted keys, no whitespace — the same bytes hash
     * the same way on both legs.
     *
     * @param array<string, mixed> $payload
     */
    public function canonicalize(array $payload): string
    {
        $this->ksortDeep($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<array-key, mixed> $value */
    private function ksortDeep(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortDeep($item);
            }
        }
    }
}
