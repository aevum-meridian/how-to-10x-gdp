<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0 AND LicenseRef-AVL-2.0
 *
 * PersonhoodBoundaryTest — the canonical I9 test (DOCUMENT 0.1 §XREF,
 * DOCUMENT 6.1 §5, DEV-6.1 acceptance test).
 *
 * Asserts BOTH:
 *  (1) the compile-time boundary: no value/minting/rights/aggregation
 *      module (App\Domain\Meridian\*, App\Domain\Aevum\*,
 *      App\Domain\Identity\Aggregation\*) can reference the AuthFraudSignal
 *      type — the PHP analog of "the dependency fails to compile across
 *      the boundary"; and
 *  (2) the runtime guarantee: no GAS cross-context score reaches any value
 *      decision — personhood aggregation over identical attestations is
 *      invariant under any fraud-risk score, and the adapter's attestation
 *      surface carries no risk-signal field.
 *
 * © Maher
 */

declare(strict_types=1);

use App\Domain\Identity\Aggregation\PersonhoodAggregator;
use App\Domain\Identity\Contracts\PersonhoodProvider;
use App\Domain\Identity\Data\PersonhoodAttestation;
use App\Domain\Identity\Enums\AssuranceRung;
use App\Domain\Identity\Gas\GasIdentityProvider;

/**
 * @return list<string> every PHP file under the given app-relative paths
 */
function walledModuleFiles(): array
{
    $roots = [
        app_path('Domain/Meridian'),
        app_path('Domain/Aevum'),
        app_path('Domain/Identity/Aggregation'),
    ];

    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

function makeSignedGasAttestation(string $secretKey, AssuranceRung $rung = AssuranceRung::Rung3): PersonhoodAttestation
{
    $attestation = new PersonhoodAttestation(
        providerId: GasIdentityProvider::PROVIDER_ID,
        subjectCommitment: 'zk:'.bin2hex(random_bytes(16)),
        assuranceRung: $rung,
        faFrProfileRef: 'fafr-2026-q2',
        nonce: bin2hex(random_bytes(16)),
        expiresAt: new DateTimeImmutable('+1 hour'),
        signature: '',
        slashingBondRef: 'bond:gas:1',
    );

    $signature = base64_encode(sodium_crypto_sign_detached($attestation->signablePayload(), $secretKey));

    return new PersonhoodAttestation(
        providerId: $attestation->providerId,
        subjectCommitment: $attestation->subjectCommitment,
        assuranceRung: $attestation->assuranceRung,
        faFrProfileRef: $attestation->faFrProfileRef,
        nonce: $attestation->nonce,
        expiresAt: $attestation->expiresAt,
        signature: $signature,
        slashingBondRef: $attestation->slashingBondRef,
    );
}

describe('PersonhoodBoundaryTest (I9) — compile-time wall', function (): void {
    test('no value, minting, rights, or personhood-aggregation module references AuthFraudSignal or its namespace', function (): void {
        $violations = [];

        foreach (walledModuleFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, 'AuthFraud')) {
                $violations[] = $path;
            }
        }

        expect($violations)->toBe(
            [],
            'I9 VIOLATION: the following value/minting/rights modules reference the '
            .'auth-fraud type across the anti-correlation wall: '.implode(', ', $violations)
        );
    });

    test('the PersonhoodProvider contract exposes no cross-context risk-signal surface', function (): void {
        $reflection = new ReflectionClass(PersonhoodProvider::class);
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );

        sort($methods);

        expect($methods)->toBe(['fetchAttestation', 'isRevoked', 'providerId', 'verifyAttestation']);

        foreach ($reflection->getMethods() as $method) {
            $returnType = $method->getReturnType();
            expect((string) $returnType)->not->toContain('AuthFraud');
        }
    });
});

describe('PersonhoodBoundaryTest (I9) — runtime wall', function (): void {
    test('personhood aggregation over identical attestations is invariant under any fraud-risk score', function (): void {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $makeProvider = static fn (): GasIdentityProvider => new GasIdentityProvider(
            gasEd25519PublicKey: $publicKey,
            attestationFetcher: static fn (string $subject): array => throw new RuntimeException('unused'),
            revocationChecker: static fn (PersonhoodAttestation $a): bool => false,
        );

        $attestation = makeSignedGasAttestation($secretKey);

        // Simulate GAS reporting wildly different fraud-risk scores for the
        // same subject; the determination must be byte-identical.
        $determinations = [];

        foreach ([0.0, 0.5, 0.99] as $score) {
            $provider = $makeProvider();

            // The signal is ingested at the auth layer...
            $signal = $provider->ingestCrossRpSignal($attestation->subjectCommitment, [
                'signal_kind' => 'cross_rp_reputation',
                'fraud_risk_score' => $score,
                'observed_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ]);

            // ...and can gate authentication only:
            expect($signal->requiresStepUp(0.7))->toBe($score >= 0.7);

            // The aggregator has no parameter, field, or channel that could
            // receive the signal; the determination depends only on the
            // attestations.
            $aggregator = new PersonhoodAggregator([GasIdentityProvider::PROVIDER_ID => $provider]);
            $determinations[] = $aggregator->determine([$attestation]);
        }

        expect($determinations[0]->effectiveRung)->toBe($determinations[1]->effectiveRung)
            ->and($determinations[1]->effectiveRung)->toBe($determinations[2]->effectiveRung)
            ->and($determinations[0]->explanation)->toBe($determinations[1]->explanation)
            ->and($determinations[1]->explanation)->toBe($determinations[2]->explanation);
    });

    test('the attestation payload carries no PII and no risk score (I8 + I9)', function (): void {
        $reflection = new ReflectionClass(PersonhoodAttestation::class);
        $propertyNames = array_map(
            static fn (ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(),
        );

        foreach (['fraudRiskScore', 'riskScore', 'reputationScore', 'email', 'phone', 'name', 'biometric'] as $forbidden) {
            expect($propertyNames)->not->toContain($forbidden);
        }
    });

    test('the adapter refuses PII-shaped subject identifiers (I8, fails closed)', function (): void {
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $provider = new GasIdentityProvider(
            gasEd25519PublicKey: $publicKey,
            attestationFetcher: static fn (string $subject): array => throw new RuntimeException('must not be reached'),
            revocationChecker: static fn (PersonhoodAttestation $a): bool => false,
        );

        expect(static fn (): PersonhoodAttestation => $provider->fetchAttestation('amina@example.com'))
            ->toThrow(InvalidArgumentException::class)
            ->and(static fn (): PersonhoodAttestation => $provider->fetchAttestation('+1 555 123 4567'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('a forged or expired attestation fails independent verification (I9)', function (): void {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $provider = new GasIdentityProvider(
            gasEd25519PublicKey: $publicKey,
            attestationFetcher: static fn (string $subject): array => throw new RuntimeException('unused'),
            revocationChecker: static fn (PersonhoodAttestation $a): bool => false,
        );

        $valid = makeSignedGasAttestation($secretKey);
        expect($provider->verifyAttestation($valid))->toBeTrue();

        // Forged: signed by a different key.
        $rogueKeyPair = sodium_crypto_sign_keypair();
        $forged = makeSignedGasAttestation(sodium_crypto_sign_secretkey($rogueKeyPair));
        expect($provider->verifyAttestation($forged))->toBeFalse();

        // Expired.
        $expired = new PersonhoodAttestation(
            providerId: $valid->providerId,
            subjectCommitment: $valid->subjectCommitment,
            assuranceRung: $valid->assuranceRung,
            faFrProfileRef: $valid->faFrProfileRef,
            nonce: $valid->nonce,
            expiresAt: new DateTimeImmutable('-1 minute'),
            signature: $valid->signature,
            slashingBondRef: $valid->slashingBondRef,
        );
        expect($provider->verifyAttestation($expired))->toBeFalse();
    });

    test('no single provider is dispositive for a constitutional-grade (Rung 3) determination', function (): void {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $provider = new GasIdentityProvider(
            gasEd25519PublicKey: $publicKey,
            attestationFetcher: static fn (string $subject): array => throw new RuntimeException('unused'),
            revocationChecker: static fn (PersonhoodAttestation $a): bool => false,
        );

        $aggregator = new PersonhoodAggregator([GasIdentityProvider::PROVIDER_ID => $provider]);
        $determination = $aggregator->determine([makeSignedGasAttestation($secretKey, AssuranceRung::Rung3)]);

        expect($determination->effectiveRung)->not->toBe(AssuranceRung::Rung3)
            ->and($determination->explanation)->toContain('independent providers')
            ->and($determination->appealable)->toBeTrue();
    });
});
