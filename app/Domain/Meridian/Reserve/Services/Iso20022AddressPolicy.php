<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-8.3 / DOCUMENT 8.3 §3 — the membrane's ISO 20022 posture for
 * reserve settlement against traditional rails. © Maher
 *
 * RECEIVE-TOLERANT: inbound hybrid addresses from compliant-but-still-
 * hybrid counterparties are accepted and normalized into the structured
 * shape as best as their content allows.
 *
 * SEND-STRICT: outbound messages carry ONLY fully structured party
 * addresses (the November 2026 SWIFT rule). A reserve adapter that
 * cannot produce a fully structured address DOES NOT SEND — the
 * membrane refuses the egress rather than emit a non-conforming
 * message.
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Reserve\Services;

use App\Domain\Meridian\Reserve\Data\StructuredPostalAddress;

final class Iso20022AddressPolicy
{
    /**
     * Receive-tolerant: accept an inbound party address that may be
     * hybrid (structured fields mixed with free-text address lines) and
     * normalize it. Missing structure is tolerated on INGRESS only.
     *
     * @param array{
     *     street_name?: string,
     *     building_number?: string,
     *     post_code?: string,
     *     town_name?: string,
     *     country?: string,
     *     address_lines?: list<string>,
     * } $inbound
     * @return array{
     *     street_name: string,
     *     building_number: string,
     *     post_code: string,
     *     town_name: string,
     *     country: string,
     *     normalization_notes: list<string>,
     * }
     */
    public function normalizeInbound(array $inbound): array
    {
        $notes = [];

        $street = trim($inbound['street_name'] ?? '');
        $building = trim($inbound['building_number'] ?? '');
        $postCode = trim($inbound['post_code'] ?? '');
        $town = trim($inbound['town_name'] ?? '');
        $country = strtoupper(trim($inbound['country'] ?? ''));

        $lines = array_values(array_filter(
            array_map(trim(...), $inbound['address_lines'] ?? []),
            static fn (string $line): bool => $line !== '',
        ));

        // Hybrid tolerance: if structured fields are missing, harvest
        // what the free-text lines contain rather than reject the
        // compliant-but-still-hybrid counterparty.
        if ($street === '' && $lines !== []) {
            $street = $lines[0];
            $notes[] = 'street_name normalized from free-text address line 1';
        }

        if ($town === '' && count($lines) >= 2) {
            $town = $lines[1];
            $notes[] = 'town_name normalized from free-text address line 2';
        }

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $notes[] = 'country missing or non-ISO; retained as-is for manual review';
        }

        return [
            'street_name' => $street,
            'building_number' => $building,
            'post_code' => $postCode,
            'town_name' => $town,
            'country' => $country,
            'normalization_notes' => $notes,
        ];
    }

    /**
     * Send-strict: the ONLY way out. The parameter type is the proof —
     * an unstructured or hybrid address cannot even be represented here,
     * so a non-conforming egress is unbuildable, not merely policed.
     *
     * @return array{
     *     StrtNm: string,
     *     BldgNb: string,
     *     PstCd: string,
     *     TwnNm: string,
     *     Ctry: string,
     * }
     */
    public function buildOutbound(StructuredPostalAddress $address): array
    {
        return [
            'StrtNm' => $address->streetName,
            'BldgNb' => $address->buildingNumber,
            'PstCd' => $address->postCode,
            'TwnNm' => $address->townName,
            'Ctry' => $address->country,
        ];
    }

    /**
     * The egress gate for callers holding raw data: attempt to assemble
     * a structured address; if the data cannot make one, REFUSE the send.
     *
     * @param array{
     *     street_name?: string,
     *     building_number?: string,
     *     post_code?: string,
     *     town_name?: string,
     *     country?: string,
     * } $raw
     */
    public function assertSendable(array $raw): StructuredPostalAddress
    {
        try {
            return new StructuredPostalAddress(
                streetName: trim($raw['street_name'] ?? ''),
                buildingNumber: trim($raw['building_number'] ?? ''),
                postCode: trim($raw['post_code'] ?? ''),
                townName: trim($raw['town_name'] ?? ''),
                country: strtoupper(trim($raw['country'] ?? '')),
            );
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException(
                'SEND-STRICT: the membrane refuses this egress — the party address is not fully structured '
                .'(November 2026 SWIFT rule). Reason: '.$e->getMessage()
            );
        }
    }
}
