<?php

namespace App\Services\Automation;

use DomainException;

class AutomationTemplateRenderer
{
    private const ALLOWED_FIELDS = [
        'tenant.id', 'tenant.name', 'tenant.currency', 'tenant.timezone',
        'reservation.id', 'reservation.confirmation_number', 'reservation.starts_at', 'reservation.ends_at',
        'reservation.status', 'reservation.currency', 'reservation.total_minor', 'reservation.adults', 'reservation.children',
        'reservation.code', 'reservation.primary_guest.first_name', 'guest.first_name',
        'payment.id', 'payment.status', 'payment.amount_minor', 'payment.currency',
        'deposit.id', 'deposit.amount_minor', 'deposit.currency', 'deposit.due_at',
        'payload.reservation_id', 'payload.deposit_id', 'payload.days_before',
        'guest_portal.url', 'event_type',
    ];

    /** @param array<string, mixed> $context */
    public function render(?string $template, array $context): ?string
    {
        if ($template === null) {
            return null;
        }

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($context): string {
                $field = $match[1];
                if (! in_array($field, self::ALLOWED_FIELDS, true)) {
                    throw new DomainException("Template merge field [{$field}] is not allowed.");
                }

                $missing = new \stdClass;
                $value = data_get($context, $field, $missing);
                if ($value === $missing || ! is_scalar($value)) {
                    throw new DomainException("Template merge field [{$field}] is missing.");
                }

                return (string) $value;
            },
            $template,
        );
    }
}
