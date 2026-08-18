<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ReadLogService::logBook when a read entry for the same
 * (user, book, finished-on date) already exists.
 *
 * .NET counterpart: Services/DuplicateReadEntryException.cs. Same reason for
 * existing: callers should not have to inspect a driver-specific integrity error
 * to tell "you already logged this" from "the database is locked".
 */
class DuplicateReadEntryException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A read entry for this book on this date already exists.');
    }
}
