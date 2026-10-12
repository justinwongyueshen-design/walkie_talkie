<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\GuestService;
use App\Services\Services;

/** The walkie-talkie screen. */
final class ChannelController
{
    public function show(Request $request): Response
    {
        $requested = $request->param('channel');
        $channel = GuestService::normalizeChannel($requested);
        if ($channel === null) {
            Session::set('flash_error', 'Please enter a valid channel name.');
            return Response::redirect(url('/'));
        }

        // A visitor without a nickname (e.g. opened a shared link) goes to the join page first.
        $nickname = Session::get('nickname');
        if (!is_string($nickname) || GuestService::sanitizeNickname($nickname) === null) {
            return Response::redirect(url('/') . '?channel=' . rawurlencode($channel));
        }

        // Canonical URL for the normalised channel name.
        if ($requested !== $channel) {
            return Response::redirect(url('/channel/' . rawurlencode($channel)));
        }

        $max = (int) Config::get('signal.max_peers');
        if (Services::rooms()->countActive($channel) >= $max) {
            Session::set('flash_error', 'This channel is currently full. Maximum ' . $max . ' users are allowed.');
            return Response::redirect(url('/'));
        }

        // Every page load is a new "peer" (so two tabs of one browser can talk to each other).
        $pid = GuestService::newPeerId();
        $token = Services::tokens()->issue($pid, $nickname, $channel);
        Session::set('last_channel', $channel);

        $clientConfig = [
            'base'           => base_path(),
            'signalUrl'      => url('/signal'),
            'homeUrl'        => url('/'),
            'token'          => $token,
            'pid'            => $pid,
            'nick'           => $nickname,
            'channel'        => $channel,
            'maxPeers'       => $max,
            'floorTimeoutMs' => (int) Config::get('signal.floor_timeout_ms'),
            'pollIntervalMs' => (int) Config::get('signal.poll_interval_ms'),
            'iceServers'     => Config::get('ice_servers'),
        ];

        $html = View::render('channel', [
            'title'        => '#' . $channel,
            'channel'      => $channel,
            'nickname'     => $nickname,
            'clientConfig' => $clientConfig,
            'scripts'      => ['audio-level.js', 'signaling.js', 'webrtc.js', 'ptt.js', 'app.js'],
            'bodyClass'    => 'wt-channel-page',
        ]);
        return Response::html($html)->header('Cache-Control', 'no-store');
    }
}
