<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Secure setup path: public registration is disabled, so the first
 * Super Administrator (and any later account) is created from the server.
 *
 *   php artisan campusnet:create-user --role=super_admin
 */
class CreateUserCommand extends Command
{
    protected $signature = 'campusnet:create-user
        {--name= : Full name}
        {--email= : E-mail address used to sign in}
        {--role=viewer : super_admin, network_admin or viewer}
        {--password= : Leave empty to generate a random one-time password}';

    protected $description = 'Create a CampusNet user account (registration is disabled in the web UI)';

    public function handle(AuditLogger $audit): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('E-mail address');
        $role = $this->option('role');

        $generated = false;
        $password = $this->option('password');

        if (! $password) {
            $password = Str::password(16, symbols: false);
            $generated = true;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'role' => $role, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
                'role' => ['required', Rule::in(UserRole::values())],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::from($role),
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $audit->record(
            action: 'user.created',
            subject: $user,
            metadata: ['role' => $role, 'via' => 'cli'],
            description: "Account created from the command line for {$email}.",
            actor: null,
        );

        $this->info("Created {$user->role->label()}: {$email}");

        if ($generated) {
            $this->warn('One-time password (shown once, change it after the first sign-in):');
            $this->line($password);
        }

        return self::SUCCESS;
    }
}