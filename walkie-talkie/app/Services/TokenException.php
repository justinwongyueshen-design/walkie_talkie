<?php
declare(strict_types=1);

namespace App\Services;

/** Thrown when a session token is missing, forged or expired. getMessage() is the error code. */
final class TokenException extends \RuntimeException
{
}
