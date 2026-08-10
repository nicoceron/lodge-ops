<?php

namespace App\Services\Automation;

class AutomationTemplateRenderer
{
    /** @param array<string, mixed> $context */
    public function render(?string $template, array $context): ?string
    {
        if ($template === null) {
            return null;
        }

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($context): string {
                $value = data_get($context, $match[1]);

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        );
    }
}
