<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentGenerationRequestResource;
use App\Http\Resources\GeneratedDocumentResource;
use App\Models\DocumentGenerationRequest;
use App\Models\GeneratedDocument;
use App\Models\GuestPortalAcknowledgement;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Services\Documents\QueueGeneratedDocumentEmail;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\Documents\RetryDocumentGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    public function store(Request $request, Reservation $reservation, RequestDocumentGeneration $command): DocumentGenerationRequestResource
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(DocumentKind::class)], 'locale' => ['sometimes', 'string', 'max:12'],
            'payment_id' => ['nullable', 'uuid'], 'reservation_change_id' => ['nullable', 'uuid'], 'acknowledgement_id' => ['nullable', 'uuid'], 'replaces_document_id' => ['nullable', 'uuid'],
        ]);
        $result = $command->handle(
            $request->user(), $reservation, DocumentKind::from($data['kind']), $data['locale'] ?? 'en',
            $request->header('Idempotency-Key', hash('sha256', json_encode($data))),
            isset($data['payment_id']) ? Payment::query()->findOrFail($data['payment_id']) : null,
            isset($data['reservation_change_id']) ? ReservationChange::query()->findOrFail($data['reservation_change_id']) : null,
            isset($data['acknowledgement_id']) ? GuestPortalAcknowledgement::query()->findOrFail($data['acknowledgement_id']) : null,
            isset($data['replaces_document_id']) ? GeneratedDocument::query()->findOrFail($data['replaces_document_id']) : null,
        );

        return new DocumentGenerationRequestResource($result->load('generatedDocument'));
    }

    public function request(DocumentGenerationRequest $documentGenerationRequest): DocumentGenerationRequestResource
    {
        $this->authorize('view', $documentGenerationRequest);

        return new DocumentGenerationRequestResource($documentGenerationRequest->load('generatedDocument'));
    }

    public function retry(Request $request, DocumentGenerationRequest $documentGenerationRequest, RetryDocumentGeneration $command): DocumentGenerationRequestResource
    {
        return new DocumentGenerationRequestResource($command->handle($request->user(), $documentGenerationRequest));
    }

    public function show(GeneratedDocument $generatedDocument): GeneratedDocumentResource
    {
        $this->authorize('view', $generatedDocument);

        return new GeneratedDocumentResource($generatedDocument);
    }

    public function email(Request $request, GeneratedDocument $generatedDocument, QueueGeneratedDocumentEmail $command): JsonResponse
    {
        $data = $request->validate(['recipient' => ['nullable', 'email', 'max:255']]);
        $communication = $command->handle($request->user(), $generatedDocument, $request->header('Idempotency-Key', $generatedDocument->id), $data['recipient'] ?? null);

        return response()->json(['data' => ['communication_id' => $communication->id, 'status' => $communication->status]], 202);
    }
}
