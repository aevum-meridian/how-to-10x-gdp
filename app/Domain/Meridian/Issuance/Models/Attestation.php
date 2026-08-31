<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — a PoVC quorum attestation. subject_proof is a ZK commitment
 * or hashed personhood ref, NEVER raw PII (I8). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_id
 * @property string $recipient_account_id
 * @property string $subject_proof
 * @property int $amount_minor
 * @property string $nonce
 * @property \Illuminate\Support\Carbon $expires_at
 * @property list<string> $attester_set
 * @property list<array{verifier_id: string, signature: string}> $signatures
 * @property bool $quorum_met
 * @property string|null $minted_transaction_id
 * @property string $status
 */
final class Attestation extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'attestations';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attester_set' => 'array',
            'signatures' => 'array',
            'quorum_met' => 'bool',
        ];
    }

    /**
     * The canonical byte string every verifier signs: binds the subject
     * proof, recipient, currency, amount, nonce and expiry so a signature
     * cannot be transplanted onto a different mint.
     */
    public function signablePayload(): string
    {
        return implode('|', [
            'povc-attestation-v1',
            $this->currency_id,
            $this->recipient_account_id,
            $this->subject_proof,
            (string) $this->amount_minor,
            $this->nonce,
            $this->expires_at->toIso8601String(),
        ]);
    }
}
