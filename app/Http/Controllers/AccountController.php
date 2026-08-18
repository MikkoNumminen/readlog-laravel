<?php

namespace App\Http\Controllers;

use App\Services\CurrentUser;
use App\Services\ReadLogService;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The account page: profile plus reading stats.
 *
 * .NET counterpart: Pages/Account.cshtml.cs, which merges an aggregate from the
 * database with the profile from UserManager. Here the profile comes off the User
 * model directly, because there is no UserManager to ask.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
    ) {}

    public function show(): View
    {
        $user = $this->currentUser->get();

        $displayName = $user->name;
        $initialSource = $displayName !== null && $displayName !== '' ? $displayName : $user->email;

        return view('account', [
            'stats' => $this->readLog->getAccountStats($user->id),
            'displayName' => $displayName,
            'email' => $user->email,
            'imageUrl' => $user->image,
            'initial' => $initialSource === '' ? '?' : Str::upper(Str::substr($initialSource, 0, 1)),
        ]);
    }
}
