<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateReadEntryException;
use App\Http\Requests\UpdateReadEntryRequest;
use App\Services\CurrentUser;
use App\Services\ReadLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Editing and deleting one of the acting user's read entries.
 *
 * .NET counterpart: Pages/Library/Edit.cshtml.cs, whose OnGet, OnPost and
 * OnPostDelete handlers become three routed actions here.
 *
 * Note what is deliberately absent: route-model binding. Laravel would happily
 * resolve `{entry}` into a ReadEntry for us, but that would fetch the row before
 * anyone had asked whose it is, and then the ownership check would be a second
 * step that is easy to forget. Passing the id to the service, which filters on
 * id and user in one query, keeps "not found" and "not yours" the same answer,
 * which is what the source does and why it returns 404 rather than 403.
 */
class ReadEntryController extends Controller
{
    public function __construct(
        private readonly ReadLogService $readLog,
        private readonly CurrentUser $currentUser,
    ) {}

    public function edit(int $entry): View
    {
        $libraryEntry = $this->readLog->getEntry($this->currentUser->id(), $entry);

        abort_if($libraryEntry === null, 404);

        return view('entries.edit', ['entry' => $libraryEntry]);
    }

    public function update(UpdateReadEntryRequest $request, int $entry): RedirectResponse
    {
        try {
            $updated = $this->readLog->updateReadEntry(
                $this->currentUser->id(),
                $entry,
                $request->toData(),
            );
        } catch (DuplicateReadEntryException) {
            return back()
                ->withInput()
                ->withErrors(['form' => "You've already logged this book with that finished date."]);
        }

        abort_if(! $updated, 404);

        return redirect()->route('library.index')->with('notice', 'Entry updated.');
    }

    public function destroy(int $entry): RedirectResponse
    {
        $deleted = $this->readLog->deleteReadEntry($this->currentUser->id(), $entry);

        abort_if(! $deleted, 404);

        return redirect()->route('library.index')->with('notice', 'Entry deleted.');
    }
}
