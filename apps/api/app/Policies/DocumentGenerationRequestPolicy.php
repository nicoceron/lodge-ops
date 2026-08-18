<?php

namespace App\Policies;

use App\Enums\DocumentKind;
use App\Models\DocumentGenerationRequest;
use App\Models\Reservation;
use App\Models\User;

class DocumentGenerationRequestPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user) || $this->canViewGuestMoney($user);
    }

    public function view(User $user, DocumentGenerationRequest $request): bool
    {
        return $this->financial($request->kind) ? $this->canViewGuestMoney($user, $request) : $this->canManageReservations($user, $request);
    }

    public function generate(User $user, DocumentKind $kind, Reservation $reservation): bool
    {
        return $this->financial($kind) ? $this->canManageGuestMoney($user, $reservation) : $this->canManageReservations($user, $reservation);
    }

    public function retry(User $user, DocumentGenerationRequest $request): bool
    {
        return $this->financial($request->kind) ? $this->canManageGuestMoney($user, $request) : $this->canManageReservations($user, $request);
    }

    private function financial(DocumentKind $kind): bool
    {
        return in_array($kind, [DocumentKind::FolioStatement, DocumentKind::PaymentReceipt, DocumentKind::RefundReceipt], true);
    }
}
