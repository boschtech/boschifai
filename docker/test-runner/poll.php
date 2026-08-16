<?php

/**
 * The test-runner sidecar's entire job: poll a shared directory for work, run it, report back.
 *
 * Deliberately plain PHP with NO `require vendor/autoload.php` and no Laravel bootstrap of any
 * kind — this is the whole point of this container's existence. Booting Laravel here would load
 * Boschifai's own `.env` (even though this container's compose service carries none of the
 * DB_, QUEUE_CONNECTION, or ANTHROPIC_API_KEY vars itself, Dotenv would still `putenv()` whatever a
 * mounted `.env` FILE contains), and those vars would then leak into the child process this
 * script spawns for a connected repo's own `composer install`/test command — recreating, one
 * level removed, the exact real incident that motivated moving this off the `worker` container
 * in the first place (a connected repo's own `RefreshDatabase`-based test suite inherited
 * Boschifai's live DB credentials and ran `migrate:fresh` against them). This container's own
 * docker-compose mounts intentionally expose NOTHING of Boschifai's own app code/config —
 * only the two paths a checkout can live under (see docker-compose.yml's `test-runner` service)
 * plus this jobs directory — so there's no `.env` here to accidentally load even by mistake.
 *
 * Protocol (all files live in JOBS_DIR, shared with `worker` via the same bind mount):
 *   {run_id}.job.json   written by LocalTestRunner  — {cwd, command, timeout_seconds}
 *   {run_id}.lock       written by this poller       — claims a job so it's only run once
 *   {run_id}.log        written by this poller       — live stdout+stderr, tailed by the app's
 *                                                       activity endpoint exactly like the old
 *                                                       in-process log did
 *   {run_id}.cancel     written by LocalTestRunner   — polled while running; presence kills the
 *                                                       child process early
 *   {run_id}.done.json  written by this poller       — {exit_code, cancelled, timed_out}, the
 *                                                       signal LocalTestRunner is waiting for
 */

const JOBS_DIR = '/var/boschifai-jobs';
const POLL_INTERVAL_SECONDS = 1;
const CANCEL_CHECK_INTERVAL_SECONDS = 1.0;
const PROCESS_POLL_MICROSECONDS = 300_000;

function processOneJob(string $jobPath): void
{
    $runId = basename($jobPath, '.job.json');
    $lockPath = JOBS_DIR."/{$runId}.lock";
    $donePath = JOBS_DIR."/{$runId}.done.json";
    $logPath = JOBS_DIR."/{$runId}.log";
    $cancelPath = JOBS_DIR."/{$runId}.cancel";

    if (file_exists($donePath) || file_exists($lockPath)) {
        return; // already processed, or another iteration already claimed it
    }

    // Not perfectly atomic (a real race would need flock/O_EXCL), but this poller is a single
    // process handling jobs one at a time in its own loop — there is no concurrent claimant.
    touch($lockPath);

    $job = json_decode(file_get_contents($jobPath), true);
    $cwd = $job['cwd'];
    $command = $job['command'];
    $timeoutSeconds = (int) ($job['timeout_seconds'] ?? 900);

    file_put_contents($logPath, '');

    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['sh', '-c', $command], $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        file_put_contents($donePath, json_encode(['exit_code' => -1, 'cancelled' => false, 'timed_out' => false]));
        @unlink($lockPath);

        return;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $started = microtime(true);
    $lastCancelCheck = 0.0;
    $cancelled = false;
    $timedOut = false;

    while (true) {
        $status = proc_get_status($process);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if ($out !== false && $out !== '') {
            file_put_contents($logPath, $out, FILE_APPEND);
        }
        if ($err !== false && $err !== '') {
            file_put_contents($logPath, $err, FILE_APPEND);
        }

        if (! $status['running']) {
            break;
        }

        $now = microtime(true);

        if ($now - $started >= $timeoutSeconds) {
            proc_terminate($process, 9);
            $timedOut = true;
            break;
        }

        if ($now - $lastCancelCheck >= CANCEL_CHECK_INTERVAL_SECONDS) {
            $lastCancelCheck = $now;
            if (file_exists($cancelPath)) {
                proc_terminate($process, 9);
                $cancelled = true;
                break;
            }
        }

        usleep(PROCESS_POLL_MICROSECONDS);
    }

    $exitCode = $status['exitcode'] ?? -1;
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    file_put_contents($donePath, json_encode([
        'exit_code' => $exitCode,
        'cancelled' => $cancelled,
        'timed_out' => $timedOut,
    ]));

    @unlink($lockPath);
    @unlink($cancelPath);
}

fwrite(STDOUT, "test-runner: polling ".JOBS_DIR." every ".POLL_INTERVAL_SECONDS."s\n");

while (true) {
    foreach (glob(JOBS_DIR.'/*.job.json') ?: [] as $jobPath) {
        processOneJob($jobPath);
    }

    sleep(POLL_INTERVAL_SECONDS);
}
