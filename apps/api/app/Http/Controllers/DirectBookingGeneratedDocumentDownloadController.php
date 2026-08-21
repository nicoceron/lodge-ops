<?php

namespace App\Http\Controllers;

use App\Enums\DirectBookingErrorCode;
use App\Http\Responses\DirectBookingErrorResponse;
use App\Models\Audit;
use App\Models\DirectBookingPropertySetting;
use App\Services\DirectBooking\DirectBookingApiService;
use App\Services\Documents\DocumentArtifactStore;
use DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class DirectBookingGeneratedDocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        string $propertySlug,
        string $orderReference,
        string $documentReference,
        DirectBookingApiService $api,
        DocumentArtifactStore $artifacts,
    ): Response {
        $correlation = $request->header('X-Correlation-ID');
        if (! is_string($correlation) || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $correlation) !== 1) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('direct_booking_correlation_id', $correlation);
        $setting = $request->attributes->get('direct_booking_setting');
        if (! $setting instanceof DirectBookingPropertySetting) {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::NotFound);
        }

        try {
            $order = $api->resolveOrder($setting, $orderReference, $request->bearerToken());
            $document = $api->confirmationDocument($setting, $order, $documentReference);
            $bytes = $artifacts->verifiedBytes($document->storage_disk, $document->storage_path, $document->checksum);
            Audit::query()->create([
                'actor_id' => null,
                'event' => 'direct_booking_document_downloaded',
                'auditable_type' => $document->getMorphClass(),
                'auditable_id' => $document->id,
                'new_values' => ['channel' => 'direct_booking', 'order_reference' => $order->public_reference],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.addcslashes($document->file_name, '"\\').'"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-Correlation-ID' => $correlation,
            ]);
        } catch (AuthenticationException) {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::NotFound);
        } catch (DomainException) {
            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::BookingUnavailable);
        } catch (Throwable $exception) {
            report($exception);

            return DirectBookingErrorResponse::make($request, DirectBookingErrorCode::BookingUnavailable);
        }
    }
}
