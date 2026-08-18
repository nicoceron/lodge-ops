<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class DocumentTemplate extends TenantModel
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'definition' => 'array', 'is_active' => 'boolean'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function generationRequests(): HasMany
    {
        return $this->hasMany(DocumentGenerationRequest::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $template): void {
            if ($template->isDirty(['name', 'kind', 'version', 'definition'])) {
                throw new LogicException('Document template versions are immutable. Create a new version instead.');
            }
        });
        static::deleting(function (self $template): void {
            if ($template->documents()->exists()) {
                throw new LogicException('Document template versions used by generated documents cannot be deleted.');
            }
        });
    }
}
