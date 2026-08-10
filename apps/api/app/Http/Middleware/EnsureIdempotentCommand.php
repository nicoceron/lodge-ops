<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class EnsureIdempotentCommand
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $next($request);
        }

        Validator::make(['key' => $key], [
            'key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ])->validate();

        $command = $request->method().' '.$request->route()->uri();
        $requestHash = hash('sha256', $request->getContent());

        $now = now();
        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => app(TenantContext::class)->id(),
            'key' => $key,
            'command' => $command,
            'request_hash' => $requestHash,
            'expires_at' => $now->copy()->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = IdempotencyKey::query()->where('key', $key)->firstOrFail();
        if ($inserted === 0) {
            $existing = $record;
            if ($existing->command !== $command || $existing->request_hash !== $requestHash) {
                throw new ConflictHttpException('This idempotency key was already used for a different command or payload.');
            }
            if ($existing->status_code === null || $existing->response_body === null) {
                throw new ConflictHttpException('A request with this idempotency key is already in progress.');
            }

            return response($existing->response_body, $existing->status_code, [
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
}
