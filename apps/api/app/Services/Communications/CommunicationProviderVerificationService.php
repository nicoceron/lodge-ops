<?php

namespace App\Services\Communications;

use App\Models\CommunicationProviderConnection;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Http;

final class CommunicationProviderVerificationService
{
    public function __construct(private readonly SecretReferenceResolver $secrets) {}

    public function verify(User $actor, CommunicationProviderConnection $connection): CommunicationProviderConnection
    {
        $actor->can('update', $connection) || abort(403);
        if ($connection->provider !== 'resend') {
            throw new DomainException('No safe verification workflow exists for this provider.');
        }

        $fromDomain = mb_strtolower((string) str($connection->from_email)->afterLast('@'));
        $allowed = array_map('mb_strtolower', $connection->allowed_sender_domains ?? []);
        if ($fromDomain === '' || ! in_array($fromDomain, $allowed, true)) {
            throw new DomainException('The sender domain must be explicitly allowlisted before verification.');
        }

        $response = Http::withToken($this->secrets->resolve($connection->secret_ref))
            ->acceptJson()->timeout((int) config('communications.provider.timeout_seconds', 20))
            ->get('https://api.resend.com/domains');
        $connection->forceFill(['verification_checked_at' => now()])->save();
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw new DomainException('Provider verification could not be completed.');
        }

        $verified = collect($response->json('data'))->first(fn (mixed $domain): bool => is_array($domain)
            && mb_strtolower((string) ($domain['name'] ?? '')) === $fromDomain
            && mb_strtolower((string) ($domain['status'] ?? '')) === 'verified');
        if (! is_array($verified)) {
            throw new DomainException('The configured sender domain is not verified by the provider.');
        }

        $connection->forceFill([
            'verified_at' => now(),
            'verified_by' => $actor->id,
            'verification_checked_at' => now(),
            'verification_reference' => mb_substr((string) ($verified['id'] ?? $fromDomain), 0, 190),
        ])->save();

        return $connection;
    }
}
