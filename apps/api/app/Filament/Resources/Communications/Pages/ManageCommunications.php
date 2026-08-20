<?php

namespace App\Filament\Resources\Communications\Pages;

use App\Filament\Resources\Communications\CommunicationResource;
use App\Models\Property;
use App\Models\User;
use App\Services\Communications\CommunicationOperationsService;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageCommunications extends ManageRecords
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_send')->label('Authorized test send')->visible(fn (): bool => app(TenantContext::class)->membership()?->role?->canManageConfiguration() === true)
                ->schema([
                    Select::make('property_id')->options(fn (): array => Property::query()->pluck('name', 'id')->all())->required(),
                    TextInput::make('recipient')->email()->required()->maxLength(254),
                    TextInput::make('subject')->required()->maxLength(200),
                    Textarea::make('body')->required()->maxLength(5000),
                ])->action(function (array $data): void {
                    app(CommunicationOperationsService::class)->testSend(
                        User::query()->findOrFail(auth()->id()),
                        Property::query()->findOrFail($data['property_id']),
                        $data['recipient'], $data['subject'], $data['body'],
                    );
                    Notification::make()->success()->title('Marked test message queued')->send();
                }),
        ];
    }
}
