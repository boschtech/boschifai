<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sandbox paths
    |--------------------------------------------------------------------------
    |
    | Every GitHub-connected RepoConfig gets one persistent checkout under here (see
    | RepoCheckoutManager) — outside storage/app so it's never web-served and never picked up by
    | Laravel's own file scanning. A local-connected RepoConfig doesn't use this at all; it's
    | worked on directly at its own git_remote_path.
    */
    'var_path' => env('BOSCHIFAI_VAR_PATH', base_path('var/boschifai')),

    /*
    |--------------------------------------------------------------------------
    | Local repository connections
    |--------------------------------------------------------------------------
    |
    | "Connect Repo"'s local-filesystem section lets a user pick a repo already checked out on
    | disk instead of going through GitHub OAuth. `root_path` is the ONE directory the browse
    | endpoint is allowed to look inside (and below) — every browsed/connected path is resolved
    | with realpath() and rejected if it escapes this root, since this app has no authentication
    | (see README) and a raw "list any directory" endpoint would otherwise be a real filesystem
    | disclosure risk to anyone who can reach it. When running via docker-compose, this must be
    | a container-internal path with a matching bind mount (docker-compose.yml mounts
    | BOSCHIFAI_LOCAL_REPOS_ROOT from the host to this path) — RepoCheckoutManager and
    | LocalTestRunner both operate directly inside the `worker` container's own filesystem view,
    | so no host-path translation is needed anywhere, only this one bind mount.
    */
    'local_repos' => [
        'root_path' => env('BOSCHIFAI_LOCAL_REPOS_ROOT_PATH', '/host-repos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local test execution (test-runner sidecar)
    |--------------------------------------------------------------------------
    |
    | LocalTestRunner doesn't run a connected repo's own composer/test command itself anymore —
    | it writes a job file here and polls for the sidecar's result. See docker-compose.yml's
    | `test-runner` service and docker/test-runner/poll.php for why this lives in a separate,
    | credential-less container rather than running in `worker` directly (a real incident: a
    | connected repo's own test suite inherited `worker`'s live DB credentials and wiped
    | Boschifai's database via `migrate:fresh`).
    */
    'test_runner' => [
        'jobs_path' => env('BOSCHIFAI_TEST_RUNNER_JOBS_PATH', '/var/boschifai-jobs'),
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
            'fix_failing_tests' => 900, // scoped to one already-known file, not a full regeneration
            // Only reached when github_mcp.pat is configured (PushAndOpenPrJob's MCP-based
            // path) — a handful of MCP tool calls against an already-prepared local commit, not
            // a full agentic exploration, so this stays far below code_generation's own ceiling.
            'push' => 300,
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
    | GitHub MCP server (docker/app/mcp-config.json)
    |--------------------------------------------------------------------------
    |
    | Optional, opt-in: PushAndOpenPrJob only uses the MCP-based push path (a constrained,
    | code-supervised Claude invocation — see that job) when a PAT is actually configured here;
    | otherwise it falls back to the original deterministic git-push + REST API flow unchanged.
    | Deliberately a single static PAT, not this org's existing per-repo GithubOAuthConnection
    | flow — github-mcp-server authenticates as whatever account owns this token for every
    | connected repo, so that account needs push + PR permissions on all of them. Never commit a
    | real value: set GITHUB_MCP_PAT in your own untracked .env (see .env.example).
    |
    | TODO: source from AWS SSM Parameter Store at runtime in a real deployment, same as
    | ANTHROPIC_API_KEY above and rams/CLAUDE.md's own documented secrets pattern — a plain .env
    | value here is for local dev only.
    */
    'github_mcp' => [
        'pat' => env('GITHUB_MCP_PAT'),
        'config_path' => '/opt/boschifai/mcp-config.json',
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
