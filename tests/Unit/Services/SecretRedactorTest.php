<?php

namespace Tests\Unit\Services;

use App\Services\ClaudeRunner\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_it_replaces_every_occurrence_of_each_secret(): void
    {
        $redactor = new SecretRedactor(['sk-ant-secret-1', 'ghp_pushtoken']);

        $this->assertSame(
            'key=[REDACTED] again [REDACTED] token=[REDACTED]',
            $redactor->redact('key=sk-ant-secret-1 again sk-ant-secret-1 token=ghp_pushtoken'),
        );
    }

    public function test_short_empty_and_missing_values_are_ignored(): void
    {
        $redactor = new SecretRedactor(['', null, 'short', 'sk-ant-secret-1']);

        $this->assertSame('a short note, [REDACTED]', $redactor->redact('a short note, sk-ant-secret-1'));
        $this->assertSame('nothing to hide', (new SecretRedactor([]))->redact('nothing to hide'));
    }

    public function test_a_secret_containing_another_is_replaced_whole(): void
    {
        $redactor = new SecretRedactor(['abcdefgh', 'abcdefgh-longer']);

        $this->assertSame('[REDACTED] and [REDACTED]', $redactor->redact('abcdefgh-longer and abcdefgh'));
    }

    public function test_a_secret_split_across_chunks_is_still_redacted(): void
    {
        $redactor = new SecretRedactor(['sk-ant-secret-1']);

        $out = $redactor->write('{"text":"sk-ant-');
        $this->assertSame('', $out, 'Nothing is released until the line is complete.');

        $out .= $redactor->write("secret-1\"}\n{\"text\":\"sk-ant-sec");
        $this->assertSame("{\"text\":\"[REDACTED]\"}\n", $out);

        $out .= $redactor->write('ret-1"}');
        $out .= $redactor->finish();

        $this->assertSame("{\"text\":\"[REDACTED]\"}\n{\"text\":\"[REDACTED]\"}", $out);
        $this->assertSame('', $redactor->finish(), 'finish() empties the buffer.');
    }
}
