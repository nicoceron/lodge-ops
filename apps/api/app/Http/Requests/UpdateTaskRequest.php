<?php

namespace App\Http\Requests;

use App\Enums\TaskStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends StoreTaskRequest
{
    public function rules(): array
    {
        if (app(TenantContext::class)->membership()?->role?->canScheduleOperations() !== true) {
            return [
                'status' => ['required', Rule::enum(TaskStatus::class)],
            ];
        }

        $rules = parent::rules();
        foreach (['property_id', 'title'] as $field) {
            $rules[$field][0] = 'sometimes';
        }

        return $rules;
    }
}
