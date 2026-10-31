<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_active_user_can_sign_in_and_login_is_audited(): void
    {
        $user = $this->userWithRole(UserRole::NetworkAdmin, true, ['password' => bcrypt('correct-password-1')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password-1']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_deactivated_user_cannot_sign_in(): void
    {
        $user = $this->userWithRole(UserRole::Viewer, false, ['password' => bcrypt('correct-password-1')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password-1']);

        $this->assertGuest();
    }

    public function test_session_of_a_user_deactivated_while_signed_in_is_ended(): void
    {
        $user = $this->userWithRole(UserRole::Viewer);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_failed_login_is_audited_without_the_password(): void
    {
        $user = $this->userWithRole(UserRole::Viewer);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password-value']);

        $log = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('wrong-password-value', json_encode($log->toArray()));
    }
}