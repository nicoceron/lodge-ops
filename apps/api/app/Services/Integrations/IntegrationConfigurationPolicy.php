<?php

namespace App\Services\Integrations;

use DomainException;

final class IntegrationConfigurationPolicy
{
    private const RESERVED_IDENTITY_KEYS = [
        'tenant_id', 'property_id', 'property_scope_key', 'type', 'provider', 'product',
        'provider_account', 'external_account_id', 'environment', 'capabilities',
    ];

    /** @param array<string,mixed> $configuration @param list<string> $capabilities @return array<string,mixed> */
    public function validate(array $configuration, string $type, string $provider, string $product, array $capabilities): array
    {
        $allowed = [];
        if ($type === 'calendar') {
            $allowed['calendar_id'] = 'string';
        }
        if ($provider === 'mercado_pago' && $product === 'checkout_pro') {
            $allowed += [
                'return_url_base' => 'url',
                'charge_currency' => 'currency',
                'use_sandbox_checkout_url' => 'bool',
                'transport' => 'transport',
                'fixture' => 'fixture',
                'webhook_secret_reference' => 'reference',
                'webhook_endpoint_key_reference' => 'reference',
            ];
        }
        if (in_array('webhook.inbound', $capabilities, true)) {
            $allowed['webhook_signing_secret_reference'] = 'reference';
        }
        if (in_array('webhook.outbound', $capabilities, true)) {
            $allowed['endpoint_url'] = 'url';
            $allowed['outbound_signing_secret_reference'] = 'reference';
        }
        if (app()->environment(['local', 'testing']) && in_array($provider, ['contract_fake', 'pg_fake'], true)) {
            $allowed['fixture'] = 'fixture';
        }

        foreach ($configuration as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::RESERVED_IDENTITY_KEYS, true)) {
                throw new DomainException('Canonical integration identity must be stored in typed connection columns.');
            }
            $typeName = $allowed[$normalized] ?? null;
            if ($typeName === null) {
                throw new DomainException("Unsupported non-secret integration configuration key: {$normalized}.");
            }
            $this->assertTyped($normalized, $value, $typeName);
        }
        $this->assertRecursiveSafety($configuration);
        if (($configuration['transport'] ?? null) === 'deterministic_fixture'
            && (! app()->environment(['local', 'testing']) || ! isset($configuration['fixture']))) {
            throw new DomainException('The deterministic fixture transport requires an explicit local/test fixture.');
        }

        return $configuration;
    }

    /** @param array<string,mixed>|null $configuration @return array<string,mixed> */
    public function publicView(?array $configuration): array
    {
        $visible = [];
        foreach ($configuration ?? [] as $key => $value) {
            if ($key === 'fixture') {
                $visible[$key] = '[test fixture configured]';
            } elseif (str_ends_with(strtolower((string) $key), '_reference')) {
                $visible[$key] = '[configured]';
            } else {
                $visible[$key] = is_array($value) ? $this->publicView($value) : $value;
            }
        }

        return $visible;
    }

    private function assertTyped(string $key, mixed $value, string $type): void
    {
        $valid = match ($type) {
            'string' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 500,
            'bool' => is_bool($value),
            'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1,
            'transport' => $value === 'deterministic_fixture',
            'fixture' => is_array($value) && app()->environment(['local', 'testing']),
            'reference' => is_string($value) && $this->isSecretReference($value),
            'url' => is_string($value) && $this->isSafeUrl($value),
            default => false,
        };
        if (! $valid) {
            throw new DomainException("Invalid value for integration configuration key: {$key}.");
        }
    }

    /** @param array<mixed> $values */
    private function assertRecursiveSafety(array $values, int $depth = 0): void
    {
        if ($depth > 8) {
            throw new DomainException('Integration configuration nesting is too deep.');
        }
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::RESERVED_IDENTITY_KEYS, true)) {
                throw new DomainException('Canonical integration identity cannot be nested in configuration.');
            }
            if (in_array(str_replace(['-', '_'], '', $normalized), ['authorization', 'proxyauthorization', 'cookie', 'setcookie', 'xapikey'], true)) {
                throw new DomainException('Authentication headers cannot be stored in integration configuration.');
            }
            $sensitiveKey = preg_match('/(?:secret|password|credential|private.?key|access.?token|api.?key)/i', $normalized) === 1;
            if ($sensitiveKey && ! str_ends_with($normalized, '_reference')) {
                throw new DomainException('Secrets must be stored in the external secret manager.');
            }
            if (str_ends_with($normalized, '_reference') && (! is_string($value) || ! $this->isSecretReference($value))) {
                throw new DomainException('Secret references must use an approved secret-manager URI.');
            }
            if (is_array($value)) {
                $this->assertRecursiveSafety($value, $depth + 1);

                continue;
            }
            if (is_string($value) && (preg_match('/\b(?:Bearer|Basic)\s+\S+/i', $value) === 1
                || preg_match('#^[a-z][a-z0-9+.-]*://[^/@\s]+:[^/@\s]+@#i', $value) === 1
                || preg_match('/\b(?:AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|sk_(?:live|test)_[A-Za-z0-9]{16,})\b/', $value) === 1)) {
                throw new DomainException('Sensitive credential values cannot be stored in integration configuration.');
            }
        }
    }

    public function isSecretReference(string $reference): bool
    {
        return preg_match('/^(?:vault|aws-sm|gcp-sm|azure-kv|secret|env):(?:\/\/)?[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $reference) === 1;
    }

    private function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']);
    }
}
