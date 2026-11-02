<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(IndexUserRequest $request, UserManagementService $users): Response
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);

        $filters = $request->filters();

        return Inertia::render('users/index', [
            ...$users->list($filters, $viewer),
            'filters' => $filters,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserManagementService $users): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $error = $users->update($user, $actor, $request->changes());

        if ($error !== null) {
            return back()->with('error', $error);
        }

        return back()->with('success', "Account of {$user->name} updated.");
    }
}