<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminFrontendLifecycleTest extends TestCase
{
    public function test_admin_settings_provider_is_registered_before_alpine_starts(): void
    {
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import { registerSettingsModalFeatures } from './features/settings-modal';", $source);
        $this->assertStringContainsString('registerSettingsModalFeatures();', $source);
        $this->assertStringNotContainsString("key: 'settings-modal'", $source);
        $this->assertSame(1, substr_count($source, 'Alpine.start()'));
        $this->assertLessThan(
            strpos($source, 'Alpine.start()'),
            strpos($source, 'registerSettingsModalFeatures();')
        );
    }

    public function test_admin_settings_modal_uses_registered_provider(): void
    {
        $modal = file_get_contents(resource_path('views/components/admin/settings-modal.blade.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('x-data="adminSettings"', $modal);
        $this->assertStringContainsString('<x-admin.settings-modal id="settings-modal"', $layout);
    }

    public function test_sidebar_accessibility_state_is_synchronized_with_focus_safety(): void
    {
        $source = file_get_contents(resource_path('js/features/sidebar.js'));
        $sidebar = file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));

        $this->assertStringContainsString('export function syncSidebarAccessibility()', $source);
        $this->assertStringContainsString('moveFocusOutOfSidebar(sidebar);', $source);
        $this->assertStringContainsString("sidebar.setAttribute('aria-hidden', 'true')", $source);
        $this->assertStringContainsString("sidebar.setAttribute('inert', '')", $source);
        $this->assertStringContainsString("sidebar.removeAttribute('aria-hidden')", $source);
        $this->assertStringContainsString("sidebar.removeAttribute('inert')", $source);
        $this->assertStringContainsString('export function scheduleSidebarAccessibilitySync()', $source);
        $this->assertStringContainsString('requestAnimationFrame', $source);
        $this->assertStringContainsString('const observer = new MutationObserver(() => scheduleSidebarAccessibilitySync())', $source);
        $this->assertStringContainsString("attributeFilter: ['class']", $source);
        $this->assertStringContainsString('sidebarAccessibilityObservers.has(sidebar)', $source);
        $this->assertStringContainsString('sidebarResizeListenerBound', $source);
        $this->assertStringNotContainsString("attributeFilter: ['class', 'aria-hidden', 'style']", $source);
        $this->assertStringNotContainsString('aria-hidden="true"', $sidebar);
    }

    public function test_admin_dashboard_content_uses_normal_grid_flow_below_topbar(): void
    {
        $adminDashboard = file_get_contents(resource_path('views/admin/dashboard.blade.php'));
        $adminLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $adminTopbar = file_get_contents(resource_path('views/components/admin/topbar.blade.php'));
        $pengajarLayout = file_get_contents(resource_path('views/layouts/pengajar/app.blade.php'));
        $pengajarDashboard = file_get_contents(resource_path('views/pengajar/dashboard.blade.php'));
        $waliLayout = file_get_contents(resource_path('views/layouts/wali_kelas/app.blade.php'));
        $waliDashboard = file_get_contents(resource_path('views/wali_kelas/dashboard.blade.php'));

        $this->assertStringContainsString('class="fixed top-0 z-50 w-full bg-white border-b border-gray-200"', $adminTopbar);
        $this->assertStringContainsString('class="p-4 sm:ml-64 min-h-screen bg-white relative"', $adminLayout);
        $this->assertStringContainsString('class="mt-14"', $adminLayout);
        $this->assertStringContainsString('class="mt-16"', $pengajarLayout);
        $this->assertStringContainsString('class="mt-16"', $waliLayout);

        $adminContentStartPixels = 16 + 56 + 8; // layout p-4 + layout mt-14 + dashboard pt-2
        $teacherContentStartPixels = 16 + 64; // layout p-4 + layout mt-16
        $this->assertSame(80, $adminContentStartPixels);
        $this->assertSame($teacherContentStartPixels, $adminContentStartPixels);

        $this->assertStringNotContainsString('flex flex-col lg:flex-row gap-4 mt-14', $adminDashboard);
        $this->assertStringContainsString('data-page="admin-dashboard" data-overall-progress="{{ number_format($overallProgress ?? 0, 2) }}" class="pt-2"', $adminDashboard);
        $this->assertStringContainsString('grid grid-cols-1 gap-4 lg:grid-cols-3', $adminDashboard);
        $this->assertStringContainsString('lg:col-span-2', $adminDashboard);
        $this->assertStringContainsString('lg:col-span-1', $adminDashboard);

        $this->assertStringContainsString('grid grid-cols-1 lg:grid-cols-3 gap-4', $pengajarDashboard);
        $this->assertStringContainsString('grid grid-cols-1 lg:grid-cols-3 gap-4', $waliDashboard);
    }

    public function test_global_navigation_blades_do_not_run_direct_model_queries(): void
    {
        $files = [
            'views/layouts/app.blade.php',
            'views/layouts/pengajar/app.blade.php',
            'views/layouts/wali_kelas/app.blade.php',
            'views/components/admin/sidebar.blade.php',
            'views/components/admin/topbar.blade.php',
            'views/components/guru/role-switcher.blade.php',
            'views/components/pengajar/sidebar.blade.php',
            'views/components/tahun-ajaran-warning.blade.php',
        ];

        $forbiddenSnippets = [
            '\\App\\Models\\',
            '::where(',
            '::first(',
            '::count(',
            '::exists(',
            '::find(',
            'DB::table(',
            'availableRoles(',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(resource_path($file));

            foreach ($forbiddenSnippets as $snippet) {
                $this->assertStringNotContainsString($snippet, $source, "{$file} still contains {$snippet}");
            }
        }
    }
}
