<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\GeneratedDocument;
use App\Models\GuestPortalAccessToken;
use App\Services\Documents\DocumentArtifactStore;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuestGeneratedDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, string $generatedDocument, DocumentArtifactStore $artifacts): Response
    {
        $access = $request->attributes->get('guest_portal_access');
        $generatedDocument = GeneratedDocument::query()->findOrFail($generatedDocument);
        abort_unless($access instanceof GuestPortalAccessToken && $generatedDocument->reservation_id === $access->reservation_id, 404);
        $financial = in_array($generatedDocument->kind, ['folio_statement', 'payment_receipt', 'refund_receipt'], true);
        abort_if($financial && ! (bool) data_get($access->reservation->property->settings, 'guest_documents.financial_visible', false), 404);
        abort_if($generatedDocument->expires_at?->isPast() || $generatedDocument->purged_at !== null, 410);
        try {
            $bytes = $artifacts->verifiedBytes($generatedDocument->storage_disk, $generatedDocument->storage_path, $generatedDocument->checksum);
        } catch (DomainException) {
            abort(503, 'The document is temporarily unavailable.');
        }
        Audit::query()->create(['actor_id' => null, 'event' => 'document_downloaded', 'auditable_type' => $generatedDocument->getMorphClass(), 'auditable_id' => $generatedDocument->id, 'new_values' => ['channel' => 'guest', 'guest_id' => $access->guest_id], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.addcslashes($generatedDocument->file_name, '"\\').'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
