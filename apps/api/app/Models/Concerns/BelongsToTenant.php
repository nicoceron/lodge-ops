<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $contextId = app(TenantContext::class)->id();

            if ($contextId === null) {
                throw new LogicException('Tenant context is required to create '.class_basename($model).'.');
            }

            if ($model->tenant_id !== null && $model->tenant_id !== $contextId) {
                throw new LogicException('Cross-tenant model creation is forbidden.');
            }

            $model->tenant_id = $contextId;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
