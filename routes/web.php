<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\DemoUserController;
use App\Http\Controllers\FeedController;
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

// Demo-only: pick which seeded reader the app acts as. See DemoUserController.
Route::post('/demo-user', [DemoUserController::class, 'update'])->name('demo-user.update');

// .NET counterpart: the [Authorize] attribute on the Log, Library, Edit and
// Account page models.
Route::middleware('demo.user')->group(function () {
    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');

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
