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
}
