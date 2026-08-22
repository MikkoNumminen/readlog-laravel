<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\GoogleSignInController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ReadEntryController;
use Illuminate\Support\Facades\Route;

/*
| .NET counterpart: there is no counterpart file. Razor Pages derives its routes
| from the folder layout under Pages/, so Pages/Library/Edit.cshtml is /library/edit
| without anyone writing it down, and app.MapRazorPages() in Program.cs is the whole
| registration. Laravel makes the URL table explicit instead.
|
| That trade shows up immediately in this file: the .NET edit page declares its
| route parameter inside the view with `@page "{id:int}"`, while here the URL, the
| HTTP verb, the controller action, the name and the middleware all sit on one line
| and can be listed with `php artisan route:list`.
|
| URLs are kept identical to the source where the source has one, so links from the
| .NET app's screenshots and notes still resolve.
*/

Route::get('/', [FeedController::class, 'index'])->name('feed');
Route::get('/book', [BookController::class, 'show'])->name('book.show');

// Signing in with Google, and out again. .NET counterpart: the /signin and
// /signout paths ConfigureApplicationCookie names in Program.cs.
Route::get('/signin', [GoogleSignInController::class, 'show'])->name('signin');
Route::get('/signin/google', [GoogleSignInController::class, 'redirect'])->name('signin.google');
Route::get('/signin/google/callback', [GoogleSignInController::class, 'callback'])->name('signin.google.callback');
Route::post('/signout', [GoogleSignInController::class, 'destroy'])->name('signout');

// Readable by anyone, signed in or not. A visitor browsing the public URL sees
// the showcase reader's library, which is what makes the portfolio link worth
// following; CurrentUser decides whose library that is. Writing needs an account.
// ?ask= runs a local model for seconds per request; the limiter (see
// AppServiceProvider) applies only when that parameter is present.
Route::get('/library', [LibraryController::class, 'index'])->middleware('throttle:ask')->name('library.index');

// .NET counterpart: the [Authorize] attribute on the Log, Edit and Account page
// models. Library is no longer among them: it reads, and reading is public.
Route::middleware('auth')->group(function () {

    Route::get('/library/{entry}/edit', [ReadEntryController::class, 'edit'])
        ->whereNumber('entry')
        ->name('entries.edit');
    Route::put('/library/{entry}', [ReadEntryController::class, 'update'])
        ->whereNumber('entry')
        ->name('entries.update');
    Route::delete('/library/{entry}', [ReadEntryController::class, 'destroy'])
        ->whereNumber('entry')
        ->name('entries.destroy');

    Route::get('/log', [LogController::class, 'create'])->name('log.create');
    Route::post('/log', [LogController::class, 'store'])->name('log.store');

    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
});
