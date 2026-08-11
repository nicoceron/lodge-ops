<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProjectionArchitectureTest extends TestCase
{
    public function test_filament_pages_and_widgets_do_not_invoke_http_controllers_for_projection_data(): void
    {
        foreach ([
            app_path('Filament/Pages/FinanceDashboard.php'),
            app_path('Filament/Pages/MasterCalendar.php'),
            app_path('Filament/Widgets/LodgeCommandCenter.php'),
            app_path('Filament/Pages/OperationsBoard.php'),
        ] as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'App\\Http\\Controllers',
                $source,
                basename($path).' must use an application projection service rather than invoke an HTTP controller.',
            );
        }
    }
}
