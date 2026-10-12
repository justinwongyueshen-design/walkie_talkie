<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Handles every message sent to POST /signal:
 *
 *   hello        join the room, get the first snapshot
 *   poll         heartbeat + fetch new events (joined/left, speaker, WebRTC data)
 *   ptt_request  ask for the floor
 *   ptt_release  give the floor back
 *   signal       relay a WebRTC offer/answer/candidate to another peer
 *   leave        leave the room
 *
 * handle() returns [httpStatus, payload].
 */
final class SignalingService
{
    private const TYPES = ['hello', 'poll', 'ptt_request', 'ptt_release', 'signal', 'leave'];
    private const KINDS = ['offer', 'answer', 'candidate'];

    /** @param array<string,int> $limits keys: max_data, rate_limit_per_minute */
    public function __construct(
        private TokenService $tokens,
        private RoomService $rooms,
        private RateLimiter $limiter,
        private array $limits
    ) {
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int,1:array<string,mixed>}
     */
    public function handle(array $body, string $ip): array
    {
        $type = $body['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            return [400, self::error('bad_request', 'Unknown message type.')];
        }

        // Requests with a bad token are limited per IP address.
        $token = $body['token'] ?? null;
        try {
            if (!is_string($token)) {
                throw new TokenException('invalid_token');
            }
            $claims = $this->tokens->verify($token);
        } catch (TokenException $e) {
            $this->limiter->hit('badtoken:' . $ip, 30);
            return $e->getMessage() === 'expired'
                ? [401, self::error('expired', 'The communication session has expired.')]
                : [401, self::error('invalid_token', 'The communication session is not valid.')];
        }

        // Valid requests are limited per peer.
        $rate = $this->limiter->hit('sig:' . $claims['pid'], (int) $this->limits['rate_limit_per_minute']);
        if (!$rate['allowed']) {
            $payload = self::error('rate_limited', 'Too many requests. Slowing down...');
            $payload['retry_after'] = $rate['retry_after'];
            return [429, $payload];
        }

        $channel = $claims['ch'];
        $pid = $claims['pid'];
        $nick = $claims['nick'];

        switch ($type) {
            case 'hello':
                $result = $this->rooms->join($channel, $pid, $nick);
                if ($result['ok'] !== true) {
                    return [409, self::error('room_full', 'This channel is currently full.')];
                }
                return [200, $this->withToken([
                    'ok'    => true,
                    'you'   => ['pid' => $pid, 'nick' => $nick],
                    'state' => $result['state'],
                    'seq'   => $result['seq'],
                ], $claims)];

            case 'poll':
                $since = isset($body['since']) && is_int($body['since']) ? max(0, $body['since']) : 0;
                $result = $this->rooms->poll($channel, $pid, $since);
                if ($result === null) {
                    return [200, self::error('not_joined', 'Please rejoin the channel.')];
                }
                return [200, $this->withToken(['ok' => true] + $result, $claims)];

            case 'ptt_request':
                $result = $this->rooms->requestFloor($channel, $pid);
                if (isset($result['error'])) {
                    return [200, self::error('not_joined', 'Please rejoin the channel.')];
                }
                return [200, ['ok' => true] + $result];

            case 'ptt_release':
                $result = $this->rooms->releaseFloor($channel, $pid);
                if (isset($result['error'])) {
                    return [200, self::error('not_joined', 'Please rejoin the channel.')];
                }
                return [200, ['ok' => true, 'released' => true]];

            case 'signal':
                return $this->relay($body, $channel, $pid);

            case 'leave':
                $this->rooms->leave($channel, $pid);
                return [200, ['ok' => true]];
        }

        return [400, self::error('bad_request', 'Unknown message type.')];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:int,1:array<string,mixed>}
     */
    private function relay(array $body, string $channel, string $pid): array
    {
        $to = $body['to'] ?? null;
        $data = $body['data'] ?? null;
        if (!is_string($to) || !GuestService::isValidPeerId($to) || !is_array($data)) {
            return [400, self::error('bad_request', 'Invalid signaling message.')];
        }
        $kind = $data['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return [400, self::error('bad_request', 'Invalid signaling message.')];
        }
        $encoded = json_encode($data);
        if ($encoded === false || strlen($encoded) > (int) $this->limits['max_data']) {
            return [413, self::error('payload_too_large', 'Signaling data is too large.')];
        }
        // Only keep the fields WebRTC needs.
        $clean = ['kind' => $kind];
        if ($kind === 'candidate') {
            if (!isset($data['candidate']) || !is_array($data['candidate'])) {
                return [400, self::error('bad_request', 'Invalid signaling message.')];
            }
            $clean['candidate'] = $data['candidate'];
        } else {
            if (!isset($data['sdp']) || !is_string($data['sdp'])) {
                return [400, self::error('bad_request', 'Invalid signaling message.')];
            }
            $clean['sdp'] = $data['sdp'];
        }

        $result = $this->rooms->relay($channel, $pid, $to, $clean);
        if (isset($result['error'])) {
            return $result['error'] === 'not_joined'
                ? [200, self::error('not_joined', 'Please rejoin the channel.')]
                : [200, self::error('unknown_peer', 'That user has left the channel.')];
        }
        return [200, ['ok' => true]];
    }

    /**
     * Attach a fresh token when the current one is close to expiring.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $claims
     * @return array<string,mixed>
     */
    private function withToken(array $payload, array $claims): array
    {
        if ($this->tokens->needsRefresh($claims)) {
            $payload['token'] = $this->tokens->issue($claims['pid'], $claims['nick'], $claims['ch']);
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private static function error(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message];
    }
}
