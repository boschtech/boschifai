<?php

/*
| Prepaid credits. 1 credit = US$1; every amount is stored as integer micro-dollars
| (1 USD = 1 000 000) so nothing is ever rounded through a float.
|
| Every Claude step is priced and recorded (invocation_charges). Credits only move when
| `enforce` is on; until then this is shadow mode — nothing is held, charged or blocked.
|
| The figures below are business decisions for finance, not engineering defaults — none are
| invented here. Until they're set, steps are priced at cost so real numbers can be calibrated
| from `php artisan boschifai:billing-report`.
*/
return [

    // Off (the default): shadow mode, as described above. On: before each Claude step the
    // step's hold is reserved from the workspace's credits; a run that can't cover it waits in
    // `awaiting_credits` (resumable) instead of running, and requests that would start a step
    // get HTTP 402. Switch on only once every step's hold is set (see stage_holds_usd) and the
    // workspaces have credits — a step with no hold still runs, uncharged, and is reported.
    'enforce' => (bool) env('BILLING_ENFORCE', false),

    // Multiplier on the provider's cost, e.g. 1.5 charges US$1.50 for US$1.00 of API usage.
    // Unset = charged at cost (1.0).
    'markup' => env('BILLING_MARKUP'),

    // The least a successful step costs, in USD. Unset = no minimum.
    'min_charge_usd' => env('BILLING_MIN_CHARGE_USD'),

    // Credits reserved before each step runs, in USD — the most that step can ever be charged
    // (anything above it is absorbed by the platform). Calibrate from the report's p95 column.
    // Unset = no hold and no cap for that step.
    'stage_holds_usd' => [
        'gap_analysis' => env('BILLING_HOLD_GAP_ANALYSIS_USD'),
        'test_plan' => env('BILLING_HOLD_TEST_PLAN_USD'),
        'test_case_generation' => env('BILLING_HOLD_TEST_CASE_GENERATION_USD'),
        'code_generation' => env('BILLING_HOLD_CODE_GENERATION_USD'),
        'fix_failing_tests' => env('BILLING_HOLD_FIX_FAILING_TESTS_USD'),
        'push' => env('BILLING_HOLD_PUSH_USD'),
        'build_skills' => env('BILLING_HOLD_BUILD_SKILLS_USD'),
        'build_knowledge_base' => env('BILLING_HOLD_BUILD_KNOWLEDGE_BASE_USD'),
    ],

];
