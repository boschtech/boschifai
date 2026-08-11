<?php

namespace App\Services\Github;

use App\Models\Run;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polls GitHub check-runs on the PR's head SHA for RAMS's existing `sonar-scan.yml` — no
 * workflow files are touched or created (plan §7 decision #7); this is pure read-only polling
 * of a pipeline that already exists.
 */
class CiPollingService
{
    public function __construct(private GithubOAuthService $githubAuth)
    {
    }

    /** @return array{conclusion: ?string, check_run_id: ?string}|null null if the check hasn't appeared yet */
    public function poll(Run $run): ?array
    {
        $token = $this->githubAuth->tokenFor($run->repoConfig);
        $owner = $run->repoConfig->github_owner;
        $repo = $run->repoConfig->name;

        $response = Http::withToken($token)
            ->acceptJson()
            ->get("https://api.github.com/repos/{$owner}/{$repo}/commits/{$run->head_sha}/check-runs");

        if ($response->failed()) {
            throw new RuntimeException('GitHub check-runs poll failed: '.$response->body());
        }

        $checkRuns = $response->json('check_runs', []);

        // sonar-scan.yml's job id is "test" — match loosely on name since the exact display
        // name (workflow name vs. job name) can vary; this is read-only polling, a miss here
        // just means "still pending", not a false failure.
        $match = collect($checkRuns)->first(
            fn ($run) => str_contains(strtolower($run['name'] ?? ''), 'sonar')
                || str_contains(strtolower($run['name'] ?? ''), 'test')
        );

        if ($match === null) {
            return null;
        }

        return [
            'conclusion' => $match['status'] === 'completed' ? $match['conclusion'] : null,
            'check_run_id' => (string) $match['id'],
        ];
    }
}
