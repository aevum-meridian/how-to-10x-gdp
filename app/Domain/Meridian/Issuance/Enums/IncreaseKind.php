<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 *
 * DEV-4.2 — Core-Riba flag 2 of 5: the character of the increase
 * (DOCUMENT 2.1 §6: only prefixed_guaranteed can complete the forbidden
 * form; PLS/rent/service_fee/staking_reward/demurrage are the permitted
 * generators, each lacking at least one Core-Riba element). © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Enums;

enum IncreaseKind: string
{
    case None = 'none';
    case PrefixedGuaranteed = 'prefixed_guaranteed';
    case ProfitAndLossShare = 'profit_and_loss_share';
    case Rent = 'rent';
    case ServiceFee = 'service_fee';
    case StakingReward = 'staking_reward';
    case Demurrage = 'demurrage';
}
