<?php

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
|
| .NET counterpart: nothing exactly, but it plays the combined role of xUnit's
| collection fixtures and the test project's shared base class. `uses(...)` binds
| a base TestCase (and traits) to whole directories, instead of every test class
| deriving from one explicitly.
|
| RefreshDatabase is the counterpart of the .NET SqliteTestDatabase helper: it
| migrates a fresh schema for each test and rolls it back afterwards, so tests
| never see each other's rows.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');
