<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Membership $membership = null;

    public function set(Tenant $tenant, ?Membership $membership = null): void
    {
        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('No tenant has been resolved for this request.');
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function propertyScopeId(): ?string
    {
        if ($this->membership?->role->hasTenantWidePropertyAccess() === true) {
            return null;
        }

        return $this->membership?->property_id;
    }

    public function canAccessProperty(string $propertyId): bool
    {
        $scopeId = $this->propertyScopeId();

        return $scopeId === null || $scopeId === $propertyId;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }
}
