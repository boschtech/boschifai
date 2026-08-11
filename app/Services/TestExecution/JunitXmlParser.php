<?php

namespace App\Services\TestExecution;

/** Parses a PHPUnit `--log-junit` XML report into the shape TestExecutionResult persists. */
class JunitXmlParser
{
    /** @return array{total: int, passed: int, failed: int, skipped: int, duration_ms: int, tests: array<int, array{name: string, status: string, message: ?string}>} */
    public function parse(string $xmlPath): array
    {
        $xml = simplexml_load_file($xmlPath);

        if ($xml === false) {
            throw new \RuntimeException("Could not parse JUnit XML at {$xmlPath}");
        }

        $total = 0;
        $failed = 0;
        $skipped = 0;
        $durationSeconds = 0.0;
        $tests = [];

        foreach ($xml->xpath('//testcase') as $testcase) {
            $total++;
            $durationSeconds += (float) ($testcase['time'] ?? 0);
            $name = (string) ($testcase['class'] ?? '').'::'.(string) ($testcase['name'] ?? '');

            $failureNode = $testcase->failure ?? $testcase->error ?? null;
            if ($failureNode !== null) {
                $failed++;
                $tests[] = [
                    'name' => $name,
                    'status' => 'failed',
                    'message' => (string) ($failureNode['message'] ?? trim((string) $failureNode)),
                ];
            } elseif (isset($testcase->skipped)) {
                $skipped++;
                $tests[] = ['name' => $name, 'status' => 'skipped', 'message' => null];
            } else {
                $tests[] = ['name' => $name, 'status' => 'passed', 'message' => null];
            }
        }

        return [
            'total' => $total,
            'passed' => $total - $failed - $skipped,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_ms' => (int) round($durationSeconds * 1000),
            'tests' => $tests,
        ];
    }
}
