<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MaintenanceRouteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'super-secret-maintenance-token';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('maintenance:127.0.0.1');
    }

    public function test_route_does_not_exist_without_a_token_in_config(): void
    {
        config(['school.maintenance_token' => null]);

        $this->get('/maintenance/whatever/migrate')->assertNotFound();
    }

    public function test_wrong_token_gives_404_not_403(): void
    {
        config(['school.maintenance_token' => self::TOKEN]);

        // 404, а не 403: стороннім не варто знати, що маршрут узагалі є.
        $this->get('/maintenance/wrong-token/migrate')->assertNotFound();
    }

    public function test_unknown_command_is_rejected(): void
    {
        config(['school.maintenance_token' => self::TOKEN]);

        $this->get('/maintenance/'.self::TOKEN.'/db:wipe')->assertNotFound();
    }

    public function test_allowed_command_runs(): void
    {
        config(['school.maintenance_token' => self::TOKEN]);

        Artisan::shouldReceive('call')->once()->with('migrate:status', [])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Migration table found.');

        $this->get('/maintenance/'.self::TOKEN.'/status')
            ->assertOk()
            ->assertSee('Migration table found.');
    }

    public function test_guessing_the_token_is_rate_limited(): void
    {
        config(['school.maintenance_token' => self::TOKEN]);

        foreach (range(1, 5) as $attempt) {
            $this->get('/maintenance/wrong-'.$attempt.'/migrate')->assertNotFound();
        }

        // Шоста спроба відсікається лімітом ще до звірки токена.
        $this->get('/maintenance/'.self::TOKEN.'/status')->assertStatus(429);
    }

    public function test_scheduler_needs_its_own_token(): void
    {
        config(['school.scheduler_token' => null]);
        $this->get('/scheduler/anything')->assertNotFound();

        config(['school.scheduler_token' => 'scheduler-token']);
        $this->get('/scheduler/wrong')->assertNotFound();
    }

    public function test_scheduler_runs_the_schedule(): void
    {
        config(['school.scheduler_token' => 'scheduler-token']);

        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('No scheduled commands are ready to run.');

        $this->get('/scheduler/scheduler-token')
            ->assertOk()
            ->assertSee('No scheduled commands are ready to run.');
    }
}
