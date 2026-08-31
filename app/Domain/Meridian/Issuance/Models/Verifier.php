<?php

/**
 * SPDX-License-Identifier: LicenseRef-MVL-2.0
 * DEV-4.2 — a registered PoVC attester. rotation_group encodes
 * independence for the I4 quorum count. © Maher
 */

declare(strict_types=1);

namespace App\Domain\Meridian\Issuance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $pubkey
 * @property string $family_scope
 * @property string $status
 * @property string $rotation_group
 * @property int $bond
 */
final class Verifier extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'verifiers';

    protected $guarded = [];
}
