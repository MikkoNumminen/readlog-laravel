<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches which seeded reader the app is acting as.
 *
 * .NET counterpart: none. readlog-dotnet has real sign-in
 * (Pages/Account/Login.cshtml.cs); version 1 of this migration does not, and this
 * is the demo stand-in that keeps the per-user behaviour visible. It disappears the
 * day authentication lands.
 *
 * It is a POST, not a GET, so that switching reader goes through the CSRF token
 * like every other state change and cannot be triggered by a link on another site.
 */
class DemoUserController extends Controller
{
    public function __construct(private readonly CurrentUser $currentUser) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->currentUser->switchTo(User::findOrFail($validated['user_id']));

        return back();
    }
}
