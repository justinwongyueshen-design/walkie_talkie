<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

/** Rejects POST requests that do not carry the session's CSRF token. */
final class CsrfMiddleware
{
    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'POST') {
            return null;
        }
        if (!$request->isSameOrigin() || !Session::verifyCsrf($request->input('_csrf'))) {
            return Router::errorPage(419);
        }
        return null;
    }
}
