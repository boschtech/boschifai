<?php

namespace App\Services\ClaudeRunner;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Parses a `claude -p ... --output-format stream-json` JSONL transcript.
 *
 * NOTE: field names below match Claude Code's documented stream-json "result" message shape
 * at the time this was written. CLI output formats can shift between releases — every field
 * read here defaults to null on a miss rather than throwing, so a schema drift degrades the
 * confidence score's "test-case generation quality" input gracefully instead of crashing the
 * pipeline. Confirm against the installed CLI version during the §3 verification spike.
 */
class ClaudeTranscriptParser
{
    /**
     * @return array{
     *     total_cost_usd: ?float, num_turns: ?int, stop_reason: ?string,
     *     input_tokens: ?int, output_tokens: ?int,
     *     cache_creation_input_tokens: ?int, cache_read_input_tokens: ?int,
     * }
     */
    public function parseSummary(string $transcriptPath): array
    {
        $summary = [
            'total_cost_usd' => null, 'num_turns' => null, 'stop_reason' => null,
            'input_tokens' => null, 'output_tokens' => null,
            'cache_creation_input_tokens' => null, 'cache_read_input_tokens' => null,
        ];

        if (! File::exists($transcriptPath)) {
            return $summary;
        }

        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'result') {
                continue;
            }

            $summary['total_cost_usd'] = isset($decoded['total_cost_usd']) ? (float) $decoded['total_cost_usd'] : null;
            $summary['num_turns'] = isset($decoded['num_turns']) ? (int) $decoded['num_turns'] : null;
            $summary['stop_reason'] = $decoded['stop_reason'] ?? $decoded['subtype'] ?? null;

            // `usage` — confirmed by reading a real transcript's "result" line (see
            // ClaudeInvocation's token columns): input_tokens/output_tokens are the two
            // headline numbers, cache_creation/cache_read are still real billed tokens (a
            // prompt-cache write/hit) and need to be added in for an accurate monthly total.
            $usage = $decoded['usage'] ?? null;
            if (is_array($usage)) {
                $summary['input_tokens'] = isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
                $summary['output_tokens'] = isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
                $summary['cache_creation_input_tokens'] = isset($usage['cache_creation_input_tokens'])
                    ? (int) $usage['cache_creation_input_tokens'] : null;
                $summary['cache_read_input_tokens'] = isset($usage['cache_read_input_tokens'])
                    ? (int) $usage['cache_read_input_tokens'] : null;
            }
        }

        return $summary;
    }

    /**
     * Extracts the /boschifai-test-cases command's own Step-3b Task-tool validator output
     * ("PASS: n | FAIL: n" / "VERDICT: APPROVED|NEEDS_FIXES") from the transcript, if present.
     * This is the confidence score's "test-case generation quality" input (plan §7) — it's
     * functionally the boschifai-reviewer behavior the original ask wanted, already built into this
     * command rather than a separate step.
     */
    public function extractTestCaseVerdict(string $transcriptPath): ?string
    {
        if (! File::exists($transcriptPath)) {
            return null;
        }

        $fullText = '';
        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            $fullText .= $this->extractTextFromMessage($decoded)."\n";
        }

        if (preg_match('/VERDICT:\s*(APPROVED|NEEDS_FIXES)/', $fullText, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extracts the `RECOMMENDED_TARGET_FILE: <path>` line PromptBuilder::coverageTestDesign()
     * asks Claude to end with — coverage mode's equivalent of a human-specified
     * `target_file_path` (see RunTestPlanJob).
     */
    public function extractRecommendedTargetFile(string $transcriptPath): ?string
    {
        if (! File::exists($transcriptPath)) {
            return null;
        }

        $fullText = '';
        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            $fullText .= $this->extractTextFromMessage($decoded)."\n";
        }

        if (preg_match('/^RECOMMENDED_TARGET_FILE:\s*(.+)$/m', $fullText, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extracts the `GENERATED_TEST_FILE: <path>` line PromptBuilder::codeGeneration()/
     * coverageCodeGeneration() ask Claude to end with — RunCodeGenerationJob's fallback for when
     * the git-status diff finds nothing new. See that prompt method's own docblock for why: an
     * edit to a test file already left in the checkout from an earlier run never looks "new" to
     * that diff, even though its content genuinely changed.
     */
    public function extractGeneratedTestFile(string $transcriptPath): ?string
    {
        if (! File::exists($transcriptPath)) {
            return null;
        }

        $fullText = '';
        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            $fullText .= $this->extractTextFromMessage($decoded)."\n";
        }

        if (preg_match('/^GENERATED_TEST_FILE:\s*(.+)$/m', $fullText, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extracts the `PR_URL:`/`PR_NUMBER:` lines PromptBuilder::pushViaGithubMcp() asks Claude to
     * end with, once it's pushed the already-prepared local branch and opened the PR via the
     * GitHub MCP server. Returns null if either line is missing or PR_NUMBER isn't a plain
     * integer — PushAndOpenPrJob treats that the same as any other push failure, since there's
     * nothing safe to verify or record without a real PR number.
     *
     * @return ?array{number: int, url: string}
     */
    public function extractPushResult(string $transcriptPath): ?array
    {
        if (! File::exists($transcriptPath)) {
            return null;
        }

        $fullText = '';
        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            $fullText .= $this->extractTextFromMessage($decoded)."\n";
        }

        if (! preg_match('/^PR_URL:\s*(\S+)$/m', $fullText, $urlMatch)
            || ! preg_match('/^PR_NUMBER:\s*(\d+)$/m', $fullText, $numberMatch)) {
            return null;
        }

        return ['number' => (int) $numberMatch[1], 'url' => $urlMatch[1]];
    }

    /**
     * A readable, live-updating feed of what Claude is actually doing — assistant prose plus a
     * one-line summary of each tool call (e.g. "→ Bash: composer install ..."). Deliberately
     * skips raw tool_result payloads (often huge file contents) to keep the feed scannable.
     * Built directly in response to a real user report: watching the run detail page show a
     * static "Gap analysis" badge for minutes with no other feedback, unable to tell whether
     * anything was happening at all.
     *
     * @return string[] oldest first
     */
    public function tailEvents(string $transcriptPath, int $maxEvents = 200): array
    {
        if (! File::exists($transcriptPath)) {
            return [];
        }

        $events = [];
        foreach ($this->readLines($transcriptPath) as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            array_push($events, ...$this->eventsFromMessage($decoded));
        }

        return array_slice($events, -$maxEvents);
    }

    /** @return string[] */
    private function eventsFromMessage(array $decoded): array
    {
        if (! in_array($decoded['type'] ?? null, ['assistant', 'user'], true)) {
            return [];
        }

        $content = $decoded['message']['content'] ?? null;
        if (! is_array($content)) {
            return [];
        }

        $events = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            $text = trim($block['text'] ?? '');
            if ($block['type'] === 'text' && $text !== '') {
                $events[] = $text;
            } elseif ($block['type'] === 'tool_use') {
                $events[] = '→ '.($block['name'] ?? 'tool').': '.$this->summarizeToolInput($block['input'] ?? []);
            }
            // tool_result intentionally skipped — usually large raw file/command output that
            // would drown out the readable narrative of what's happening.
        }

        return $events;
    }

    private function summarizeToolInput(mixed $input): string
    {
        if (is_array($input)) {
            // The Skill tool's input is {"skill": "...", "args": "..."} — neither key alone is
            // as useful as combining them (e.g. "boschifai-test-cases --file ...").
            if (isset($input['skill']) && is_string($input['skill'])) {
                $args = is_string($input['args'] ?? null) ? ' '.$input['args'] : '';

                return Str::limit($input['skill'].$args, 140);
            }

            foreach (['command', 'file_path', 'path', 'pattern', 'prompt', 'description'] as $key) {
                if (isset($input[$key]) && is_string($input[$key])) {
                    return Str::limit($input[$key], 140);
                }
            }
            $input = json_encode($input);
        }

        return Str::limit((string) $input, 140);
    }

    private function extractTextFromMessage(mixed $decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }

        $content = $decoded['message']['content'] ?? null;
        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= ($block['text'] ?? '')."\n";
            }
            // Task-tool sub-invocation results often surface as tool_result blocks.
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                $inner = $block['content'] ?? '';
                $text .= (is_string($inner) ? $inner : json_encode($inner))."\n";
            }
        }

        return $text;
    }

    /** @return \Generator<string> */
    private function readLines(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
