<?php

namespace App\Http\Middleware;

use App\Enums\DirectBookingErrorCode;
use App\Models\DirectBookingCommandResponse;
use App\Models\DirectBookingPropertySetting;
use App\Models\Tenant;
use App\Services\Documents\CanonicalJson;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDirectBookingIdempotency
{
    public function __construct(private readonly CanonicalJson $canonical) {}

    public function handle(Request $request, Closure $next): Response
    {
        $setting = $request->attributes->get('direct_booking_setting');
        abort_unless($setting instanceof DirectBookingPropertySetting, 404);
        $key = strtolower(trim((string) $request->header('Idempotency-Key')));
        if (! Str::isUuid($key) || strlen($key) !== 36) {
            return $this->error($request, DirectBookingErrorCode::Validation, 'A canonical UUID Idempotency-Key is required.', 422);
        }
        $command = $request->method().' /'.$request->route()->uri();
        $checksum = $this->canonical->checksum([
            'command' => $command,
            'path_parameters' => [
                'property' => $setting->public_slug,
                'order' => $request->route('orderReference'),
            ],
            'input' => $request->except(['turnstile_token', 'evidence']),
            'turnstile_present' => $request->filled('turnstile_token'),
            'file' => $request->hasFile('evidence') ? [
                'sha256' => hash_file('sha256', (string) $request->file('evidence')?->getRealPath()),
                'size' => $request->file('evidence')?->getSize(),
                'mime' => $request->file('evidence')?->getMimeType(),
            ] : null,
        ]);

        [$record, $replay] = DB::transaction(function () use ($setting, $key, $command, $checksum): array {
            Tenant::query()->whereKey($setting->tenant_id)->lockForUpdate()->firstOrFail();
            $record = DirectBookingCommandResponse::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($record !== null) {
                if ($record->command !== $command || ! hash_equals($record->request_checksum, $checksum)) {
                    return [$record, 'conflict'];
                }
                if ($record->status_code !== null && $record->response_body_encrypted !== null) {
                    return [$record, 'replay'];
                }
                if ($record->lease_expires_at->isFuture()) {
                    return [$record, 'pending'];
                }
                $record->forceFill(['lease_expires_at' => now()->addSeconds(45)])->save();

                return [$record, 'claimed'];
            }
            $record = DirectBookingCommandResponse::query()->create([
                'property_id' => $setting->property_id,
                'idempotency_key' => $key,
                'command' => $command,
                'request_checksum' => $checksum,
                'lease_expires_at' => now()->addSeconds(45),
                'expires_at' => now()->addDays(7),
            ]);

            return [$record, 'claimed'];
        }, 3);

        if ($replay === 'conflict') {
            return $this->error($request, DirectBookingErrorCode::IdempotencyConflict, 'This idempotency key was already used for different request facts.', 409);
        }
        if ($replay === 'pending') {
            return $this->error($request, DirectBookingErrorCode::Conflict, 'This booking command is already in progress.', 409);
        }
        if ($replay === 'replay') {
            return response($record->response_body_encrypted, $record->status_code, [
                ...($record->response_headers ?? []),
                'Content-Type' => 'application/json',
                'Idempotency-Replayed' => 'true',
            ]);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $record->delete();
            throw $exception;
        }
        if ($response->isSuccessful()) {
            $record->forceFill([
                'status_code' => $response->getStatusCode(),
                'response_body_encrypted' => $response->getContent(),
                'response_headers' => [
                    'Cache-Control' => (string) $response->headers->get('Cache-Control'),
                    'X-Correlation-ID' => (string) $response->headers->get('X-Correlation-ID'),
                ],
                'lease_expires_at' => now(),
            ])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    private function error(Request $request, DirectBookingErrorCode $code, string $message, int $status): Response
    {
        $correlation = (string) $request->attributes->get('direct_booking_correlation_id', Str::uuid());

        return response()->json(['error' => [
            'code' => $code->value,
            'message' => $message,
            'correlation_id' => $correlation,
            'retryable' => false,
        ]], $status, ['Cache-Control' => 'no-store, private', 'X-Correlation-ID' => $correlation]);
    }
}
