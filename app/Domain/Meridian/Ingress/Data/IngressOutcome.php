<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-7.1 — what the single ingress returns: committed (with the
 * posted transaction id) or rejected (with the reason), plus the
 * confirmation event that carries the outcome back to Aevum. Aevum
 * updates its VIEW on confirmation; it never held the authoritative
 * balance. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Ingress\Data;

use App\Domain\Joint\EventContract\Models\CrossSystemEvent;

final readonly class IngressOutcome
{
    public function __construct(
        public bool $committed,
        public ?string $transactionId,
        public ?string $rejectionReason,
        public CrossSystemEvent $confirmation,
    ) {
    }
}
