<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Enums\UserRole;
use App\Http\Requests\StoreUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class StoreUserController extends Controller
{
    /**
     * Create an account from the User Management screen.
     *
     * The starter kit's CreateNewUser action does the creating, so the new person gets the same
     * team setup as anyone who registers. The role and the active flag are not mass-assignable
     * on purpose, so they are set explicitly here.
     */
    public function __invoke(StoreUserRequest $request, CreateNewUser $creator): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $creator) {
            $user = $creator->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password'],
            ]);

            $user->forceFill([
                'role' => UserRole::from($data['role']),
                'is_active' => true,
                // The administrator vouches for the address, so there is no verification mail to wait for.
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        return back()->with('success', "Account created for {$user->name}. They can sign in now with the email and password you set.");
    }
}