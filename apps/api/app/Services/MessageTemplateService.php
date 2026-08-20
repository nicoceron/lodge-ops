<?php

namespace App\Services;

use App\Models\Communication;
use App\Models\CommunicationSuppression;
use App\Models\Guest;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\Reservation;
use App\Services\Automation\AutomationTemplateRenderer;
use App\Services\Automation\OutboxRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

class MessageTemplateService
{
    public function __construct(
        private readonly AutomationTemplateRenderer $renderer,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function createVersion(MessageTemplate $template, string $language, ?string $subject, string $body): MessageTemplateVersion
    {
        return DB::transaction(function () use ($template, $language, $subject, $body): MessageTemplateVersion {
            MessageTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $version = ((int) $template->versions()->max('version')) + 1;

            return $template->versions()->create([
                'version' => $version,
                'language' => $language,
                'subject' => $subject,
                'body' => $body,
            ]);
        });
    }

    public function publish(MessageTemplateVersion $version): MessageTemplateVersion
    {
        if ($version->published_at === null) {
            $version->update(['published_at' => now()]);
        }

        return $version;
    }

    /** @param array<string, mixed> $context */
    public function queue(
        MessageTemplate $template,
        Guest $guest,
        string $language,
        string $idempotencyKey,
        array $context,
        ?Reservation $reservation = null,
    ): Communication {
        $recipient = $this->recipient($guest, $template->channel);
        if ($recipient === null || $recipient === '') {
            throw new DomainException('The guest has no recipient address for this channel.');
        }
        $recipientHash = $this->recipientHash($recipient);
        if ($this->isSuppressed($guest, $template->channel, $reservation?->property_id)) {
            throw new DomainException('Communication to this recipient is suppressed.');
        }

        $version = $template->versions()
            ->where('language', $language)
            ->whereNotNull('published_at')
            ->latest('version')
            ->first() ?? $template->versions()->whereNotNull('published_at')->latest('version')->first();
        if ($version === null) {
            throw new DomainException('No published template version is available.');
        }

        $existing = Communication::query()->where('automation_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $renderedSubject = $this->renderer->render($version->subject, $context);
        $renderedBody = $this->renderer->render($version->body, $context);
        $templateVersion = $version->version;
        $locale = $version->language;

        return DB::transaction(function () use ($template, $guest, $reservation, $idempotencyKey, $context, $recipientHash, $renderedSubject, $renderedBody, $templateVersion, $locale): Communication {
            $communication = Communication::query()->firstOrCreate(
                ['automation_key' => $idempotencyKey],
                [
                    'property_id' => $reservation?->property_id,
                    'guest_id' => $guest->id,
                    'reservation_id' => $reservation?->id,
                    'channel' => $template->channel,
                    'direction' => 'outbound',
                    'purpose' => (string) data_get($context, 'communication_purpose', 'transactional'),
                    'template_key' => $template->key,
                    'template_version' => $templateVersion,
                    'locale' => $locale,
                    'status' => 'queued',
                    'body' => $renderedBody,
                    'content_checksum' => hash('sha256', $renderedSubject."\n".$renderedBody),
                    'subject' => $renderedSubject,
                    'metadata' => [
                        'template_id' => $template->id,
                        'template_version' => $templateVersion,
                        'language' => $locale,
                        'recipient_hash' => $recipientHash,
                    ],
                ],
            );
            if ($communication->wasRecentlyCreated) {
                $this->outbox->record('communication', $communication->id, 'communication.queued', [
                    'communication_id' => $communication->id,
                    'channel' => $communication->channel,
                ]);
            }

            return $communication;
        });
    }

    public function isSuppressed(Guest $guest, string $channel, ?string $propertyId = null): bool
    {
        $recipient = $this->recipient($guest, $channel);
        if ($recipient === null || trim($recipient) === '') {
            return false;
        }

        return CommunicationSuppression::query()
            ->where('channel', $channel)
            ->where('recipient_hash', $this->recipientHash($recipient))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNull('lifted_at')
            ->when($propertyId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('property_id')->orWhere('property_id', $propertyId)))
            ->exists();
    }

    public function recipient(Guest $guest, string $channel): ?string
    {
        return $channel === 'email' ? $guest->email : $guest->phone;
    }

    public function recipientHash(string $recipient): string
    {
        return hash('sha256', mb_strtolower(trim($recipient)));
    }
}
