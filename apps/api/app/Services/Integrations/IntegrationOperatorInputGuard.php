<?php

namespace App\Services\Integrations;

use App\Services\Payments\SensitivePaymentDataGuard;
use Illuminate\Validation\ValidationException;

final class IntegrationOperatorInputGuard
{
    public function __construct(
        private readonly SensitivePaymentDataGuard $paymentData,
        private readonly IntegrationConfigurationPolicy $configuration,
    ) {}

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public function admit(mixed $value, string $field): mixed
    {
        $this->paymentData->assertSafe($value, $field);
        $this->assertSafe($value, $field);

        return $value;
    }

    private function assertSafe(mixed $value, string $path, int $depth = 0): void
    {
        if ($depth > 8) {
            $this->reject($path, 'Integration operator input nesting is too deep.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $key = (string) $key;
                $normalized = strtolower(str_replace(['-', ' '], '_', $key));
                $childPath = $path.'.'.$key;
                $headerKey = str_replace('_', '', $normalized);
                if (in_array($headerKey, ['authorization', 'proxyauthorization', 'cookie', 'setcookie', 'xapikey'], true)) {
                    $this->reject($childPath, 'Authentication headers cannot be stored in integration records.');
                }
                $sensitiveKey = preg_match('/(?:secret|password|credential|private_?key|(?:access_?)?token|api_?key)/i', $normalized) === 1;
                $referenceKey = str_ends_with($normalized, '_reference');
                if ($sensitiveKey && ! $referenceKey) {
                    $this->reject($childPath, 'Secrets must be stored in the external secret manager.');
                }
                if ($referenceKey && (! is_string($nested) || ! $this->configuration->isSecretReference($nested))) {
                    $this->reject($childPath, 'Secret references must use an approved secret-manager URI.');
                }
                $this->assertSafe($nested, $childPath, $depth + 1);
            }

            return;
        }
        if (! is_string($value)) {
            return;
        }
        if (preg_match('/\b(?:Bearer|Basic)\s+\S+/i', $value) === 1
            || preg_match('#\b[a-z][a-z0-9+.-]*://[^/@\s]+:[^/@\s]+@#i', $value) === 1
            || preg_match('/\b(?:AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{20,}|sk_(?:live|test)_[A-Za-z0-9]{16,})\b/', $value) === 1
            || preg_match('/\b(?:token|secret|password|credential|private[ _-]?key|access[ _-]?token|api[ _-]?key)\s*[:=]\s*[^\s,;]+/i', $value) === 1) {
            $this->reject($path, 'Sensitive credential values cannot be stored in integration records.');
        }
    }

    private function reject(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => $message]);
    }
}
