<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\GeneratedDocument;
use App\Services\Documents\DocumentArtifactStore;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GeneratedDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, DocumentArtifactStore $artifacts): Response
    {
        $generatedDocument = GeneratedDocument::query()->findOrFail((string) $request->route('generatedDocument'));
        $this->authorize('download', $generatedDocument);
        abort_if($generatedDocument->expires_at?->isPast() || $generatedDocument->purged_at !== null, 410, 'This document is no longer available.');
        try {
            $bytes = $artifacts->verifiedBytes($generatedDocument->storage_disk, $generatedDocument->storage_path, $generatedDocument->checksum);
        } catch (DomainException) {
            abort(503, 'The document is temporarily unavailable.');
        }
        Audit::query()->create(['actor_id' => auth()->id(), 'event' => 'document_downloaded', 'auditable_type' => $generatedDocument->getMorphClass(), 'auditable_id' => $generatedDocument->id, 'new_values' => ['channel' => 'staff'], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($generatedDocument->file_name, '"\\').'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
