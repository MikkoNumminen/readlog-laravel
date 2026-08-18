<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
|
| .NET counterpart: roughly xUnit's shared base class plus collection fixtures.
| `uses(...)` binds a base TestCase (and traits) to whole directories, instead of
| every test class deriving from one explicitly.
|
| RefreshDatabase plays the part of the .NET tests' SqliteTestDatabase helper: it
| migrates a fresh schema and wraps each test in a transaction that is rolled back
| afterwards, so tests never see each other's rows. It is bound to Feature only;
| Unit tests here are pure and should not pay for a database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
