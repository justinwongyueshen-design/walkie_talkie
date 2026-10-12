<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Services\Services;

/** POST /signal - JSON endpoint used by the browser for signaling and PTT. */
final class SignalController
{
    public function handle(Request $request): Response
    {
        if (!$request->isSameOrigin()) {
            return Response::json(['ok' => false, 'error' => 'forbidden', 'message' => 'Request not allowed.'], 403);
        }

        $max = (int) Config::get('signal.max_body');
        $raw = $request->rawBody($max);
        if ($raw === null) {
            return Response::json(['ok' => false, 'error' => 'payload_too_large', 'message' => 'Request is too large.'], 413);
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return Response::json(['ok' => false, 'error' => 'bad_request', 'message' => 'Invalid request.'], 400);
        }

        try {
            [$status, $payload] = Services::signaling()->handle($body, $request->ip());
        } catch (\Throwable $e) {
            Logger::error('Signaling failure', $e);
            return Response::json([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => 'Unable to connect to the communication server.',
            ], 500);
        }
        return Response::json($payload, $status);
    }
}
