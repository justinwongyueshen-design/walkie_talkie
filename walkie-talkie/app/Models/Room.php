<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Room data helpers. A room is a plain array stored as JSON:
 *
 *   name     channel name
 *   peers    pid => [nick, joined, seen]      (milliseconds timestamps)
 *   speaker  null | [pid, nick, since, expires]
 *   seq      last event number
 *   events   recent events (peer_joined, peer_left, speaker_start, speaker_stop, signal)
 *
 * Keeping this logic in one small class makes it easy to swap the JSON files
 * for a MySQL table later.
 */
final class Room
{
    public const EVENT_TTL_MS = 45000;
    public const MAX_EVENTS = 120;

    /** @return array<string,mixed> */
    public static function blank(string $name): array
    {
        return ['name' => $name, 'peers' => [], 'speaker' => null, 'seq' => 0, 'events' => []];
    }

    /**
     * Remove peers that stopped polling, release an expired/absent speaker,
     * and drop old events.
     *
     * @param array<string,mixed> $room
     */
    public static function prune(array &$room, int $now, int $peerTimeoutMs): void
    {
        foreach ($room['peers'] as $pid => $peer) {
            if ($now - (int) $peer['seen'] > $peerTimeoutMs) {
                unset($room['peers'][$pid]);
                self::emit($room, ['type' => 'peer_left', 'pid' => (string) $pid, 'nick' => $peer['nick']], $now);
            }
        }

        if ($room['speaker'] !== null) {
            $speaker = $room['speaker'];
            if (!isset($room['peers'][$speaker['pid']])) {
                $room['speaker'] = null;
                self::emit($room, ['type' => 'speaker_stop', 'pid' => $speaker['pid'], 'reason' => 'left'], $now);
            } elseif ($now >= (int) $speaker['expires']) {
                $room['speaker'] = null;
                self::emit($room, ['type' => 'speaker_stop', 'pid' => $speaker['pid'], 'reason' => 'timeout'], $now);
            }
        }

        $events = array_values(array_filter(
            $room['events'],
            static fn(array $e): bool => ($now - (int) $e['ts']) <= self::EVENT_TTL_MS
        ));
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }
        $room['events'] = $events;
    }

    /**
     * Append an event with the next sequence number.
     *
     * @param array<string,mixed> $room
     * @param array<string,mixed> $event
     */
    public static function emit(array &$room, array $event, int $now): void
    {
        $room['seq'] = (int) $room['seq'] + 1;
        $event['seq'] = $room['seq'];
        $event['ts'] = $now;
        $room['events'][] = $event;
    }

    /**
     * Events newer than $since that are addressed to everybody or to $pid.
     *
     * @param array<string,mixed> $room
     * @return array<int,array<string,mixed>>
     */
    public static function eventsFor(array $room, string $pid, int $since): array
    {
        $out = [];
        foreach ($room['events'] as $event) {
            if ((int) $event['seq'] <= $since) {
                continue;
            }
            if (isset($event['to']) && $event['to'] !== $pid) {
                continue;
            }
            unset($event['ts']);
            $out[] = $event;
        }
        return $out;
    }

    /**
     * Public snapshot sent to clients (no secrets, no internal timestamps).
     *
     * @param array<string,mixed> $room
     * @return array<string,mixed>
     */
    public static function snapshot(array $room, int $maxPeers, int $now): array
    {
        $speakerPid = $room['speaker']['pid'] ?? null;
        $peers = [];
        foreach ($room['peers'] as $pid => $peer) {
            $peers[] = [
                'pid'      => (string) $pid,
                'nick'     => $peer['nick'],
                'speaking' => $speakerPid !== null && $speakerPid === (string) $pid,
                'joined'   => (int) $peer['joined'],
            ];
        }
        usort($peers, static fn(array $a, array $b): int => $a['joined'] <=> $b['joined']);

        return [
            'peers'       => $peers,
            'count'       => count($peers),
            'max'         => $maxPeers,
            'speaker'     => $room['speaker'] === null ? null : [
                'pid'        => $room['speaker']['pid'],
                'nick'       => $room['speaker']['nick'],
                'expires_at' => (int) $room['speaker']['expires'],
            ],
            'server_time' => $now,
        ];
    }
}
