<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Signing in with Google did not work. One exception for every way it can fail,
 * so the controller has one thing to catch and the reader gets one sentence.
 *
 * .NET counterpart: the RemoteFailure the Google handler raises, which the
 * source turns into a message on the sign-in page the same way.
 */
class GoogleSignInException extends RuntimeException {}
