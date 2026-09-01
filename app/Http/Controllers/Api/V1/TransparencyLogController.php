<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * GET /api/v1/transparency-log — DOCUMENT 10.1: "the transparency log
 * (public, verifiable)."
 *
 * The hash-chained cross-system event log, paged, with everything a
 * third party needs to verify the chain independently: sequence, hashes,
 * signatures, and the registered public keys. Payloads are the events'
 * OWN content (proposals and confirmations carry account ids, never PII
 * — the ledger schema is PII-free by I8/6.5 design).
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TransparencyLogController extends Controller
{
    private const PAGE_SIZE = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $afterSeq = max(0, (int) $request->query('after_seq', '0'));

        /** @var list<object{seq: int, id: string, source: string, kind: string, prev_hash: string, entry_hash: string, signature: string, status: string, created_at: string}> $events */
        $events = DB::table('cross_system_events')
            ->where('seq', '>', $afterSeq)
            ->orderBy('seq')
            ->limit(self::PAGE_SIZE)
            ->get(['seq', 'id', 'source', 'kind', 'prev_hash', 'entry_hash', 'signature', 'status', 'created_at'])
            ->all();

        /** @var list<object{source: string, public_key: string, status: string}> $signers */
        $signers = DB::table('event_signers')
            ->get(['source', 'public_key', 'status'])
            ->all();

        return response()->json([
            'log' => 'the hash-chained cross-system event contract (DOCUMENT 7.1); each entry_hash commits to the canonical payload and prev_hash, signed by the originating leg',
            'verification' => 'recompute each entry hash from its canonical payload + prev_hash and verify the Ed25519 signature against the registered signer keys below; a broken link is drift, and drift is alerted, never auto-corrected (DOCUMENT 7.2)',
            'signers' => array_map(static fn (object $signer): array => [
                'source' => $signer->source,
                'public_key' => $signer->public_key,
                'status' => $signer->status,
            ], $signers),
            'after_seq' => $afterSeq,
            'page_size' => self::PAGE_SIZE,
            'events' => array_map(static fn (object $event): array => [
                'seq' => $event->seq,
                'id' => $event->id,
                'source' => $event->source,
                'kind' => $event->kind,
                'prev_hash' => $event->prev_hash,
                'entry_hash' => $event->entry_hash,
                'signature' => $event->signature,
                'status' => $event->status,
                'created_at' => $event->created_at,
            ], $events),
        ]);
    }
}
