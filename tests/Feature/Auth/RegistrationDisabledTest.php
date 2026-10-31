<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_cannot_be_posted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.test',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ]);

        // Either 404 or 405 is acceptable; what matters is that no account exists.
        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame(0, User::where('email', 'intruder@example.test')->count());
        $this->assertGuest();
    }
}