<?php

namespace App\Services\Integrations;

use Throwable;

final class SafeIntegrationError
{
    public static function from(Throwable|string $error): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = preg_replace('/\b(?:Bearer|Basic)\s+[^\s,;]+/i', '[redacted-auth]', $message) ?? 'Integration operation failed.';
        $message = preg_replace(
            '/(["\']?(?:(?:access[_-]?)?token|client[_-]?secret|secret|password|api[_-]?key|private[_-]?key|credential)["\']?)\s*([:=])\s*(?:"[^"]*"|\'[^\']*\'|[^\s&,;}]+)/i',
            '$1$2[redacted]',
            $message,
        ) ?? 'Integration operation failed.';
        $message = preg_replace('#https?://[^\s]+#i', '[redacted-url]', $message) ?? 'Integration operation failed.';

        return (string) str($message)->squish()->limit(500);
    }

    public static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::from($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        return collect($value)->mapWithKeys(function (mixed $item, string|int $key): array {
            if (is_string($key) && preg_match('/(?:token|secret|password|api[_-]?key|authorization)/i', $key) === 1) {
                return [$key => '[redacted]'];
            }

            return [$key => self::value($item)];
        })->all();
    }
}
