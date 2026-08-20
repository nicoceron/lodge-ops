<?php

namespace App\Services\Payments;

use App\Models\FinancialCommandRecord;
use App\Models\Tenant;
use App\Models\TenantModel;
use App\Services\Documents\CanonicalJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialCommandExecutor
{
    public function __construct(private readonly CanonicalJson $canonical) {}

    /**
     * @template T of Model
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(string $tenantId, string $commandType, string $key, mixed $payload, callable $callback): Model
    {
        $key = trim($key);
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,159}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Use an 8-160 character stable command key.']);
        }
        $checksum = $this->canonical->checksum($payload);

        return DB::transaction(function () use ($tenantId, $commandType, $key, $checksum, $callback): Model {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $record = FinancialCommandRecord::query()
                ->where('command_type', $commandType)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($record !== null) {
                if (! hash_equals($record->request_checksum, $checksum)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This command key was already used with a different request body.']);
                }
                $class = $record->result_model;
                if (! is_a($class, TenantModel::class, true)) {
                    throw new \LogicException('Stored financial command result type is invalid.');
                }

                return $class::query()->findOrFail($record->result_id);
            }

            $result = $callback();
            if (! $result instanceof TenantModel) {
                throw new \LogicException('Financial commands must return a tenant-scoped model.');
            }
            FinancialCommandRecord::query()->create([
                'command_type' => $commandType,
                'idempotency_key' => $key,
                'request_checksum' => $checksum,
                'result_model' => $result::class,
                'result_id' => (string) $result->getKey(),
            ]);

            return $result;
        }, 3);
    }
}
