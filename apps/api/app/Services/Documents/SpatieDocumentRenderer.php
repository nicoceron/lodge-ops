<?php

namespace App\Services\Documents;

use App\Contracts\Documents\DocumentRenderer;
use App\Enums\DocumentKind;
use Composer\InstalledVersions;
use Spatie\LaravelPdf\Drivers\PdfDriver;
use Spatie\LaravelPdf\PdfOptions;

final class SpatieDocumentRenderer implements DocumentRenderer
{
    public function __construct(private readonly PdfDriver $driver) {}

    public function render(DocumentKind $kind, array $snapshot): string
    {
        $html = view('documents.'.$kind->value, ['snapshot' => $snapshot])->render();

        return $this->driver->generatePdf($html, null, null, new PdfOptions);
    }

    public function name(): string
    {
        return 'spatie-laravel-pdf:'.config('laravel-pdf.driver');
    }

    public function version(): string
    {
        return InstalledVersions::getPrettyVersion('spatie/laravel-pdf') ?? 'unknown';
    }
}
