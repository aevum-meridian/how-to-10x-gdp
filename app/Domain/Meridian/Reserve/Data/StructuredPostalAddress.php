<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-8.3 — a fully structured ISO 20022 party address. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Data;

use Spatie\LaravelData\Data;

/**
 * The November 2026 SWIFT rule's target shape: every element in its own
 * field, no free-text address lines. An instance of this class IS the
 * proof that an address is structured — the send path accepts nothing
 * else.
 */
final class StructuredPostalAddress extends Data
{
    public function __construct(
        public readonly string $streetName,
        public readonly string $buildingNumber,
        public readonly string $postCode,
        public readonly string $townName,
        public readonly string $country, // ISO 3166 alpha-2.
    ) {
        foreach ([
            'streetName' => $streetName,
            'postCode' => $postCode,
            'townName' => $townName,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException(
                    "A structured address requires a non-empty {$field}."
                );
            }
        }

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException(
                'A structured address requires an ISO 3166 alpha-2 country code.'
            );
        }
    }
}
