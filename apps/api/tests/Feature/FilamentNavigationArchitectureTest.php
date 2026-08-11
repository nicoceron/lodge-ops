<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FilamentNavigationArchitectureTest extends TestCase
{
    public function test_financial_navigation_uses_one_consistent_group(): void
    {
        $groups = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))))
            ->filter(fn (\SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
            ->flatMap(function (\SplFileInfo $file): array {
                $source = file_get_contents($file->getPathname());
                preg_match_all("/navigationGroup = '([^']*Finance[^']*)'/", is_string($source) ? $source : '', $matches);

                return $matches[1];
            })
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['Finance'], $groups);
    }
}
