<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ollama could not be reached, refused, timed out, or answered with something
 * that was not the shape the client expects. One exception for every way the
 * optional dependency can be absent, so callers have exactly one thing to catch
 * and exactly one decision to make: degrade.
 *
 * .NET counterpart: none, along with the rest of the AI search.
 */
class OllamaUnavailableException extends RuntimeException {}
