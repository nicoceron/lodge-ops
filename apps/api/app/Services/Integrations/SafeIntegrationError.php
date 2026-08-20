<?php

namespace App\Services\Integrations;

use Throwable;

final class SafeIntegrationError
{
    public static function from(Throwable|string $error): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '[redacted-auth]', $message) ?? 'Integration operation failed.';
        $message = preg_replace('/(?:token|secret|password|api[_-]?key)=([^\s&]+)/i', '$1=[redacted]', $message) ?? 'Integration operation failed.';
        $message = preg_replace('#https?://[^\s]+#i', '[redacted-url]', $message) ?? 'Integration operation failed.';

        return (string) str($message)->squish()->limit(500);
    }
}
