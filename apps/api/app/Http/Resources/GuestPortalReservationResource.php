<?php

namespace App\Http\Resources;

use App\Enums\AllocationStatus;
use App\Enums\FolioStatus;
use App\Models\Allocation;
use App\Models\GuestPortalAccessToken;
use App\Services\FolioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property FolioStatus $folio_status */
class GuestPortalReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GuestPortalAccessToken $access */
        $access = $request->attributes->get('guest_portal_access');
        $guest = $this->guestsForPortal->firstWhere('id', $access->guest_id);
        $profile = $this->guestPortalProfiles->firstWhere('guest_id', $access->guest_id);
        $document = $this->property->guestPortalDocuments->first();
        $acknowledgement = $document === null
            ? null
            : $this->guestPortalAcknowledgements
                ->where('guest_id', $access->guest_id)
                ->firstWhere('document_id', $document->id);
        $evidence = $this->guestPaymentEvidence->firstWhere('guest_id', $access->guest_id);
        $survey = $this->surveys
            ->where('guest_id', $access->guest_id)
            ->firstWhere('kind', 'post_stay');
        $balanceMinor = max(0, app(FolioService::class)->summary($this->resource)['balance_minor']);
        $stayAssignments = $this->allocations
            ->filter(fn ($allocation): bool => $allocation->status !== AllocationStatus::Released
                && ($allocation->requestedCategory?->counts_as_stay === true || $allocation->resource?->countsAsStay() === true))
            ->map(function (Allocation $allocation): array {
                return [
                    'category' => $allocation->requestedCategoryName(),
                    'instance' => $allocation->assignedInstanceName(),
                    'label' => $allocation->assignmentLabel(),
                ];
            })
            ->values();
        $itinerary = $this->allocations
            ->pluck('serviceOccurrence')
            ->filter()
            ->unique('id')
            ->sortBy('starts_at')
            ->values()
            ->map(fn ($occurrence): array => [
                'day' => $occurrence->starts_at->format('D j'),
                'title' => $occurrence->program->name,
                'starts_at' => $occurrence->starts_at->toIso8601String(),
                'ends_at' => $occurrence->ends_at->toIso8601String(),
                'meeting_point' => $occurrence->meeting_point,
                'detail' => $occurrence->program->description,
                'type' => 'activity',
            ]);

        $preArrivalComplete = $profile !== null;
        $waiverComplete = $acknowledgement !== null;
        $paymentComplete = $balanceMinor === 0;
        $folioFinal = $this->folio_status === FolioStatus::Closed;
        $surveyComplete = $survey !== null;

        return [
            'portal' => [
                'session_expires_at' => $access->session_expires_at?->toIso8601String(),
            ],
            'reservation' => [
                'confirmation_number' => $this->confirmation_number,
                'status' => $this->status->value,
                'starts_at' => $this->starts_at->toIso8601String(),
                'ends_at' => $this->ends_at->toIso8601String(),
                'adults' => $this->adults,
                'children' => $this->children,
                'currency' => $this->currency,
                'property' => [
                    'name' => $this->property->name,
                    'timezone' => $this->property->timezone,
                    'address' => $this->property->address,
                ],
                'guest' => [
                    'preferred_name' => data_get($profile?->profile, 'preferred_name', $guest?->first_name),
                    'email' => data_get($profile?->profile, 'email', $guest?->email),
                    'mobile' => data_get($profile?->profile, 'mobile', $guest?->phone),
                ],
                'stay_assignments' => $stayAssignments,
                'stay_assignment' => $stayAssignments->pluck('label')->join(', ') ?: null,
            ],
            'itinerary' => $itinerary,
            'readiness' => [
                'pre_arrival' => $preArrivalComplete,
                'waiver' => $waiverComplete,
                'payment' => $paymentComplete,
                'folio_final' => $folioFinal,
                'survey' => $surveyComplete,
            ],
            'pre_arrival' => [
                'complete' => $preArrivalComplete,
                'profile' => $profile?->profile,
                'travel' => $profile?->travel,
                'preferences' => $profile?->preferences,
                'consented_at' => $profile?->consented_at?->toIso8601String(),
            ],
            'document' => $document === null ? null : [
                'slug' => $document->slug,
                'title' => $document->title,
                'version' => $document->version,
                'body' => $document->body,
                'body_hash' => $document->body_hash,
                'acknowledged' => $waiverComplete,
                'signature' => $acknowledgement?->signature,
                'acknowledged_at' => $acknowledgement?->acknowledged_at?->toIso8601String(),
            ],
            'payment' => [
                'currency' => $this->currency,
                'balance_minor' => $balanceMinor,
                'evidence' => $evidence === null ? null : [
                    'file_name' => $evidence->file_name,
                    'status' => $evidence->status,
                    'submitted_at' => $evidence->submitted_at->toIso8601String(),
                ],
            ],
            'survey' => [
                'available' => $this->ends_at->isPast(),
                'submitted' => $surveyComplete,
                'responded_at' => $survey?->responded_at?->toIso8601String(),
            ],
        ];
    }
}
