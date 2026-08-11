<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
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

    public function test_secondary_navigation_groups_start_collapsed_and_are_ordered_last(): void
    {
        $groups = Filament::getPanel('admin')->getNavigationGroups();

        $this->assertSame([
            'Commercial',
            'Operations',
            'Sales & CRM',
            'Finance',
            'Guest experience',
            'Retail & Stock',
            'Setup',
            'Templates & Integrations',
        ], array_map(fn ($group): ?string => $group->getLabel(), $groups));
        $this->assertFalse($groups[0]->isCollapsed());
        $this->assertTrue($groups[6]->isCollapsed());
        $this->assertTrue($groups[7]->isCollapsed());
    }
}
