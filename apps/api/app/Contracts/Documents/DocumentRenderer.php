<?php

namespace App\Contracts\Documents;

use App\Enums\DocumentKind;

interface DocumentRenderer
{
    /** @param array<string, mixed> $snapshot */
    public function render(DocumentKind $kind, array $snapshot): string;

    public function name(): string;

    public function version(): string;
}
