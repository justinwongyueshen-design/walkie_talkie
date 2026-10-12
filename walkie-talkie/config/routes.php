<?php
declare(strict_types=1);

use App\Controllers\ChannelController;
use App\Controllers\JoinController;
use App\Controllers\SignalController;

/**
 * Route table: [HTTP method, path pattern, [Controller::class, 'method'], [middleware...]]
 * "{name}" segments become route parameters.
 */
return [
    ['GET',  '/',                  [JoinController::class,    'show'], []],
    ['POST', '/join',              [JoinController::class,    'join'], ['throttle:30', 'csrf']],
    ['GET',  '/channel/{channel}', [ChannelController::class, 'show'], []],
    ['POST', '/signal',            [SignalController::class,  'handle'], []],
];
