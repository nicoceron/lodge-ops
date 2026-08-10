<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Enums\MembershipRole;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Services\TeamMemberService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeamMember extends CreateRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $membership = app(TeamMemberService::class)->invite(
            $data['member_name'],
            $data['member_email'],
            MembershipRole::from($data['role']),
            $data['property_id'] ?? null,
        );

        Notification::make()->success()->title('Team member invited')->body('New accounts receive a secure password setup link.')->send();

        return $membership;
    }

    protected function getRedirectUrl(): string
    {
        return TeamMemberResource::getUrl('index');
    }
}
