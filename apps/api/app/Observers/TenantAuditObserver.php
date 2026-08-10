<?php

namespace App\Observers;

use App\Models\Audit;
use App\Models\TenantModel;
use Illuminate\Support\Facades\Auth;

class TenantAuditObserver
{
    public function created(TenantModel $model): void
    {
        $this->record($model, 'created', null, $model->getAttributes());
    }

    public function updated(TenantModel $model): void
    {
        $changes = $model->getChanges();
        unset($changes['updated_at']);
        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }

        $this->record($model, 'updated', $old, $changes);
    }

    public function deleted(TenantModel $model): void
    {
        $this->record($model, 'deleted', $model->getAttributes(), null);
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new */
    private function record(TenantModel $model, string $event, ?array $old, ?array $new): void
    {
        $request = app()->runningInConsole() ? null : request();

        Audit::query()->create([
            'actor_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
