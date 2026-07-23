<?php

namespace Tests\Feature;

use App\Http\Controllers\ScoreController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GradeDeletionRouteCleanupTest extends TestCase
{
    public function test_active_enrollment_aware_grade_deletion_route_remains_available(): void
    {
        $route = Route::getRoutes()->getByName('pengajar.score.nilai.delete');

        $this->assertNotNull($route);
        $this->assertSame('pengajar/score/score/nilai/delete', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(ScoreController::class.'@deleteNilai', $route->getActionName());
        $this->assertTrue(method_exists(ScoreController::class, 'deleteNilai'));
    }

    public function test_obsolete_bulk_grade_deletion_route_is_not_registered(): void
    {
        $this->assertFalse(Route::has('pengajar.score.delete'));
    }

    public function test_no_pengajar_grade_deletion_route_targets_a_missing_controller_method(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $actionName = $route->getActionName();

            if (! str_starts_with($name, 'pengajar.score.')) {
                continue;
            }

            $looksLikeGradeDeletionRoute = str_contains($name, 'delete')
                || str_contains($route->uri(), 'nilai/delete')
                || str_contains($actionName, 'delete');

            if (! $looksLikeGradeDeletionRoute || ! str_contains($actionName, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $actionName, 2);

            $this->assertTrue(
                method_exists($controller, $method),
                "{$name} targets missing controller method {$actionName}."
            );
        }
    }
}
