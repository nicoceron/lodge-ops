<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestPortal\AcknowledgeDocumentRequest;
use App\Http\Requests\GuestPortal\ExchangeGuestPortalTokenRequest;
use App\Http\Requests\GuestPortal\StoreGuestSurveyRequest;
use App\Http\Requests\GuestPortal\StorePaymentEvidenceRequest;
use App\Http\Requests\GuestPortal\UpdatePreArrivalRequest;
use App\Http\Resources\GuestPortalFolioResource;
use App\Http\Resources\GuestPortalReservationResource;
use App\Models\GuestPaymentEvidence;
use App\Models\GuestPortalAccessToken;
use App\Models\GuestPortalAcknowledgement;
use App\Models\GuestPortalDocument;
use App\Models\GuestPortalProfile;
use App\Models\Reservation;
use App\Models\Survey;
use App\Services\GuestPortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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

    public function show(Request $request): GuestPortalReservationResource
    {
        return new GuestPortalReservationResource($this->reservation($request));
    }

    public function updatePreArrival(UpdatePreArrivalRequest $request): GuestPortalReservationResource
    {
        $access = $this->access($request);
        $data = $request->validated();

        GuestPortalProfile::query()->updateOrCreate(
            ['reservation_id' => $access->reservation_id, 'guest_id' => $access->guest_id],
            [
                'profile' => $data['profile'],
                'travel' => $data['travel'],
                'preferences' => $data['preferences'],
                'consented_at' => now(),
            ],
        );

        return new GuestPortalReservationResource($this->reservation($request));
    }

    public function acknowledge(AcknowledgeDocumentRequest $request): JsonResponse
    {
        $access = $this->access($request);
        $reservation = $this->reservation($request);
        $data = $request->validated();
        $document = GuestPortalDocument::query()
            ->where('property_id', $reservation->property_id)
            ->where('slug', $data['document_slug'])
            ->where('version', $data['document_version'])
            ->where('body_hash', $data['document_hash'])
            ->where('is_active', true)
            ->first();

        if ($document === null) {
            return response()->json(['message' => 'This document version is unavailable.'], 409);
        }

        if (GuestPortalAcknowledgement::query()
            ->where('reservation_id', $access->reservation_id)
            ->where('guest_id', $access->guest_id)
            ->where('document_id', $document->id)
            ->exists()) {
            return response()->json(['message' => 'This document has already been acknowledged.'], 409);
        }

        GuestPortalAcknowledgement::query()->create([
            'reservation_id' => $access->reservation_id,
            'guest_id' => $access->guest_id,
            'document_id' => $document->id,
            'signature' => $data['signature'],
            'document_hash' => $document->body_hash,
            'acknowledged_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
        ]);

        return response()->json(['data' => ['acknowledged' => true, 'acknowledged_at' => now()->toIso8601String()]], 201);
    }

    public function storePaymentEvidence(StorePaymentEvidenceRequest $request): JsonResponse
    {
        $access = $this->access($request);
        $upload = $request->file('evidence');
        $realPath = $upload->getRealPath();
        $contentType = (new \finfo(FILEINFO_MIME_TYPE))->file($realPath);
        $sizeBytes = filesize($realPath);

        if (! is_string($contentType) || ! is_int($sizeBytes) || $sizeBytes < 1 || $sizeBytes > 10 * 1024 * 1024) {
            abort(422, 'Invalid evidence file.');
        }

        $extension = match ($contentType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => abort(422, 'Unsupported evidence type.'),
        };
        $directory = "guest-payment-evidence/{$access->tenant_id}/{$access->reservation_id}";
        $storagePath = Storage::disk('local')->putFileAs($directory, $upload, Str::uuid().'.'.$extension);

        if (! is_string($storagePath)) {
            return response()->json(['message' => 'Unable to store payment evidence.'], 503);
        }

        try {
            $evidence = GuestPaymentEvidence::query()->create([
                'reservation_id' => $access->reservation_id,
                'guest_id' => $access->guest_id,
                'file_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
                'content_type' => $contentType,
                'size_bytes' => $sizeBytes,
                'sha256' => hash_file('sha256', $realPath),
                'storage_path' => $storagePath,
                'status' => 'review_pending',
                'submitted_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storagePath);
            throw $exception;
        }

        return response()->json([
            'data' => [
                'file_name' => $evidence->file_name,
                'status' => $evidence->status,
                'submitted_at' => $evidence->submitted_at->toIso8601String(),
            ],
        ], 201);
    }

    public function folio(Request $request): GuestPortalFolioResource
    {
        $reservation = $this->reservation($request);
        $reservation->load('folioLines');

        return new GuestPortalFolioResource($reservation);
    }

    public function storeSurvey(StoreGuestSurveyRequest $request): JsonResponse
    {
        $access = $this->access($request);
        $reservation = $this->reservation($request);

        if ($reservation->ends_at->isFuture()) {
            return response()->json(['message' => 'Feedback opens after departure.'], 409);
        }

        if (Survey::query()
            ->where('reservation_id', $access->reservation_id)
            ->where('guest_id', $access->guest_id)
            ->where('kind', 'post_stay')
            ->exists()) {
            return response()->json(['message' => 'Feedback has already been submitted.'], 409);
        }

        $data = $request->validated();
        $survey = Survey::query()->create([
            'reservation_id' => $access->reservation_id,
            'guest_id' => $access->guest_id,
            'kind' => 'post_stay',
            'score' => $data['stay_rating'],
            'answers' => $data,
            'responded_at' => now(),
        ]);

        return response()->json(['data' => ['submitted' => true, 'responded_at' => $survey->responded_at->toIso8601String()]], 201);
    }

    private function access(Request $request): GuestPortalAccessToken
    {
        $access = $request->attributes->get('guest_portal_access');

        abort_unless($access instanceof GuestPortalAccessToken, 401);

        return $access;
    }

    private function reservation(Request $request): Reservation
    {
        $access = $this->access($request);
        $reservation = Reservation::query()
            ->whereKey($access->reservation_id)
            ->where(function ($query) use ($access): void {
                $query->where('primary_guest_id', $access->guest_id)
                    ->orWhereHas('guests', fn ($guestQuery) => $guestQuery->whereKey($access->guest_id));
            })
            ->firstOrFail();

        $reservation->load([
            'property.guestPortalDocuments' => fn ($query) => $query->where('is_active', true)->latest('created_at'),
            'allocations.resource',
            'allocations.serviceOccurrence.program',
            'guestPortalProfiles' => fn ($query) => $query->where('guest_id', $access->guest_id),
            'guestPortalAcknowledgements' => fn ($query) => $query->where('guest_id', $access->guest_id),
            'guestPaymentEvidence' => fn ($query) => $query->where('guest_id', $access->guest_id)->latest('submitted_at'),
            'payments',
            'surveys' => fn ($query) => $query->where('guest_id', $access->guest_id)->where('kind', 'post_stay'),
        ]);

        $guests = $reservation->guests()->whereKey($access->guest_id)->get();
        if ($reservation->primary_guest_id === $access->guest_id && $reservation->primaryGuest !== null) {
            $guests->prepend($reservation->primaryGuest);
        }
        $reservation->setRelation('guestsForPortal', $guests->unique('id')->values());

        return $reservation;
    }
}
