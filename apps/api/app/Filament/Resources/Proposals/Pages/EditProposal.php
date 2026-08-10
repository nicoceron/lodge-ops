<?php

namespace App\Filament\Resources\Proposals\Pages;

use App\Filament\Resources\Proposals\ProposalFormData;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Proposal;
use App\Services\ProposalService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProposal extends EditRecord
{
    protected static string $resource = ProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return ProposalFormData::fromRecord($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Proposal $record */
        return app(ProposalService::class)->updateDraft($record, ProposalFormData::forService($data));
    }
}
