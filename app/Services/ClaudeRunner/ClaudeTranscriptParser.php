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
    /** @return array{total_cost_usd: ?float, num_turns: ?int, stop_reason: ?string} */
    public function parseSummary(string $transcriptPath): array
    {
        $summary = ['total_cost_usd' => null, 'num_turns' => null, 'stop_reason' => null];

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
