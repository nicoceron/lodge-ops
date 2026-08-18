<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;

class GeneratedDocumentPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageReservations($user) || $this->canViewGuestMoney($user);
    }

    public function view(User $user, GeneratedDocument $document): bool
    {
        return $this->financial($document) ? $this->canViewGuestMoney($user, $document) : $this->canManageReservations($user, $document);
    }

    public function download(User $user, GeneratedDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function email(User $user, GeneratedDocument $document): bool
    {
        return $this->financial($document) ? $this->canManageGuestMoney($user, $document) : $this->canManageReservations($user, $document);
    }

    private function financial(GeneratedDocument $document): bool
    {
        return in_array($document->kind, ['folio_statement', 'payment_receipt', 'refund_receipt'], true);
    }
}
