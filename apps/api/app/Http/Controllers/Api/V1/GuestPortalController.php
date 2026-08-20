<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CommunicationPurpose;
use App\Exceptions\GuestPortalStorageException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuestPortal\AcknowledgeDocumentRequest;
use App\Http\Requests\GuestPortal\ExchangeGuestPortalTokenRequest;
use App\Http\Requests\GuestPortal\StoreGuestSurveyRequest;
use App\Http\Requests\GuestPortal\StorePaymentEvidenceRequest;
use App\Http\Requests\GuestPortal\UpdatePreArrivalRequest;
use App\Http\Resources\GuestPortalFolioResource;
use App\Http\Resources\GuestPortalReservationResource;
use App\Models\CommunicationPreference;
use App\Models\GuestPortalAccessToken;
use App\Services\Communications\CommunicationPreferenceService;
use App\Services\GuestPortalService;
use App\Services\GuestPortalTokenService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class GuestPortalController extends Controller
{
    public function exchange(ExchangeGuestPortalTokenRequest $request, GuestPortalTokenService $tokens): JsonResponse
    {
        $exchange = $tokens->exchange($request->validated('token'));

        return response()->json([
            'data' => [
                'access_token' => $exchange['token'],
                'token_type' => 'Bearer',
                'expires_at' => $exchange['access']->session_expires_at->toIso8601String(),
            ],
        ]);
    }

    public function show(Request $request, GuestPortalService $portal): GuestPortalReservationResource
    {
        return new GuestPortalReservationResource($portal->reservation($this->access($request)));
    }

    public function updatePreArrival(
        UpdatePreArrivalRequest $request,
        GuestPortalService $portal,
    ): GuestPortalReservationResource {
        return new GuestPortalReservationResource(
            $portal->updatePreArrival($this->access($request), $request->validated()),
        );
    }

    public function acknowledge(AcknowledgeDocumentRequest $request, GuestPortalService $portal): JsonResponse
    {
        try {
            $result = $portal->acknowledge(
                $this->access($request),
                $request->validated(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $result], 201);
    }

    public function storePaymentEvidence(
        StorePaymentEvidenceRequest $request,
        GuestPortalService $portal,
    ): JsonResponse {
        $upload = $request->file('evidence');
        abort_unless($upload instanceof UploadedFile, 422, 'Invalid evidence file.');

        try {
            $result = $portal->storePaymentEvidence($this->access($request), $upload, $request->validated());
        } catch (GuestPortalStorageException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $result], 201);
    }

    public function folio(Request $request, GuestPortalService $portal): GuestPortalFolioResource
    {
        return new GuestPortalFolioResource($portal->folio($this->access($request)));
    }

    public function storeSurvey(StoreGuestSurveyRequest $request, GuestPortalService $portal): JsonResponse
    {
        try {
            $result = $portal->storeSurvey($this->access($request), $request->validated());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $result], 201);
    }

    public function communicationPreferences(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $access->loadMissing('reservation');
        $records = CommunicationPreference::query()
            ->where('guest_id', $access->guest_id)->where('channel', 'email')
            ->whereIn('purpose', [CommunicationPurpose::Survey->value, CommunicationPurpose::Marketing->value])
            ->where(fn ($query) => $query->whereNull('property_id')->orWhere('property_id', $access->reservation->property_id))
            ->get()->keyBy('purpose');

        return response()->json(['data' => collect([CommunicationPurpose::Survey, CommunicationPurpose::Marketing])
            ->map(function (CommunicationPurpose $purpose) use ($records): array {
                $record = $records->get($purpose->value);

                return [
                    'purpose' => $purpose->value,
                    'is_allowed' => $record instanceof CommunicationPreference
                        && $record->is_allowed === true
                        && $record->withdrawn_at === null,
                ];
            })->all()]);
    }

    public function updateCommunicationPreference(Request $request, CommunicationPreferenceService $preferences): JsonResponse
    {
        $data = $request->validate(['purpose' => ['required', 'in:survey,marketing'], 'is_allowed' => ['required', 'boolean']]);
        $access = $this->access($request);
        $access->loadMissing(['guest', 'reservation.property']);
        $preference = $preferences->record(
            $access->guest,
            CommunicationPurpose::from($data['purpose']),
            (bool) $data['is_allowed'],
            'guest_portal_api',
            $access->reservation->property,
        );

        return response()->json(['data' => [
            'purpose' => $preference->purpose,
            'is_allowed' => $preference->is_allowed && $preference->withdrawn_at === null,
            'recorded_at' => $preference->recorded_at->toIso8601String(),
        ]]);
    }

    private function access(Request $request): GuestPortalAccessToken
    {
        $access = $request->attributes->get('guest_portal_access');

        abort_unless($access instanceof GuestPortalAccessToken, 401);

        return $access;
    }
}
