<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sandbox paths
    |--------------------------------------------------------------------------
    |
    | Mirror clones and per-run worktrees live outside storage/app so they're
    | never web-served and never picked up by Laravel's own file scanning.
    */
    'var_path' => env('BOSCHIFAI_VAR_PATH', base_path('var/boschifai')),

    /*
    |--------------------------------------------------------------------------
    | Docker-outside-of-Docker (DooD) path translation
    |--------------------------------------------------------------------------
    |
    | DockerTestRunner spins up a sibling `rams-app` container via the host's Docker socket
    | (bind-mounted into this app's own container — see docker-compose.yml). A command issued
    | over that socket is scheduled by the HOST daemon, so any `-v <path>:...` it contains must
    | be a HOST filesystem path — the container-internal path this app itself sees (`var_path`
    | above, e.g. `/var/boschifai`) means nothing to the host daemon.
    |
    | When running via docker-compose, BOSCHIFAI_HOST_VAR_PATH must be set to the absolute HOST
    | path that docker-compose.yml bind-mounts to `var_path` inside the container (Compose
    | resolves `./var/boschifai` to an absolute host path itself — this just needs to be told
    | what that resolved path is). Left null for bare-host (non-Docker) runs, where the app's
    | own filesystem view already *is* the host's, so no translation is needed — this is the
    | mode already verified end-to-end against the real RAMS repo (see the plan's verification
    | notes) before Docker support existed.
    */
    'docker' => [
        'host_var_path' => env('BOSCHIFAI_HOST_VAR_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Headless Claude Code invocation
    |--------------------------------------------------------------------------
    */
    'claude' => [
        // TODO: source from AWS SSM Parameter Store at runtime, per rams/CLAUDE.md's own
        // documented secrets pattern (SSM -> Terraform Cloud -> ECS injection) — never a
        // plain .env value in a real deployment. Left as an env var here for local dev only.
        'api_key' => env('ANTHROPIC_API_KEY'),

        'model' => env('BOSCHIFAI_CLAUDE_MODEL', 'claude-sonnet-5'),

        'docker_image' => env('BOSCHIFAI_CLAUDE_RUNNER_IMAGE', 'boschifai/claude-runner:latest'),

        // Per-step wall-clock timeouts (seconds) — see plan §3 rationale per step.
        'timeouts' => [
            'gap_analysis' => 480,      // /boschifai-review
            'test_plan' => 480,         // /boschifai-test-plan
            'test_case_generation' => 900, // /boschifai-test-cases (includes its own Task-tool validator)
            // Bumped from 900s: a real run against a multi-tenant, portfolio-scoped controller
            // (requirement explicitly demanded 100% coverage + all edge cases) timed out at
            // 900s while still mid-tool-call — the transcript showed genuine, still-in-progress
            // exploration (reading Lease/Mandate/AssetGroup models, portfolio scoping services),
            // not a stuck/looping session. 1800s matches this file's own ci_poll_timeout_seconds
            // precedent for "generous ceiling for a genuinely long agentic operation."
            'code_generation' => 1800,  // /boschifai-gen-component
        ],

        'max_retries' => [
            'test_case_generation' => 2, // mirrors boschifai-crew's own two-attempt fix-loop rule
        ],

        // TODO: needs a real dollar figure from engineering/finance before going live —
        // not invented here (see plan §3).
        'max_cost_per_run_usd' => env('BOSCHIFAI_MAX_COST_PER_RUN_USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub push / PR / CI polling
    |--------------------------------------------------------------------------
    */
    'github' => [
        'ci_poll_interval_seconds' => 30,
        'ci_poll_timeout_seconds' => 1800, // 30 min ceiling — self-hosted + full suite + Sonar scan

        // "Connect GitHub" feature — a classic OAuth App ("Authorized OAuth Apps"), the same
        // mechanism VS Code's own "Sign in with GitHub" uses, not a GitHub App installation.
        // oauth_client_id/oauth_client_secret identify this OAuth App to GitHub — a one-time,
        // out-of-band registration by an org admin at https://github.com/settings/applications/new
        // (Homepage URL + Authorization callback URL {APP_URL}/github/callback; no separate
        // permissions UI — access is granted per-user via the `repo` scope requested in
        // GithubOAuthService::authorizeUrl()). TODO: oauth_client_secret should be sourced from
        // AWS SSM in production, never a plain .env value — same pattern as ANTHROPIC_API_KEY
        // above. Unlike a GitHub App installation token, the resulting per-user access tokens
        // are long-lived and are stored (encrypted — see GithubConnection) rather than minted
        // on demand.
        'oauth_client_id' => env('BOSCHIFAI_GITHUB_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('BOSCHIFAI_GITHUB_OAUTH_CLIENT_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence score weights
    |--------------------------------------------------------------------------
    |
    | Starting proposal for the team to tune, not a validated formula — see plan §7.
    */
    'confidence_weights' => [
        'testability' => 25,
        'test_case_quality' => 20,
        'local_execution' => 25,
        'ci' => 30,
    ],
];
