<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaudeInvocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UsageController extends Controller
{
    /**
     * Every Claude-driven pipeline step (gap analysis, test plan, test case generation, code
     * generation, push) records its own token usage on `claude_invocations` (see
     * ClaudeTranscriptParser::parseSummary — pulled straight from the CLI's own "result"
     * message `usage` object, not estimated). Summed here by calendar month rather than a
     * rolling 30 days, matching how a monthly API/billing cycle is usually read.
     *
     * `cache_creation_input_tokens`/`cache_read_input_tokens` are included in the total: a
     * prompt-cache write or hit is still a real, billed token count, not free reuse — leaving
     * them out would understate actual usage significantly on multi-turn agentic steps.
     */
    public function tokensThisMonth()
    {
        return response()->json($this->monthlyTotals());
    }

    /**
     * Same monthly totals as tokensThisMonth(), plus every individual invocation that
     * contributed to them — the header badge links here (and so does the "Token Usage" entry
     * under Reporting) for anyone who wants to see exactly which run/step the spend came from,
     * not just the aggregate.
     */
    public function tokensThisMonthDetails()
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $invocations = ClaudeInvocation::whereBetween('claude_invocations.created_at', [$start, $end])
            ->join('run_steps', 'run_steps.id', '=', 'claude_invocations.run_step_id')
            ->join('runs', 'runs.id', '=', 'run_steps.run_id')
            ->leftJoin('repo_configs', 'repo_configs.id', '=', 'runs.repo_config_id')
            ->orderByDesc('claude_invocations.created_at')
            ->get([
                'claude_invocations.id',
                'claude_invocations.command',
                'claude_invocations.created_at',
                'claude_invocations.duration_ms',
                'claude_invocations.total_cost_usd',
                'claude_invocations.num_turns',
                'claude_invocations.stop_reason',
                'claude_invocations.timed_out',
                'claude_invocations.input_tokens',
                'claude_invocations.output_tokens',
                'claude_invocations.cache_creation_input_tokens',
                'claude_invocations.cache_read_input_tokens',
                'run_steps.key as step_key',
                'runs.id as run_id',
                'runs.requirement_text',
                'repo_configs.display_name as repo_name',
            ])
            ->map(function ($row) {
                $totalTokens = (int) $row->input_tokens + (int) $row->output_tokens
                    + (int) $row->cache_creation_input_tokens + (int) $row->cache_read_input_tokens;

                return [
                    'id' => $row->id,
                    'run_id' => $row->run_id,
                    'repo_name' => $row->repo_name,
                    'requirement_summary' => Str::limit(trim(explode("\n", trim($row->requirement_text ?? ''))[0] ?? ''), 80),
                    'step_key' => $row->step_key,
                    'command' => $row->command,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'cache_creation_input_tokens' => (int) $row->cache_creation_input_tokens,
                    'cache_read_input_tokens' => (int) $row->cache_read_input_tokens,
                    'total_tokens' => $totalTokens,
                    'total_cost_usd' => $row->total_cost_usd !== null ? round((float) $row->total_cost_usd, 4) : null,
                    'num_turns' => $row->num_turns,
                    'duration_ms' => $row->duration_ms,
                    'stop_reason' => $row->stop_reason,
                    'timed_out' => (bool) $row->timed_out,
                    'created_at' => $row->created_at,
                ];
            });

        return response()->json([
            'summary' => $this->monthlyTotals(),
            'invocations' => $invocations,
        ]);
    }

    private function monthlyTotals(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $totals = ClaudeInvocation::whereBetween('created_at', [$start, $end])
            ->select([
                DB::raw('COALESCE(SUM(input_tokens), 0) as input_tokens'),
                DB::raw('COALESCE(SUM(output_tokens), 0) as output_tokens'),
                DB::raw('COALESCE(SUM(cache_creation_input_tokens), 0) as cache_creation_input_tokens'),
                DB::raw('COALESCE(SUM(cache_read_input_tokens), 0) as cache_read_input_tokens'),
                DB::raw('COALESCE(SUM(total_cost_usd), 0) as total_cost_usd'),
            ])
            ->first();

        $totalTokens = (int) $totals->input_tokens + (int) $totals->output_tokens
            + (int) $totals->cache_creation_input_tokens + (int) $totals->cache_read_input_tokens;

        return [
            'month' => $start->format('Y-m'),
            'input_tokens' => (int) $totals->input_tokens,
            'output_tokens' => (int) $totals->output_tokens,
            'cache_creation_input_tokens' => (int) $totals->cache_creation_input_tokens,
            'cache_read_input_tokens' => (int) $totals->cache_read_input_tokens,
            'total_tokens' => $totalTokens,
            'total_cost_usd' => round((float) $totals->total_cost_usd, 4),
        ];
    }
}
