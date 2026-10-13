<?php

/*
| Prepaid credits. 1 credit = US$1; every amount is stored as integer micro-dollars
| (1 USD = 1 000 000) so nothing is ever rounded through a float.
|
| Every Claude step is priced and recorded (invocation_charges). Credits only move when
| `enforce` is on; until then this is shadow mode — nothing is held, charged or blocked.
|
| Pricing defaults (docs/outstanding-decisions.md D4, D5) — each can be overridden in .env; an
| empty value keeps the default. Recalibrate with `php artisan boschifai:billing-report`.
*/

// A set, non-empty env value wins ("0" included); empty or missing keeps the default.
$setting = fn (string $key, string $default): string => filled(env($key)) ? (string) env($key) : $default;

return [

    // Off (the default): shadow mode, as described above. On: before each Claude step the
    // step's hold is reserved from the workspace's credits; a run that can't cover it waits in
    // `awaiting_credits` (resumable) instead of running, and requests that would start a step
    // get HTTP 402. A step with no hold still runs, uncharged, and is reported.
    'enforce' => (bool) env('BILLING_ENFORCE', false),

    // Multiplier on the provider's cost. 2.0 = a 50% gross margin on usage, in line with the
    // ~50–60% reported for AI products (vs 80–90% for traditional SaaS); infrastructure gateways
    // charge ~5% but don't sell a product on top. Bonus credits on larger packs lower the
    // effective margin (the +20% Scale pack makes 2.0× ≈ 1.67×).
    'markup' => $setting('BILLING_MARKUP', '2.0'),

    // The least a successful step costs, in USD — a small floor for per-step overhead. No
    // provider publishes one; for scale, GitHub Copilot charges US$0.04 per extra request.
    'min_charge_usd' => $setting('BILLING_MIN_CHARGE_USD', '0.02'),

    // How long purchased (and granted) credits stay usable. 36 months: the CPA (s63) doesn't allow
    // prepaid credits to expire sooner for consumers and small businesses (under R2m), and one
    // rule for everyone avoids having to test each customer's size (D6). Oldest credits are used
    // first; owners and admins are emailed reminder_days before credits expire.
    'credit_validity_months' => (int) $setting('BILLING_CREDIT_VALIDITY_MONTHS', '36'),
    'expiry_reminder_days' => [60, 14],

    // Credit packs customers can buy: "key:credits:price" in USD, comma-separated. Credits above
    // the price are a bonus. Defaults (D5) follow the common pattern for prepaid credits: a small
    // entry pack, then larger packs with 10–20% bonus credits:
    //   Starter US$10 → 10 · Team US$50 → 55 (+10%) · Business US$100 → 115 (+15%) ·
    //   Scale US$250 → 300 (+20%)
    // To stop sales, take the provider out of PAYMENTS_ENABLED.
    'packs' => collect(explode(',', $setting('BILLING_PACKS', 'starter:10:10,team:55:50,business:115:100,scale:300:250')))
        ->map(fn ($pack) => array_map('trim', explode(':', $pack)))
        ->filter(fn ($parts) => count($parts) === 3 && $parts[0] !== '')
        ->mapWithKeys(fn ($parts) => [$parts[0] => ['credits_usd' => $parts[1], 'price_usd' => $parts[2]]])
        ->all(),

    // Credits reserved before each step runs, in USD — the most that step can ever be charged
    // (anything above it is absorbed by the platform). Defaults (D4): the highest cost seen for
    // the step in usage so far × the 2.0 markup, rounded up to whole dollars — 1 to 11 samples
    // per step, so recalibrate from the billing report's p95/max columns as usage grows. Kept at
    // or below the Starter pack (US$10) so one pack can always cover the largest step.
    'stage_holds_usd' => [
        'gap_analysis' => $setting('BILLING_HOLD_GAP_ANALYSIS_USD', '2'),                 // max seen $0.68
        'test_plan' => $setting('BILLING_HOLD_TEST_PLAN_USD', '3'),                       // max seen $1.13
        'test_case_generation' => $setting('BILLING_HOLD_TEST_CASE_GENERATION_USD', '2'), // max seen $0.65
        'code_generation' => $setting('BILLING_HOLD_CODE_GENERATION_USD', '9'),           // max seen $4.33
        'fix_failing_tests' => $setting('BILLING_HOLD_FIX_FAILING_TESTS_USD', '3'),       // max seen $1.26
        'push' => $setting('BILLING_HOLD_PUSH_USD', '1'),                                 // max seen $0.24
        'build_skills' => $setting('BILLING_HOLD_BUILD_SKILLS_USD', '2'),                 // one sample, $0.48
        'build_knowledge_base' => $setting('BILLING_HOLD_BUILD_KNOWLEDGE_BASE_USD', '4'), // max seen $1.98
    ],

];
