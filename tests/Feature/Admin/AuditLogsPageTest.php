<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/audit-logs')->assertRedirect();
    }

    public function test_only_a_super_administrator_can_read_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('viewer'))->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->userWithRole('network_admin'))->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->userWithRole('super_admin'))->get('/audit-logs')->assertOk();
    }

    public function test_entries_are_listed_newest_first_with_decoded_details(): void
    {
        $this->entry('device.created', 'Device created: Core router', now()->subMinutes(10), 'Ana Cruz', ['name' => 'Core router']);
        $this->entry('alert.resolved', 'Alert resolved: Core router down', now()->subMinutes(1), null);

        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('audit-logs/index')
                ->where('logs.total', 2)
                ->where('logs.data.0.action', 'alert.resolved')
                ->where('logs.data.0.actor', 'System')
                ->where('logs.data.0.metadata', null)
                ->where('logs.data.1.actor', 'Ana Cruz')
                ->where('logs.data.1.metadata.name', 'Core router')
                ->has('actions', 2)
                ->has('timezone'));
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->entry('device.created', 'Device created: Core router', now(), 'Ana Cruz');
        $this->entry('auth.login_failed', 'Failed sign-in attempt.', now(), null, ['email' => 'x@example.com']);
        $this->entry('interface.updated', 'Interface changed', now(), 'Ben Reyes');

        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)
            ->get('/audit-logs?action=auth.login_failed')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'auth.login_failed'));

        $this->actingAs($admin)
            ->get('/audit-logs?search=Ben')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'interface.updated'));

        $this->actingAs($admin)
            ->get('/audit-logs?search=Core router')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'device.created'));
    }

    public function test_the_date_filter_uses_whole_days(): void
    {
        $day = CarbonImmutable::parse('2026-11-01 10:00:00', (string) config('app.timezone'));

        $this->entry('inside.range', 'Inside', $day);
        $this->entry('before.range', 'Before', $day->subDays(3));
        $this->entry('after.range', 'After', $day->addDays(3));

        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/audit-logs?from=2026-11-01&to=2026-11-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'inside.range'));
    }

    public function test_the_summary_counts_the_last_24_hours_only(): void
    {
        $this->entry('auth.login_failed', 'Failed sign-in attempt.', now()->subHours(2));
        $this->entry('auth.login_failed', 'Failed sign-in attempt.', now()->subHours(30));
        $this->entry('auth.login', 'User signed in.', now()->subHours(1));
        $this->entry('settings.updated', 'Settings changed', now()->subHours(1));

        $this->actingAs($this->userWithRole('super_admin'))
            ->get('/audit-logs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.events_24h', 3)
                ->where('stats.failed_logins_24h', 1)
                ->where('stats.changes_24h', 1));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $admin = $this->userWithRole('super_admin');

        $this->actingAs($admin)->get('/audit-logs?action=a b;drop')->assertSessionHasErrors('action');
        $this->actingAs($admin)->get('/audit-logs?from=yesterday')->assertSessionHasErrors('from');
        $this->actingAs($admin)->get('/audit-logs?from=2026-11-05&to=2026-11-01')->assertSessionHasErrors('to');
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function entry(string $action, string $description, \DateTimeInterface $at, ?string $actor = null, ?array $metadata = null): void
    {
        DB::table('audit_logs')->insert([
            'actor_name' => $actor,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata === null ? null : json_encode($metadata),
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}