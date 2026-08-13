<?php

namespace App\Services;

use App\Enums\FolioLineType;
use App\Enums\ProposalStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\Proposal;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProposalService
{
    public function __construct(
        private readonly MoneyCalculator $money,
        private readonly OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDraft(array $data, ?int $actorId): Proposal
    {
        return DB::transaction(function () use ($data, $actorId): Proposal {
            $reference = $data['reference'] ?? 'Q-'.Str::upper((string) Str::ulid());

            return Proposal::query()->create([
                ...$this->draftAttributes($data),
                'reference' => $reference,
                'version' => 1,
                'status' => ProposalStatus::Draft,
                'created_by' => $actorId,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Proposal $proposal, array $data): Proposal
    {
        return DB::transaction(function () use ($proposal, $data): Proposal {
            $locked = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->assertDraft($locked);

            $merged = [
                'property_id' => $data['property_id'] ?? $locked->property_id,
                'program_id' => $data['program_id'] ?? data_get($locked->snapshot, 'program_id'),
                'primary_guest_id' => $data['primary_guest_id'] ?? $locked->primary_guest_id,
                'starts_at' => $data['starts_at'] ?? $locked->starts_at,
                'ends_at' => $data['ends_at'] ?? $locked->ends_at,
                'adults' => $data['adults'] ?? $locked->adults,
                'children' => $data['children'] ?? $locked->children,
                'currency' => $data['currency'] ?? $locked->currency,
                'tax_minor' => $data['tax_minor'] ?? $locked->tax_minor,
                'expires_at' => Arr::exists($data, 'expires_at') ? $data['expires_at'] : $locked->expires_at,
                'title' => $data['title'] ?? data_get($locked->snapshot, 'title'),
                'notes' => $data['notes'] ?? data_get($locked->snapshot, 'notes'),
                'lines' => $data['lines'] ?? data_get($locked->snapshot, 'lines', []),
            ];
            $locked->update($this->draftAttributes($merged));

            return $locked->fresh(['property', 'primaryGuest']);
        });
    }

    public function send(Proposal $proposal): Proposal
    {
        return DB::transaction(function () use ($proposal): Proposal {
            $locked = Proposal::query()->with(['property', 'primaryGuest'])->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status === ProposalStatus::Sent) {
                return $locked;
            }

            $this->assertDraft($locked);
            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                throw new DomainException('An expired proposal cannot be sent. Update its expiry first.');
            }

            $snapshot = [
                ...$locked->snapshot,
                'schema_version' => 1,
                'sent_at' => now()->toIso8601String(),
                'property' => [
                    'id' => $locked->property->id,
                    'name' => $locked->property->name,
                    'timezone' => $locked->property->timezone,
                ],
                'guest' => [
                    'id' => $locked->primaryGuest?->id,
                    'name' => $locked->primaryGuest === null ? null : trim("{$locked->primaryGuest->first_name} {$locked->primaryGuest->last_name}"),
                    'email' => $locked->primaryGuest?->email,
                ],
                'stay' => [
                    'starts_at' => $locked->starts_at?->toIso8601String(),
                    'ends_at' => $locked->ends_at?->toIso8601String(),
                    'adults' => $locked->adults,
                    'children' => $locked->children,
                ],
            ];

            $locked->update([
                'snapshot' => $snapshot,
                'status' => ProposalStatus::Sent,
                'sent_at' => now(),
            ]);
            $this->outbox->record('proposal', $locked->id, 'proposal.sent', [
                'proposal_id' => $locked->id,
                'reference' => $locked->reference,
                'guest_id' => $locked->primary_guest_id,
            ]);

            return $locked->fresh(['property', 'primaryGuest']);
        }, 3);
    }

    public function revise(Proposal $proposal, ?int $actorId): Proposal
    {
        return DB::transaction(function () use ($proposal, $actorId): Proposal {
            $locked = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status === ProposalStatus::Accepted) {
                throw new DomainException('An accepted proposal cannot be revised.');
            }

            $latestVersion = Proposal::query()
                ->where('reference', $locked->reference)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->firstOrFail(['version']);
            $version = $latestVersion->version + 1;

            return Proposal::query()->create([
                'reservation_id' => null,
                'reference' => $locked->reference,
                'property_id' => $locked->property_id,
                'primary_guest_id' => $locked->primary_guest_id,
                'starts_at' => $locked->starts_at,
                'ends_at' => $locked->ends_at,
                'adults' => $locked->adults,
                'children' => $locked->children,
                'version' => $version,
                'status' => ProposalStatus::Draft,
                'currency' => $locked->currency,
                'total_minor' => $locked->total_minor,
                'tax_minor' => $locked->tax_minor,
                'snapshot' => Arr::except($locked->snapshot, ['sent_at', 'property', 'guest', 'stay']),
                'expires_at' => $locked->expires_at,
                'created_by' => $actorId,
            ]);
        }, 3);
    }

    public function convertToReservation(Proposal $proposal): Reservation
    {
        return DB::transaction(function () use ($proposal): Reservation {
            $locked = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status === ProposalStatus::Accepted && $locked->reservation_id !== null) {
                return Reservation::query()->findOrFail($locked->reservation_id);
            }
            if ($locked->status !== ProposalStatus::Sent) {
                throw new DomainException('Only a sent proposal can be converted to a reservation.');
            }
            if ($locked->expires_at !== null && $locked->expires_at->isPast()) {
                $locked->update(['status' => ProposalStatus::Expired]);
                throw new DomainException('This proposal has expired. Create a revision before converting it.');
            }

            $reservation = Reservation::query()->create([
                'property_id' => $locked->property_id,
                'program_id' => data_get($locked->snapshot, 'program_id'),
                'primary_guest_id' => $locked->primary_guest_id,
                'confirmation_number' => 'RSV-'.Str::upper((string) Str::ulid()),
                'status' => ReservationStatus::Draft,
                'source' => 'proposal',
                'starts_at' => $locked->starts_at,
                'ends_at' => $locked->ends_at,
                'adults' => $locked->adults,
                'children' => $locked->children,
                'currency' => $locked->currency,
                'subtotal_minor' => (int) data_get($locked->snapshot, 'subtotal_minor', $locked->total_minor - $locked->tax_minor),
                'tax_minor' => $locked->tax_minor,
                'total_minor' => $locked->total_minor,
                'notes' => data_get($locked->snapshot, 'notes'),
            ]);

            foreach (data_get($locked->snapshot, 'lines', []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                app(FolioService::class)->append(
                    reservation: $reservation,
                    type: FolioLineType::Charge,
                    description: (string) ($line['description'] ?? 'Proposal line'),
                    quantityThousandths: (int) ($line['quantity_thousandths'] ?? 1000),
                    unitAmountMinor: (int) ($line['unit_amount_minor'] ?? 0),
                    actorId: null,
                    metadata: [
                        'source' => 'proposal',
                        'proposal_id' => $locked->id,
                        'proposal_reference' => $locked->reference,
                        'proposal_version' => $locked->version,
                        'proposal_line_index' => $index,
                    ],
                    includedInBookedTotal: true,
                );
            }
            if ($locked->tax_minor > 0) {
                app(FolioService::class)->append(
                    reservation: $reservation,
                    type: FolioLineType::Charge,
                    description: 'Proposal tax',
                    quantityThousandths: 1000,
                    unitAmountMinor: 0,
                    actorId: null,
                    metadata: [
                        'source' => 'proposal',
                        'proposal_id' => $locked->id,
                        'proposal_reference' => $locked->reference,
                        'proposal_version' => $locked->version,
                        'proposal_tax' => true,
                    ],
                    taxAmountMinor: $locked->tax_minor,
                    includedInBookedTotal: true,
                );
            }

            $locked->update([
                'reservation_id' => $reservation->id,
                'status' => ProposalStatus::Accepted,
                'accepted_at' => now(),
                'converted_at' => now(),
            ]);
            $this->outbox->record('proposal', $locked->id, 'proposal.accepted', [
                'proposal_id' => $locked->id,
                'reservation_id' => $reservation->id,
                'reference' => $locked->reference,
            ]);

            return $reservation;
        }, 3);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function draftAttributes(array $data): array
    {
        $lines = collect($data['lines'] ?? [])->map(function (array $line): array {
            $quantity = (int) $line['quantity_thousandths'];
            $unitAmount = (int) $line['unit_amount_minor'];

            return [
                'description' => trim($line['description']),
                'quantity_thousandths' => $quantity,
                'unit_amount_minor' => $unitAmount,
                'amount_minor' => $this->money->lineAmount($unitAmount, $quantity),
            ];
        })->values();
        $subtotal = $this->money->sum($lines->pluck('amount_minor'));
        $tax = (int) ($data['tax_minor'] ?? 0);

        return [
            'property_id' => $data['property_id'],
            'primary_guest_id' => $data['primary_guest_id'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'adults' => (int) ($data['adults'] ?? 1),
            'children' => (int) ($data['children'] ?? 0),
            'currency' => strtoupper($data['currency']),
            'total_minor' => $subtotal + $tax,
            'tax_minor' => $tax,
            'snapshot' => [
                'schema_version' => 1,
                'program_id' => $data['program_id'] ?? null,
                'title' => $data['title'] ?? 'Lodge stay proposal',
                'notes' => $data['notes'] ?? null,
                'lines' => $lines->all(),
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $subtotal + $tax,
                'currency' => strtoupper($data['currency']),
            ],
            'expires_at' => $data['expires_at'] ?? null,
        ];
    }

    private function assertDraft(Proposal $proposal): void
    {
        if ($proposal->status !== ProposalStatus::Draft) {
            throw new DomainException('Only draft proposals may be edited. Create a revision instead.');
        }
    }
}
