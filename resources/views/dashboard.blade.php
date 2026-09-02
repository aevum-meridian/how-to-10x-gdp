{{--
    SPDX-License-Identifier: LicenseRef-AVL-2.0

    DOCUMENT 10.3 — the ethical dashboard. Plain language, WCAG-conformant
    markup (landmarks, one h1, labelled tables, honest link text, visible
    focus, prefers-reduced-motion respected, no color-only meaning),
    honest maturity labels on every capability, honest scope statements
    on every currency. Nothing promotional. © Maher
--}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="The Meridian × Aevum ethical dashboard: what this system is, what it cannot do to you, and how mature each part honestly is.">
    <title>Ethical Dashboard — Meridian × Aevum</title>
    <style>
        :root {
            --ink: #1a1a1a; --paper: #fbfaf7; --line: #d9d4c7;
            --accent: #14532d; --warn: #7c2d12; --muted: #57534e;
            --card: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #e7e5e4; --paper: #191817; --line: #3f3c38;
                --accent: #86efac; --warn: #fdba74; --muted: #a8a29e;
                --card: #232120;
            }
        }
        * { box-sizing: border-box; }
        html { font-size: 100%; }
        body {
            margin: 0; color: var(--ink); background: var(--paper);
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 1.65; font-size: 1.0625rem;
        }
        a { color: var(--accent); }
        a:focus-visible, summary:focus-visible {
            outline: 3px solid var(--accent); outline-offset: 2px;
        }
        .skip-link {
            position: absolute; left: -9999px; top: 0; background: var(--card);
            padding: .5rem 1rem; z-index: 10;
        }
        .skip-link:focus { left: 0; }
        header, main, footer { max-width: 60rem; margin: 0 auto; padding: 0 1.25rem; }
        header { padding-top: 2.5rem; }
        h1 { font-size: 1.9rem; line-height: 1.25; margin: 0 0 .25rem; }
        h2 { font-size: 1.35rem; margin: 2.5rem 0 .5rem; border-bottom: 1px solid var(--line); padding-bottom: .3rem; }
        h3 { font-size: 1.1rem; margin: 1.25rem 0 .25rem; }
        .subtitle { color: var(--muted); margin: 0 0 1rem; }
        .banner {
            border: 2px solid var(--warn); border-radius: .5rem;
            padding: 1rem 1.25rem; margin: 1.5rem 0; background: var(--card);
        }
        .banner strong { color: var(--warn); }
        .spine {
            border: 2px solid var(--accent); border-radius: .5rem;
            padding: 1rem 1.25rem; margin: 1.5rem 0; background: var(--card);
        }
        table { border-collapse: collapse; width: 100%; margin: .75rem 0 1.5rem; background: var(--card); }
        caption { text-align: left; font-style: italic; color: var(--muted); padding: .35rem 0; }
        th, td { border: 1px solid var(--line); padding: .5rem .65rem; text-align: left; vertical-align: top; font-size: .95rem; }
        th { background: transparent; }
        .label {
            display: inline-block; border: 1.5px solid currentColor; border-radius: 999px;
            padding: 0 .6rem; font-size: .8rem; font-family: ui-monospace, monospace;
            white-space: nowrap;
        }
        .label::before { font-weight: bold; }
        .label--in_development { color: #92400e; } .label--in_development::before { content: "◐ "; }
        .label--research { color: #7c3aed; } .label--research::before { content: "◯ "; }
        .label--deprecated_removed { color: var(--muted); } .label--deprecated_removed::before { content: "✕ "; }
        .label--shipped { color: var(--accent); } .label--shipped::before { content: "● "; }
        @media (prefers-color-scheme: dark) {
            .label--in_development { color: #fcd34d; }
            .label--research { color: #c4b5fd; }
        }
        details { margin: .5rem 0; }
        summary { cursor: pointer; font-weight: bold; }
        dl.stats { display: flex; flex-wrap: wrap; gap: 1.5rem; margin: 1rem 0; }
        dl.stats > div { background: var(--card); border: 1px solid var(--line); border-radius: .5rem; padding: .75rem 1.1rem; }
        dl.stats dt { font-size: .85rem; color: var(--muted); margin: 0; }
        dl.stats dd { font-size: 1.4rem; margin: 0; font-variant-numeric: tabular-nums; }
        footer { border-top: 1px solid var(--line); margin-top: 3rem; padding-top: 1rem; padding-bottom: 2.5rem; color: var(--muted); font-size: .9rem; }
        @media (max-width: 40rem) {
            body { font-size: 1rem; }
            th, td { font-size: .875rem; padding: .4rem .45rem; }
            .table-scroll { overflow-x: auto; }
        }
        .table-scroll { overflow-x: auto; }
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<header>
    <h1>The Ethical Dashboard</h1>
    <p class="subtitle">Meridian (the record) × Aevum (the engagement) — what this system is, what it cannot do to you, and how mature each part honestly is.</p>

    <div class="banner" role="note" aria-label="Honest maturity statement">
        <strong>Nothing here is finished.</strong>
        Every capability below carries an honest maturity label. Right now:
        <strong>{{ $counts['shipped'] }}</strong> shipped,
        <strong>{{ $counts['in_development'] }}</strong> in development,
        <strong>{{ $counts['research'] }}</strong> still open research problems, and
        <strong>{{ $counts['deprecated_removed'] }}</strong> retired and kept in the record.
        Anything not labelled “shipped” is <em>not available</em>, no matter how
        confident any other page sounds. The machine-readable version of this
        promise lives at <a href="/api/v1/maturity">/api/v1/maturity</a>, and every
        other surface is bound to it.
    </div>
</header>

<main id="main">
    <section aria-labelledby="spine-h">
        <h2 id="spine-h">What this system can never do to you</h2>
        <div class="spine">
            <p><strong>Your contribution balance can never be taken from you as a punishment.</strong>
            No administrator, no “emergency,” no policy engine, and no crisis commander can
            debit what you earned. The only path that can ever reverse a contribution is a
            formal arbitration of a specific disputed transaction — and that path is walled
            in three independent ways: the database itself refuses the write, the service
            layer refuses the request, and a continuously-running test suite proves both
            walls hold.</p>
            <p>Three more plain promises:</p>
            <ul>
                <li><strong>The record never rewrites itself.</strong> Mistakes are corrected by
                    visible reversals, never by silent edits.</li>
                <li><strong>The ledger stores no personal data</strong> — not your name, not your
                    location, and never anything biometric or neural. The database refuses
                    such columns structurally.</li>
                <li><strong>Nothing here pays interest for idle money.</strong> Policies that would
                    are rejected by the schema itself.</li>
            </ul>
        </div>
    </section>

    <section aria-labelledby="currencies-h">
        <h2 id="currencies-h">What each currency honestly is</h2>
        <p>A currency name tells you nothing. Its <em>scope statement</em> tells you everything.
        These are the honest scopes, including the uncomfortable parts:</p>
        <ul>
            <li><strong>Contribution credits</strong> — earned by verified contribution, and they can
                never be taken from you (except by arbitration of a specific disputed
                transaction). <span class="label label--research">research</span></li>
            <li><strong>$FLUX</strong> — designed to lose value when held idle. Holding it is a losing
                trade <em>on purpose</em>; it exists to move. <span class="label label--research">research</span></li>
            <li><strong>$PEG</strong> — a regulated, reserve-backed peg with published attestations.
                <span class="label label--in_development">in development</span></li>
            <li><strong>$PEG+</strong> — experimental. It may fail. It is named “experimental” so it
                cannot be mistaken for the regulated one. <span class="label label--research">research</span></li>
            <li><strong>$FOCUS</strong> — an EEG-based currency that was <em>retired</em> on the
                no-neural-data principle. It stays in this record so its removal is
                never forgotten. <span class="label label--deprecated_removed">removed</span></li>
        </ul>
        @if ($currencies !== [])
            <div class="table-scroll">
            <table>
                <caption>Currencies currently instantiated in this deployment's registry</caption>
                <thead>
                    <tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Family</th></tr>
                </thead>
                <tbody>
                    @foreach ($currencies as $currency)
                        <tr>
                            <td>{{ $currency->code }}</td>
                            <td>{{ $currency->name }}</td>
                            <td>{{ $currency->family }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p><em>No currencies are instantiated in this deployment yet.</em></p>
        @endif
    </section>

    <section aria-labelledby="maturity-h">
        <h2 id="maturity-h">The maturity ledger, in full</h2>
        <p>Every capability, with both its <em>exit criterion</em> (what would have to be true
        before we call it done) and its <em>abandonment criterion</em> (the pre-agreed
        condition under which we stop and say so). A row that cannot name the second
        is not being honest about the first.</p>
        <div class="table-scroll">
        <table>
            <caption>The Maturity &amp; Abandonment Ledger — the binding source is <a href="/api/v1/maturity">/api/v1/maturity</a></caption>
            <thead>
                <tr>
                    <th scope="col">Capability</th>
                    <th scope="col">Label</th>
                    <th scope="col">Done when…</th>
                    <th scope="col">Abandoned if…</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <th scope="row">{{ $entry->subsystem }}</th>
                        <td><span class="label label--{{ $entry->label->value }}">{{ str_replace('_', ' ', $entry->label->value) }}</span></td>
                        <td>{{ $entry->exitCriterion }}</td>
                        <td>{{ $entry->abandonmentCriterion }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </section>

    <section aria-labelledby="tradeoffs-h">
        <h2 id="tradeoffs-h">Every trade-off, with its cost</h2>
        <p>Design decisions have costs. Hiding the cost is how systems lie.
        Each entry below names what was chosen <em>and what it costs you</em>:</p>
        @foreach ($tradeOffs as $tradeOff)
            <details>
                <summary>{{ $tradeOff->axis }}</summary>
                <p><strong>Chosen:</strong> {{ $tradeOff->chosen }}</p>
                <p><strong>The honest cost:</strong> {{ $tradeOff->cost }}</p>
                <p><small>Source: {{ $tradeOff->specSource }}</small></p>
            </details>
        @endforeach
    </section>

    <section aria-labelledby="verify-h">
        <h2 id="verify-h">Check it yourself</h2>
        <dl class="stats">
            <div>
                <dt>Events in the public transparency log</dt>
                <dd>{{ number_format($eventCount) }}</dd>
            </div>
            <div>
                <dt>Open incidents on the disclosure clock</dt>
                <dd>{{ number_format($openIncidents) }}</dd>
            </div>
        </dl>
        <p>You do not have to trust this page. Everything on it is served, machine-readable,
        from the same sources:</p>
        <ul>
            <li><a href="/api/v1/maturity">The maturity ledger</a> — the binding availability check</li>
            <li><a href="/api/v1/trade-off-register">The trade-off register</a> — every cost, disclosed</li>
            <li><a href="/api/v1/currencies">The currency registry</a> — with the no-interest flags visible</li>
            <li><a href="/api/v1/transparency-log">The transparency log</a> — a hash-chained, signed event record you can verify independently</li>
            <li><a href="/api/v1/incidents">The incident disclosure clock</a> — our public deadlines, publicly checkable, including when we are overdue</li>
            <li><a href="/api/v1/openapi.json">The OpenAPI document</a> — the single source of truth for the API, including the honest list of endpoints that do <em>not</em> exist yet and why</li>
        </ul>
    </section>

    <section aria-labelledby="absent-h">
        <h2 id="absent-h">What you cannot do here yet, and why</h2>
        <p>There is no sign-up, no wallet, and no transfer button on this page. That is
        deliberate, not an oversight: the identity and authorization layer is still in
        development, and offering you a money button before it is safe would be
        presenting an unfinished capability as available — the exact dishonesty this
        system's license forbids. When those surfaces arrive, they will arrive with
        their maturity labels attached.</p>
    </section>
</main>

<footer>
    <p>Meridian is licensed under <span lang="und">LicenseRef-MVL-2.0</span>; Aevum under
    <span lang="und">LicenseRef-AVL-2.0</span>. Neither is an OSI-approved open-source
    license, and this page does not claim otherwise. The specification documents in
    the repository are the authority wherever prose and software could disagree.</p>
    <p>© Maher — the ethical dashboard of DOCUMENT 10.3 (maturity: in development).</p>
</footer>
</body>
</html>
