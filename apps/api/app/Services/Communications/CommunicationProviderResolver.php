<?php

namespace App\Services\Communications;

use App\Data\ResolvedCommunicationProvider;
use App\Models\Communication;
use App\Models\CommunicationProviderConnection;
use DomainException;

final class CommunicationProviderResolver
{
    public function __construct(
        private readonly SecretReferenceResolver $secrets,
        private readonly ResendCommunicationProvider $resend,
        private readonly LaravelMailCommunicationProvider $local,
    ) {}

    public function resolve(Communication $communication): ResolvedCommunicationProvider
    {
        $propertyId = $communication->property_id ?? $communication->reservation?->property_id;
        $connection = is_string($propertyId) ? CommunicationProviderConnection::query()
            ->where('property_id', $propertyId)
            ->where('is_enabled', true)
            ->whereNull('revoked_at')
            ->first() : null;

        if ($connection === null) {
            if (! (bool) config('communications.fallback.enabled', false) || app()->environment('production')) {
                throw new DomainException('No enabled communication provider is configured for this property.');
            }

            return new ResolvedCommunicationProvider(
                $this->local,
                null,
                'local',
                '',
                trim(config('communications.fallback.from_name').' <'.config('communications.fallback.from_email').'>'),
                null,
            );
        }

        if ($connection->provider !== 'resend') {
            throw new DomainException('The configured communication provider is unsupported.');
        }
        if ($connection->verified_at === null) {
            throw new DomainException('The communication sender domain is not verified.');
        }

        $senderDomain = mb_strtolower((string) substr(strrchr($connection->from_email, '@') ?: '', 1));
        $allowed = array_map('mb_strtolower', $connection->allowed_sender_domains ?? []);
        if ($senderDomain === '' || ! in_array($senderDomain, $allowed, true)) {
            throw new DomainException('The configured sender is outside the connection allowlist.');
        }

        return new ResolvedCommunicationProvider(
            $this->resend,
            $connection,
            $connection->account_id,
            $this->secrets->resolve($connection->secret_ref),
            trim($connection->from_name).' <'.trim($connection->from_email).'>',
            $connection->reply_to_email,
        );
    }
}
