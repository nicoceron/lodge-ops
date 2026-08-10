<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends TenantModel
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
