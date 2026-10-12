<?php

namespace App\Services\ClaudeRunner;

/**
 * Removes known secret values from `claude` output before it is stored. The agent's tools run
 * with the invocation's credentials in their environment, so a command like `env` would
 * otherwise write the API key (or push token) into the transcript, where anyone who can view
 * the run could read it.
 *
 * Output arrives in arbitrary chunks, so a secret can be split across two of them: write()
 * only releases complete lines (the transcript is JSON lines and secrets never contain a
 * newline), and finish() releases whatever is left at the end.
 */
class SecretRedactor
{
    public const REPLACEMENT = '[REDACTED]';

    /** Shorter values are too likely to match ordinary text to be worth replacing. */
    private const MIN_SECRET_LENGTH = 8;

    /** @var list<string> */
    private array $secrets;

    private string $pending = '';

    /** @param  iterable<string|null>  $secrets */
    public function __construct(iterable $secrets)
    {
        $secrets = array_filter(
            array_map('strval', is_array($secrets) ? $secrets : iterator_to_array($secrets, false)),
            fn (string $secret) => strlen($secret) >= self::MIN_SECRET_LENGTH,
        );
        // Longest first, so a secret that contains another is replaced whole.
        usort($secrets, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $this->secrets = array_values(array_unique($secrets));
    }

    public function redact(string $text): string
    {
        return $this->secrets ? str_replace($this->secrets, self::REPLACEMENT, $text) : $text;
    }

    /** Buffers $chunk and returns the redacted complete lines available so far. */
    public function write(string $chunk): string
    {
        $this->pending .= $chunk;
        $end = strrpos($this->pending, "\n");
        if ($end === false) {
            return '';
        }

        $complete = substr($this->pending, 0, $end + 1);
        $this->pending = substr($this->pending, $end + 1);

        return $this->redact($complete);
    }

    /** Returns the redacted remainder (a final line without a newline). */
    public function finish(): string
    {
        $rest = $this->pending;
        $this->pending = '';

        return $this->redact($rest);
    }
}
