<?php

namespace App\Services\Communications;

use App\Contracts\CommunicationProvider;
use App\Data\CommunicationProviderRequest;
use App\Data\CommunicationProviderResult;
use App\Mail\CommunicationMail;
use Illuminate\Support\Facades\Mail;

final class LaravelMailCommunicationProvider implements CommunicationProvider
{
    public function name(): string
    {
        return 'laravel-mail';
    }

    public function send(CommunicationProviderRequest $request): CommunicationProviderResult
    {
        $attachment = $request->attachments[0] ?? null;
        Mail::to($request->recipient)->send(new CommunicationMail(
            $request->subject,
            $request->text,
            attachmentBytes: isset($attachment['content']) ? base64_decode($attachment['content'], true) ?: null : null,
            attachmentName: $attachment['filename'] ?? null,
        ));

        return new CommunicationProviderResult('local-'.hash('sha256', $request->idempotencyKey));
    }
}
