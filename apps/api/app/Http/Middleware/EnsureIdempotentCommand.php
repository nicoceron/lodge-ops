<?php

namespace App\Http\Middleware;

use App\Models\FinancialCommandRecord;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Services\Documents\CanonicalJson;
use App\Services\Payments\SensitivePaymentDataGuard;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class EnsureIdempotentCommand
{
    public function __construct(
        private readonly CanonicalJson $canonical,
        private readonly SensitivePaymentDataGuard $sensitiveData,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }

        Validator::make(['key' => $key], [
            'key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])->validate();
        $this->sensitiveData->assertSafe($key, 'idempotency_key');

        $command = $request->method().' '.$request->route()->uri();
        $requestHash = $this->requestHash($request);

        $now = now();
        $tenantId = app(TenantContext::class)->id();
        $claim = DB::transaction(function () use ($tenantId, $key, $command, $requestHash, $now): array {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $record = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();
            if ($record === null) {
                $record = IdempotencyKey::query()->create([
                    'id' => (string) Str::uuid(),
                    'key' => $key,
                    'command' => $command,
                    'request_hash' => $requestHash,
                    'expires_at' => $now->copy()->addDay(),
                ]);

                return [$record, false];
            }
            if ($record->command !== $command || $record->request_hash !== $requestHash) {
                throw new ConflictHttpException('This idempotency key was already used for a different command or payload.');
            }
            if ($record->status_code !== null && $record->response_body !== null) {
                return [$record, true];
            }

            $financiallyCommitted = FinancialCommandRecord::query()->where('idempotency_key', $key)->exists();
            $leaseExpired = $record->updated_at?->lte(now()->subSeconds((int) config('front_desk_tenders.idempotency_pending_lease_seconds', 30))) ?? true;
            if (! $financiallyCommitted && ! $leaseExpired) {
                throw new ConflictHttpException('A request with this idempotency key is already in progress.');
            }
            $record->touch();

            return [$record, false];
        }, 3);
        /** @var IdempotencyKey $record */
        [$record, $replay] = $claim;
        if ($replay) {
            return response($record->response_body, $record->status_code, [
                'Content-Type' => 'application/json',
                'Idempotency-Replayed' => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if ($response->isSuccessful()) {
            $record->update([
                'status_code' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }

    private function requestHash(Request $request): string
    {
        return $this->canonical->checksum([
            'path' => '/'.ltrim($request->path(), '/'),
            'query' => $request->query->all(),
            'input' => $request->input(),
            'files' => $this->fileDescriptors($request->allFiles()),
        ]);
    }

    /** @param array<string, mixed> $files @return array<string, mixed> */
    private function fileDescriptors(array $files): array
    {
        $descriptors = [];
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                $descriptors[$name] = $this->fileDescriptors($file);
            } elseif ($file instanceof UploadedFile) {
                $path = $file->getRealPath();
                $descriptors[$name] = [
                    'name' => basename($file->getClientOriginalName()),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'sha256' => is_string($path) ? hash_file('sha256', $path) : false,
                ];
            }
        }

        return $descriptors;
    }
}
