<?php

namespace Tests\Feature;

use Tests\TestCase;

class SingleAppArchitectureTest extends TestCase
{
    public function test_next_is_public_only_and_filament_is_the_authenticated_application(): void
    {
        $repository = dirname(base_path(), 2);

        $this->assertDirectoryExists($repository.'/apps/web');
        $this->assertFileExists($repository.'/apps/web/package.json');
        $this->assertFileExists($repository.'/docker/web.Dockerfile');
        $this->assertFileDoesNotExist($repository.'/apps/web/src/app/login/page.tsx');
        $this->assertFileDoesNotExist($repository.'/apps/web/src/app/reservations/page.tsx');
        $this->assertFileDoesNotExist($repository.'/apps/web/src/app/guest/stay/page.tsx');
        $this->assertFileDoesNotExist($repository.'/apps/web/src/app/staff/api/[...path]/route.ts');

        $compose = file_get_contents($repository.'/compose.yml');
        $makefile = file_get_contents($repository.'/Makefile');
        $workflow = file_get_contents($repository.'/.github/workflows/ci.yml');
        $panelProvider = file_get_contents(base_path('app/Providers/Filament/AdminPanelProvider.php'));
        $viteConfig = file_get_contents(base_path('vite.config.js'));
        $apiDockerfile = file_get_contents($repository.'/docker/api.Dockerfile');
        $guestWebController = file_get_contents(base_path('app/Http/Controllers/Web/GuestPortalController.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $openApi = file_get_contents($repository.'/contracts/openapi.yaml');
        $appConfig = file_get_contents(base_path('config/app.php'));
        $sanctumConfig = file_get_contents(base_path('config/sanctum.php'));
        $corsConfig = file_get_contents(base_path('config/cors.php'));
        $environmentExample = file_get_contents(base_path('.env.example'));

        $this->assertIsString($compose);
        $this->assertStringContainsString('docker/web.Dockerfile', $compose);
        $this->assertStringContainsString('php artisan serve --host=0.0.0.0 --port=8000 --no-reload', $compose);
        $this->assertStringNotContainsString('php -S 0.0.0.0:8000 -t public public/index.php', $compose);
        $this->assertStringNotContainsString('API_INTERNAL_URL', $compose);
        $this->assertStringNotContainsString('NEXT_PUBLIC_API_URL', $compose);
        $this->assertStringNotContainsString('SESSION_COOKIE_NAME', $compose);
        $this->assertStringNotContainsString('APP_FRONTEND_URL', $compose);
        $this->assertStringNotContainsString('SANCTUM_STATEFUL_DOMAINS', $compose);
        $this->assertStringNotContainsString('FRONTEND_URLS', $compose);
        $this->assertIsString($makefile);
        $this->assertStringContainsString('apps/web', $makefile);
        $this->assertStringContainsString('/css/filament/filament/app.css', $makefile);
        $this->assertStringContainsString('/js/filament/filament/app.js', $makefile);
        $this->assertIsString($workflow);
        $this->assertStringContainsString('apps/web', $workflow);
        $this->assertFileExists(base_path('resources/css/filament/admin/theme.css'));
        $this->assertIsString($panelProvider);
        $this->assertStringContainsString("->viteTheme('resources/css/filament/admin/theme.css')", $panelProvider);
        $this->assertStringNotContainsString('AccountWidget::class', $panelProvider);
        $this->assertStringNotContainsString('FilamentInfoWidget::class', $panelProvider);
        $this->assertIsString($viteConfig);
        $this->assertStringContainsString("'resources/css/filament/admin/theme.css'", $viteConfig);
        $this->assertIsString($apiDockerfile);
        $this->assertStringContainsString('npm ci', $apiDockerfile);
        $this->assertStringContainsString('npm run build', $apiDockerfile);
        $this->assertStringContainsString('/opt/lodgeops-public-build', $apiDockerfile);
        $this->assertIsString($guestWebController);
        $this->assertStringContainsString('GuestPortalService', $guestWebController);
        $this->assertStringNotContainsString('Controllers\\Api', $guestWebController);
        $this->assertIsString($bootstrap);
        $this->assertStringNotContainsString('statefulApi()', $bootstrap);
        $this->assertIsString($openApi);
        $this->assertStringContainsString('bearerFormat: Sanctum personal access token', $openApi);
        $this->assertStringNotContainsString('cookieAuth:', $openApi);
        $this->assertIsString($appConfig);
        $this->assertStringNotContainsString('frontend_url', $appConfig);
        $this->assertIsString($sanctumConfig);
        $this->assertStringNotContainsString("'stateful'", $sanctumConfig);
        $this->assertStringNotContainsString("'middleware'", $sanctumConfig);
        $this->assertIsString($corsConfig);
        $this->assertStringNotContainsString('sanctum/csrf-cookie', $corsConfig);
        $this->assertStringContainsString("'supports_credentials' => false", $corsConfig);
        $this->assertIsString($environmentExample);
        $this->assertStringNotContainsString('SANCTUM_STATEFUL_DOMAINS', $environmentExample);
        $this->assertStringNotContainsString('FRONTEND_URLS', $environmentExample);
        $this->assertStringContainsString('build-api:', $makefile);
        $this->assertStringContainsString('cache-dependency-path: apps/api/package-lock.json', $workflow);
    }

    public function test_retired_staff_json_auth_endpoints_are_not_registered(): void
    {
        $this->postJson('/api/v1/auth/login')->assertNotFound();
        $this->getJson('/api/v1/auth/me')->assertNotFound();
        $this->postJson('/api/v1/auth/logout')->assertNotFound();
        $this->postJson('/api/v1/auth/forgot-password')->assertNotFound();
        $this->postJson('/api/v1/auth/reset-password')->assertNotFound();
    }
}
