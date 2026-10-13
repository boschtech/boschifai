<?php

namespace App\Services\ClaudeRunner;

/**
 * Locks down the `claude` process the pipeline starts inside a repo checkout. That process
 * runs an AI agent over code we don't control, so two things must hold for every invocation:
 *
 * 1. **It sees no secrets it wasn't given.** Symfony Process merges the worker's whole
 *    environment into the child by default (Process::getDefaultEnv() — $_SERVER, $_ENV and
 *    getenv(), which include everything Laravel loaded from .env), so the agent would see
 *    DB_PASSWORD, APP_KEY and any other key on the worker. environment() passes an allowlist
 *    plus the credentials for this invocation, and explicitly removes everything else (Symfony
 *    removes a variable only when it's set to `false`).
 *
 * 2. **The repo can't reconfigure the CLI.** Verified against Claude Code 2.1.296 with a
 *    hostile checkout: a committed `.claude/settings.json` (or `settings.local.json`) could
 *    point ANTHROPIC_BASE_URL or a proxy at an attacker (sending them our API key), run hooks
 *    and an `apiKeyHelper` command, and a `.mcp.json` could start its own MCP server.
 *    cliArguments() blocks all of that:
 *    - `--setting-sources user,project` drops `settings.local.json`. `project` must stay —
 *      without it the pipeline's own .claude/commands and skills don't load either.
 *    - `--settings` (higher precedence than project settings) pins the API endpoint and proxy
 *      variables, disables hooks and blanks `apiKeyHelper`.
 *    - `--strict-mcp-config` ignores the repo's MCP servers; only ours (--mcp-config) load.
 *
 * Not covered here: the agent can still read files the worker can read (e.g. a mounted
 * `.env`) through its own tools. Closing that needs the runner isolated from app secrets —
 * see the Phase 0 notes in the plan; `restricted_mode` below is a partial, opt-in measure.
 */
class ClaudeProcessHardening
{
    /**
     * Variables the CLI and the tools it runs legitimately need. Proxy and CA settings are
     * included so a corporate network setup keeps working — they come from the worker's own
     * environment, never from the repo (see cliArguments()).
     */
    private const BASE_ALLOWLIST = [
        'PATH', 'HOME', 'USER', 'SHELL', 'TERM', 'TZ', 'TMPDIR',
        'LANG', 'LC_ALL', 'LC_CTYPE',
        'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY', 'https_proxy', 'http_proxy', 'no_proxy',
        'NODE_EXTRA_CA_CERTS', 'SSL_CERT_FILE',
    ];

    /** Pinned over anything a repo's settings files set (see class docblock). */
    private const PINNED_SETTINGS = [
        'disableAllHooks' => true,
        'apiKeyHelper' => '',
    ];

    /**
     * @param  array<string, string|null>  $credentials  this invocation's own variables (API key,
     *                                                   push token…); null values are dropped
     * @param  array<string, string>|null  $inherited  the parent environment; defaults to the
     *                                                 same sources Symfony Process inherits from
     * @return array<string, string|false> env for Process::env() — `false` removes a variable
     */
    public function environment(array $credentials, ?array $inherited = null): array
    {
        $inherited ??= self::parentEnvironment();
        $allowed = array_merge(self::BASE_ALLOWLIST, config('boschifai.claude.hardening.env_passthrough', []));

        $env = [];
        foreach ($inherited as $name => $value) {
            $env[$name] = in_array($name, $allowed, true) ? $value : false;
        }

        foreach ($credentials as $name => $value) {
            if ($value !== null && $value !== '') {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $providerEnv  provider settings for this invocation (see
     *                                              ResolvedClaudeCredentials::$pinnedEnv) — pinned
     *                                              the same way, over anything a repo sets
     * @return list<string>
     */
    public function cliArguments(array $providerEnv = []): array
    {
        $settings = self::PINNED_SETTINGS + [
            'env' => [
                // Which provider the CLI talks to is ours to decide, never the repo's: otherwise a
                // repo could switch a run onto Bedrock/Vertex and point it at its own endpoint,
                // where the AWS/Google SDK would sign requests with whatever credentials the
                // worker's machine has. Off unless this invocation's credentials say otherwise.
                'CLAUDE_CODE_USE_BEDROCK' => '0',
                'CLAUDE_CODE_USE_VERTEX' => '0',
                ...$providerEnv,
                'ANTHROPIC_BASE_URL' => config('boschifai.claude.hardening.api_base_url'),
                'NODE_TLS_REJECT_UNAUTHORIZED' => '1',
                // The worker's own proxy settings (if any) still reach the CLI through
                // environment(); these entries only stop a repo's settings from adding one.
                'HTTPS_PROXY' => getenv('HTTPS_PROXY') ?: '',
                'HTTP_PROXY' => getenv('HTTP_PROXY') ?: '',
                'https_proxy' => getenv('https_proxy') ?: '',
                'http_proxy' => getenv('http_proxy') ?: '',
                'ALL_PROXY' => '',
            ],
        ];

        return [
            '--setting-sources', 'user,project',
            '--settings', json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            '--strict-mcp-config',
            ...(config('boschifai.claude.hardening.restricted_mode') ? ['--restricted'] : []),
        ];
    }

    /**
     * The same sources Symfony Process merges into a child by default (Process::getDefaultEnv):
     * string values from $_SERVER and $_ENV plus getenv().
     *
     * @return array<string, string>
     */
    private static function parentEnvironment(): array
    {
        $env = getenv();
        foreach ([$_SERVER, $_ENV] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $env[$name] = $value;
                }
            }
        }

        return $env;
    }
}
