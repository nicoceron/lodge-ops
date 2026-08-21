<?php

namespace App\Http\Middleware;

use App\Enums\DirectBookingErrorCode;
use App\Http\Responses\DirectBookingErrorResponse;
use App\Models\DirectBookingCommandResponse;
use App\Models\DirectBookingOrder;
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
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::Validation);
        }
        $correlation = $request->header('X-Correlation-ID');
        if (! is_string($correlation) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlation) !== 1) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('direct_booking_correlation_id', $correlation);
        $bearer = $request->bearerToken();
        $requestTokenHash = is_string($bearer) && $bearer !== '' ? hash('sha256', $bearer) : null;
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

        [$record, $replay] = DB::transaction(function () use ($setting, $key, $command, $checksum, $requestTokenHash): array {
            Tenant::query()->whereKey($setting->tenant_id)->lockForUpdate()->firstOrFail();
            $record = DirectBookingCommandResponse::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($record !== null) {
                if ($record->command !== $command || ! hash_equals($record->request_checksum, $checksum)
                    || $record->request_token_hash !== $requestTokenHash) {
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
                'request_token_hash' => $requestTokenHash,
                'lease_expires_at' => now()->addSeconds(45),
                'expires_at' => now()->addDays(7),
            ]);

            return [$record, 'claimed'];
        }, 3);

        if ($replay === 'conflict') {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::IdempotencyConflict);
        }
        if ($replay === 'pending') {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::Conflict);
        }
        if ($replay === 'replay') {
            if (! $this->replaySessionIsCurrent($record, $requestTokenHash)) {
                return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::NotFound);
            }

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
            $record->refresh();
            if ($record->status_code === null) {
                $record->forceFill([
                    'status_code' => $response->getStatusCode(),
                    'response_body_encrypted' => $response->getContent(),
                    'response_headers' => [
                        'Cache-Control' => (string) $response->headers->get('Cache-Control'),
                        'X-Correlation-ID' => (string) $response->headers->get('X-Correlation-ID'),
                    ],
                    'lease_expires_at' => now(),
                ])->save();
            }
        } else {
            $record->delete();
        }

        return $response;
    }

    private function replaySessionIsCurrent(DirectBookingCommandResponse $record, ?string $requestTokenHash): bool
    {
        if ($record->direct_booking_order_id === null || $record->response_token_hash === null) {
            return false;
        }
        $order = DirectBookingOrder::query()->whereKey($record->direct_booking_order_id)->first();
        if ($order === null || $order->revoked_at !== null || $order->session_expires_at->isPast()
            || ! hash_equals($record->response_token_hash, $order->token_hash)) {
            return false;
        }
        if ($requestTokenHash === null) {
            return $record->request_token_hash === null;
        }
        if ($record->request_token_hash === null || ! hash_equals($record->request_token_hash, $requestTokenHash)) {
            return false;
        }
        if (! str_ends_with($record->command, '/recover')) {
            return hash_equals($requestTokenHash, $order->token_hash);
        }

        return true;
    }
}
