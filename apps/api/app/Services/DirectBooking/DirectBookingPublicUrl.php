<?php

namespace App\Services\DirectBooking;

final class DirectBookingPublicUrl
{
    public function isSafeHttps(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);

        $host = is_array($parts) && is_string($parts['host'] ?? null)
            ? rtrim(strtolower($parts['host']), '.')
            : '';

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && $host !== 'localhost'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }
}
