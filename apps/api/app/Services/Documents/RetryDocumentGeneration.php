<?php

namespace App\Services\Documents;

use App\Enums\DocumentGenerationStatus;
use App\Jobs\GenerateDocument;
use App\Models\DocumentGenerationRequest;
use App\Models\User;
use DomainException;

final class RetryDocumentGeneration
{
    public function handle(User $actor, DocumentGenerationRequest $request): DocumentGenerationRequest
    {
        $actor->can('retry', $request) || abort(403);
        if ($request->status !== DocumentGenerationStatus::Failed) {
            throw new DomainException('Only failed document requests can be retried.');
        }
        $request->forceFill(['status' => DocumentGenerationStatus::Pending, 'failed_at' => null, 'last_error' => null])->save();
        GenerateDocument::dispatch($request->id);

        return $request->refresh();
    }
}
