<?php

namespace App\Contracts;

use App\Data\CommunicationProviderRequest;
use App\Data\CommunicationProviderResult;

interface CommunicationProvider
{
    public function name(): string;

    public function send(CommunicationProviderRequest $request): CommunicationProviderResult;
}
