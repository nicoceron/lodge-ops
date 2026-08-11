<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Enums\MembershipRole;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Models\Membership;
use App\Services\TeamMemberService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeamMember extends EditRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Membership $membership */
        $membership = $this->record->load('user');
        $data['member_name'] = $membership->user->name;
        $data['member_email'] = $membership->user->email;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Membership $record */
        return app(TeamMemberService::class)->update(
            $record,
            MembershipRole::from($data['role']),
            $data['property_id'] ?? null,
            (bool) $data['is_active'],
        );
    }
}
