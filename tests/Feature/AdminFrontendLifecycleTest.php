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

    public function test_admin_settings_modal_lazy_loads_and_deduplicates_data_requests(): void
    {
        $source = file_get_contents(resource_path('js/features/settings-modal.js'));
        $modal = file_get_contents(resource_path('views/components/admin/settings-modal.blade.php'));

        $initBlock = $this->sourceBlock($source, 'init() {', 'getFilteredKkmList()');
        $openBlock = $this->sourceBlock($source, 'open() {', 'close()');

        $this->assertStringNotContainsString('fetchKelasData', $initBlock);
        $this->assertStringNotContainsString('fetchKkmList', $initBlock);
        $this->assertStringNotContainsString('fetchBobotData', $initBlock);
        $this->assertStringNotContainsString('initKkmNotificationSettings', $initBlock);
        $this->assertStringContainsString('this.loadSettingsData();', $openBlock);

        $this->assertStringContainsString('const SETTINGS_DATA_TTL_MS = 5 * 60 * 1000;', $source);
        $this->assertStringContainsString('settingsDataPromise: null', $source);
        $this->assertStringContainsString('if (this.settingsDataPromise)', $source);
        $this->assertStringContainsString('Promise.allSettled([', $source);
        $this->assertStringContainsString('new AbortController()', $source);
        $this->assertStringContainsString('signal: options.controller?.signal', $source);
        $this->assertStringContainsString('settingsLoadGeneration: 0', $source);
        $this->assertStringContainsString('const sequence = this.settingsLoadGeneration + 1;', $source);
        $this->assertStringContainsString('this.settingsLoadGeneration = sequence;', $source);
        $this->assertStringContainsString('this.settingsLoadGeneration === sequence', $source);
        $this->assertStringContainsString('this.invalidateActiveSettingsLoad();', $source);
        $this->assertStringContainsString('this.settingsLoadGeneration += 1;', $source);
        $this->assertStringContainsString('this.settingsAbortController?.abort();', $source);
        $this->assertStringContainsString('this.$el?.isConnected', $source);
        $this->assertStringContainsString('window.location.pathname === this.pagePath', $source);
        $this->assertStringContainsString("document.addEventListener('turbo:before-cache'", $source);
        $this->assertStringContainsString('prepareForCache()', $source);
        $this->assertStringContainsString('settingsInstances.delete(this);', $source);
        $this->assertStringContainsString('markSettingsDataStale()', $source);
        $this->assertStringContainsString('refreshKkmListAfterMutation()', $source);
        $this->assertStringContainsString('bobotLoaded: false', $source);
        $this->assertStringContainsString('bobotLoadError: false', $source);
        $this->assertStringContainsString('bobotSaving: false', $source);
        $this->assertStringContainsString('get canSaveBobot()', $source);
        $this->assertStringContainsString('this.bobotLoaded', $source);
        $this->assertStringContainsString('!this.settingsLoading', $source);
        $this->assertStringContainsString('!this.bobotLoadError', $source);
        $this->assertStringContainsString('!this.bobotSaving', $source);
        $this->assertStringContainsString('this.bobotLoaded = true;', $source);
        $this->assertStringContainsString('this.bobotLoaded = false;', $source);
        $this->assertStringContainsString('this.resetEndpointReadinessForLoad();', $source);
        $this->assertStringContainsString('this.settingsLoadedAt = null;', $source);

        foreach ([
            '/admin/kelas/data',
            '/admin/kkm/list',
            '/admin/bobot-nilai/data',
            '/admin/kkm/notification-settings',
        ] as $endpoint) {
            $this->assertStringContainsString($endpoint, $source);
        }

        $this->assertStringContainsString('settingsLoading', $modal);
        $this->assertStringContainsString('settingsLoadError', $modal);
        $this->assertStringContainsString('Coba lagi', $modal);
        $this->assertStringContainsString(':disabled="!canSaveBobot"', $modal);
        $this->assertStringContainsString('Data Bobot belum berhasil dimuat. Muat ulang sebelum menyimpan.', $modal);
    }

    public function test_sidebar_accessibility_state_is_synchronized_with_focus_safety(): void
    {
        $source = file_get_contents(resource_path('js/features/sidebar.js'));
        $sidebar = file_get_contents(resource_path('views/components/admin/sidebar.blade.php'));
        $pengajarSidebar = file_get_contents(resource_path('views/components/pengajar/sidebar.blade.php'));
        $waliSidebar = file_get_contents(resource_path('views/components/wali-kelas/sidebar.blade.php'));
        $topbar = file_get_contents(resource_path('views/components/admin/topbar.blade.php'));
        $adminLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $pengajarLayout = file_get_contents(resource_path('views/layouts/pengajar/app.blade.php'));
        $waliLayout = file_get_contents(resource_path('views/layouts/wali_kelas/app.blade.php'));
        $pengajarEditSubject = file_get_contents(resource_path('js/pages/pengajar-edit-subject.js'));
        $appCss = file_get_contents(resource_path('css/app.css'));

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
        $this->assertStringContainsString("sidebar.classList.contains('transform-none')", $source);
        $this->assertStringContainsString("sidebar.classList.add('-translate-x-full')", $source);
        $this->assertStringContainsString('rounded-lg xl:hidden', $topbar);
        $this->assertStringNotContainsString('rounded-lg lg:hidden', $topbar);
        $this->assertStringContainsString("const sidebarDesktopMediaQuery = '(min-width: 1280px)'", $source);
        preg_match("/sidebarDesktopMediaQuery = '\(min-width: (\d+)px\)'/", $source, $breakpointMatch);
        $desktopBreakpoint = (int) ($breakpointMatch[1] ?? 0);
        $this->assertFalse(1279 >= $desktopBreakpoint);
        $this->assertTrue(1280 >= $desktopBreakpoint);
        $this->assertTrue(1281 >= $desktopBreakpoint);
        $this->assertStringContainsString('const handleBreakpointChange = () => {', $source);
        $this->assertStringContainsString('ensureSidebarVisible();', $source);
        $this->assertSame(1, substr_count($source, "desktopMediaQuery.addEventListener('change', handleBreakpointChange)"));
        $this->assertStringContainsString("sidebar.classList.add('xl:translate-x-0')", $source);
        $this->assertStringContainsString('closeSidebarDrawer(sidebar);', $source);
        $this->assertStringContainsString('drawer.hide();', $source);
        $this->assertStringContainsString("sidebar.classList.remove('-translate-x-full')", $source);
        $this->assertStringContainsString("sidebar.classList.add('-translate-x-full')", $source);
        $this->assertStringNotContainsString('(min-width: 1024px)', $source);
        foreach ([$sidebar, $pengajarSidebar, $waliSidebar] as $roleSidebar) {
            $this->assertStringContainsString('xl:translate-x-0', $roleSidebar);
            $this->assertStringNotContainsString('lg:translate-x-0', $roleSidebar);
        }
        foreach ([$adminLayout, $pengajarLayout, $waliLayout] as $roleLayout) {
            $this->assertStringContainsString('xl:ml-64', $roleLayout);
            $this->assertStringNotContainsString('lg:ml-64', $roleLayout);
        }
        $this->assertStringContainsString('@media (max-width: 1279px)', $appCss);
        $this->assertStringContainsString('@media (min-width: 1280px)', $appCss);
        $this->assertMatchesRegularExpression('/@media \(min-width: 1024px\)\s*\{\s*\.form-container/s', $appCss);
        $this->assertStringNotContainsString("sidebar.style.transform = 'translateX(0)'", $pengajarEditSubject);
        $this->assertStringNotContainsString("style.setProperty('margin-left', '16rem')", $pengajarEditSubject);
        $this->assertStringNotContainsString('transform: translateX(-100%) !important', $appCss);
        $this->assertStringNotContainsString('transform: none !important', $adminLayout);
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
        $this->assertStringContainsString('class="p-4 xl:ml-64 min-h-screen bg-white relative"', $adminLayout);
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

    public function test_active_role_tables_keep_mobile_toolbars_and_row_actions_usable(): void
    {
        $appCss = file_get_contents(resource_path('css/app.css'));
        $tahunAjaran = file_get_contents(resource_path('views/admin/tahun_ajaran/index.blade.php'));

        $this->assertStringContainsString('.table-responsive {', $appCss);
        $this->assertStringContainsString('w-full max-w-full overflow-x-auto overscroll-x-contain', $appCss);
        $this->assertStringContainsString('.table-responsive > table {', $appCss);
        $this->assertStringContainsString('@apply w-full;', $appCss);
        $this->assertStringContainsString('.toolbar-action {', $appCss);
        $this->assertStringContainsString('min-h-10 shrink-0', $appCss);
        $this->assertStringContainsString('.table-action-group {', $appCss);
        $this->assertStringContainsString('mx-auto flex w-max', $appCss);
        $this->assertStringContainsString('gap-0.5 md:gap-0', $appCss);
        $this->assertStringContainsString('.table-action-heading,', $appCss);
        $this->assertStringContainsString('.table-action-cell {', $appCss);
        $this->assertStringContainsString('whitespace-nowrap text-center', $appCss);
        $this->assertStringContainsString('.table-action-control {', $appCss);
        $this->assertStringContainsString('h-10 w-10 shrink-0', $appCss);
        $this->assertStringContainsString('md:h-9 md:w-9', $appCss);
        $this->assertStringNotContainsString('.table-responsive th,', $appCss);

        $this->assertStringContainsString('grid w-full grid-cols-1 gap-2 sm:flex sm:flex-wrap', $tahunAjaran);
        $this->assertStringContainsString('toolbar-action w-full', $tahunAjaran);
        $this->assertStringContainsString('class="min-w-[56rem] bg-white border border-gray-200"', $tahunAjaran);
        $this->assertStringContainsString('class="table-action-group"', $tahunAjaran);
        $this->assertStringNotContainsString('table-action-group justify-start', $tahunAjaran);
        $this->assertStringContainsString('Tampilkan Arsip', $tahunAjaran);
        $this->assertStringContainsString('Tambah Tahun Ajaran', $tahunAjaran);

        $adminTables = [
            'views/admin/partials/student-results.blade.php',
            'views/admin/partials/teacher-results.blade.php',
            'views/admin/partials/class-results.blade.php',
            'views/admin/partials/subject-results.blade.php',
            'views/admin/partials/achievement-results.blade.php',
            'views/admin/partials/ekstrakurikuler-results.blade.php',
            'views/admin/report/index.blade.php',
            'views/admin/report/history.blade.php',
        ];

        foreach ($adminTables as $file) {
            $source = file_get_contents(resource_path($file));

            $this->assertStringContainsString('table-responsive', $source, "{$file} does not contain local table overflow");
            $this->assertStringContainsString('table-action-control', $source, "{$file} still has icon-sized controls");
            $this->assertStringContainsString('table-action-heading', $source, "{$file} does not align the action heading");
            $this->assertStringContainsString('table-action-cell', $source, "{$file} does not align the action controls");
        }

        $pengajarTables = [
            'views/pengajar/subject.blade.php',
            'views/pengajar/input_score.blade.php',
            'views/pengajar/score.blade.php',
        ];

        foreach ($pengajarTables as $file) {
            $source = file_get_contents(resource_path($file));

            $this->assertStringContainsString('table-responsive', $source, "{$file} does not contain local table overflow");
            $this->assertStringContainsString('table-action-control', $source, "{$file} still has icon-sized controls");
            $this->assertStringContainsString('table-action-heading', $source, "{$file} does not align the action heading");
            $this->assertStringContainsString('table-action-cell', $source, "{$file} does not align the action controls");
        }

        $waliTables = [
            'views/wali_kelas/partials/student-results.blade.php',
            'views/wali_kelas/capaian_kompetensi/index.blade.php',
            'views/wali_kelas/absence.blade.php',
            'views/wali_kelas/ekstrakurikuler.blade.php',
            'views/wali_kelas/rapor/index.blade.php',
        ];

        foreach ($waliTables as $file) {
            $source = file_get_contents(resource_path($file));

            $this->assertStringContainsString('table-responsive', $source, "{$file} does not contain local table overflow");
        }

        $pengajarSubject = file_get_contents(resource_path('views/pengajar/subject.blade.php'));
        $waliReport = file_get_contents(resource_path('views/wali_kelas/rapor/index.blade.php'));
        $guruVerification = file_get_contents(resource_path('views/auth/verify-email.blade.php'));
        $adminAccount = file_get_contents(resource_path('views/auth/admin-account-settings.blade.php'));
        $sidebar = file_get_contents(resource_path('js/features/sidebar.js'));

        $adminPasswordFormStart = strpos($adminAccount, '<form id="admin-change-password-form"');
        $this->assertNotFalse($adminPasswordFormStart);
        $adminPasswordFormEnd = strpos($adminAccount, '</form>', $adminPasswordFormStart);
        $this->assertNotFalse($adminPasswordFormEnd);
        $adminPasswordForm = substr(
            $adminAccount,
            $adminPasswordFormStart,
            $adminPasswordFormEnd + strlen('</form>') - $adminPasswordFormStart
        );

        $this->assertStringNotContainsString('h-7 w-7', $pengajarSubject);
        $this->assertStringContainsString('table-action-group', $waliReport);
        $this->assertStringContainsString('Unduh Semua Rapor', $waliReport);
        $this->assertStringContainsString('mt-6 w-full rounded-lg', $guruVerification);
        $this->assertStringContainsString("layouts.wali_kelas.app' : 'layouts.pengajar.app", $guruVerification);
        $this->assertStringNotContainsString("@extends('layouts.app')", $guruVerification);
        $this->assertStringNotContainsString('max-w-xl', $guruVerification);
        $this->assertStringContainsString('mt-6 w-full space-y-6', $adminAccount);
        $this->assertStringContainsString('id="admin-change-password-form"', $adminPasswordForm);
        $this->assertStringContainsString('mt-5 space-y-4', $adminPasswordForm);
        $this->assertStringNotContainsString('grid-cols-', $adminPasswordForm);
        $this->assertSame(1, substr_count($adminPasswordForm, 'type="submit"'));
        $this->assertStringContainsString("const sidebarDesktopMediaQuery = '(min-width: 1280px)'", $sidebar);
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

    private function sourceBlock(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        $this->assertNotFalse($start, "Missing source block start: {$startNeedle}");

        $end = strpos($source, $endNeedle, $start);
        $this->assertNotFalse($end, "Missing source block end: {$endNeedle}");

        return substr($source, $start, $end - $start);
    }
}
