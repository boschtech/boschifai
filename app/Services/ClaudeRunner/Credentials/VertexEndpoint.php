<?php

namespace App\Services\ClaudeRunner\Credentials;

/**
 * Vertex AI's official endpoints, in the format the CLI's own Vertex client uses by default
 * (CLI 2.1.296) — pinning exactly these means a repo can't redirect requests, without changing
 * where they normally go.
 */
final class VertexEndpoint
{
    /** global, the us/eu multi-regions, or a region such as us-east5 or europe-west1. */
    public const REGION = '/^(global|us|eu|[a-z]+-[a-z]+\d+)$/';

    public static function baseUrl(string $region): string
    {
        return match ($region) {
            'global' => 'https://aiplatform.googleapis.com/v1',
            'us', 'eu' => "https://aiplatform.{$region}.rep.googleapis.com/v1",
            default => "https://{$region}-aiplatform.googleapis.com/v1",
        };
    }
}
