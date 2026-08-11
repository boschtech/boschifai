<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the staged diff before a commit contains anything other than the exact expected
 * generated file — the hard safety control from plan §7/§8: RAMS is documented to carry
 * `.env`, `auth.json`, and a known hardcoded secret in `phpunit.xml`, so this must fail loudly
 * rather than let `git add` scope creep push something it shouldn't.
 */
class UnexpectedStagedFilesException extends RuntimeException
{
    public function __construct(public readonly array $unexpectedPaths)
    {
        parent::__construct('Staged diff contains unexpected paths: '.implode(', ', $unexpectedPaths));
    }
}
