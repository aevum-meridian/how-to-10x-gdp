<?php

/**
 * SPDX-License-Identifier: LicenseRef-AVL-2.0
 *
 * DOCUMENT 10.3 — USER DOCUMENTATION & ETHICAL-DASHBOARD GUIDE.
 *
 * The public ethical dashboard: plain language, accessible
 * (WCAG-conformant markup), honest about maturity everywhere. It is a
 * READ of the same sources the API serves — the Maturity Ledger, the
 * Trade-off Register, the currency registry, the transparency log and
 * the incident clock — so the human surface can never say something the
 * machine surface does not.
 *
 * The special responsibility DOCUMENT 10.3 flags: sovereignty must not
 * become an inattention-trap. The dashboard therefore leads with what
 * the system CANNOT do to the user (the non-punishment spine) and the
 * honest scope of every currency, before anything promotional — and
 * there is nothing promotional.
 *
 * © Maher
 */

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Joint\Maturity\Enums\MaturityLabel;
use App\Domain\Joint\Maturity\MaturityLedger;
use App\Domain\Joint\Maturity\TradeOffRegister;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $entries = MaturityLedger::all();

        $counts = [
            'shipped' => 0,
            'in_development' => 0,
            'research' => 0,
            'deprecated_removed' => 0,
        ];
        foreach ($entries as $entry) {
            $counts[$entry->label->value]++;
        }

        /** @var list<object{code: string, name: string, family: string}> $currencies */
        $currencies = DB::table('currencies')
            ->orderBy('code')
            ->get(['code', 'name', 'family'])
            ->all();

        $eventCount = (int) DB::table('cross_system_events')->count();
        $openIncidents = (int) DB::table('incidents')->whereNull('closed_at')->count();

        return view('dashboard', [
            'entries' => $entries,
            'counts' => $counts,
            'tradeOffs' => TradeOffRegister::all(),
            'currencies' => $currencies,
            'eventCount' => $eventCount,
            'openIncidents' => $openIncidents,
            'labels' => MaturityLabel::cases(),
        ]);
    }
}
