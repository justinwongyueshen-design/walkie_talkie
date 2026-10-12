<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Room;

/**
 * Room / channel management on top of JSON files.
 *
 * Every change goes through mutate(): it takes an exclusive file lock (flock),
 * reads the room, lets a callback change it, writes it back and unlocks.
 * Because only one request can hold the lock at a time, two people pressing
 * PTT at the same moment are handled one after the other - the first one
 * gets the floor, the second one is told the channel is busy.
 */
final class RoomService
{
    public function __construct(
        private string $dir,
        private int $maxPeers,
        private int $floorTimeoutMs,
        private int $peerTimeoutMs
    ) {
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function pathFor(string $channel): string
    {
        // Hashing the name gives safe file names (no traversal, no reserved Windows names).
        return $this->dir . '/' . substr(sha1($channel), 0, 24) . '.json';
    }

    /**
     * Run $fn(array &$room, int $now) while holding the room lock.
     *
     * @template T
     * @param callable(array<string,mixed>&,int):T $fn
     * @return T
     */
    private function mutate(string $channel, callable $fn): mixed
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $handle = @fopen($this->pathFor($channel), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open room file');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock room file');
            }
            $raw = stream_get_contents($handle);
            $room = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
            if (!is_array($room) || !isset($room['peers'], $room['events'], $room['seq'])) {
                $room = Room::blank($channel);
            }
            $now = $this->nowMs();
            Room::prune($room, $now, $this->peerTimeoutMs);

            $result = $fn($room, $now);

            $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $json);
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** Number of users currently in the channel (read-only, used on the join page). */
    public function countActive(string $channel): int
    {
        $path = $this->pathFor($channel);
        if (!is_file($path)) {
            return 0;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        try {
            flock($handle, LOCK_SH);
            $room = json_decode((string) stream_get_contents($handle), true);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        if (!is_array($room) || !isset($room['peers']) || !is_array($room['peers'])) {
            return 0;
        }
        $now = $this->nowMs();
        $count = 0;
        foreach ($room['peers'] as $peer) {
            if (($now - (int) ($peer['seen'] ?? 0)) <= $this->peerTimeoutMs) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<string,mixed> ["ok"=>true,"state"=>...,"seq"=>...] or ["ok"=>false,"error"=>"room_full"] */
    public function join(string $channel, string $pid, string $nick): array
    {
        return $this->mutate($channel, function (array &$room, int $now) use ($pid, $nick): array {
            if (!isset($room['peers'][$pid])) {
                if (count($room['peers']) >= $this->maxPeers) {
                    return ['ok' => false, 'error' => 'room_full'];
                }
                $room['peers'][$pid] = ['nick' => $nick, 'joined' => $now, 'seen' => $now];
                Room::emit($room, ['type' => 'peer_joined', 'pid' => $pid, 'nick' => $nick], $now);
            } else {
                $room['peers'][$pid]['seen'] = $now;
            }
            return [
                'ok'    => true,
                'state' => Room::snapshot($room, $this->maxPeers, $now),
                'seq'   => (int) $room['seq'],
            ];
        });
    }

    /** @return array<string,mixed>|null null when the peer is not (or no longer) in the room */
    public function poll(string $channel, string $pid, int $since): ?array
    {
        return $this->mutate($channel, function (array &$room, int $now) use ($pid, $since): ?array {
            if (!isset($room['peers'][$pid])) {
                return null;
            }
            $room['peers'][$pid]['seen'] = $now;
            return [
                'state'  => Room::snapshot($room, $this->maxPeers, $now),
                'events' => Room::eventsFor($room, $pid, $since),
                'seq'    => (int) $room['seq'],
            ];
        });
    }

    /**
     * Ask for the floor (permission to talk).
     *
     * @return array<string,mixed>
     */
    public function requestFloor(string $channel, string $pid): array
    {
        return $this->mutate($channel, function (array &$room, int $now) use ($pid): array {
            if (!isset($room['peers'][$pid])) {
                return ['error' => 'not_joined'];
            }
            $room['peers'][$pid]['seen'] = $now;
            $speaker = $room['speaker'];

            if ($speaker !== null && $speaker['pid'] !== $pid) {
                return [
                    'granted' => false,
                    'reason'  => 'busy',
                    'speaker' => ['pid' => $speaker['pid'], 'nick' => $speaker['nick']],
                ];
            }
            if ($speaker === null) {
                $room['speaker'] = [
                    'pid'     => $pid,
                    'nick'    => $room['peers'][$pid]['nick'],
                    'since'   => $now,
                    'expires' => $now + $this->floorTimeoutMs,
                ];
                Room::emit($room, [
                    'type'       => 'speaker_start',
                    'pid'        => $pid,
                    'nick'       => $room['peers'][$pid]['nick'],
                    'expires_at' => $room['speaker']['expires'],
                ], $now);
            }
            return [
                'granted'     => true,
                'expires_at'  => (int) $room['speaker']['expires'],
                'duration_ms' => $this->floorTimeoutMs,
            ];
        });
    }

    /** Give the floor back. Releasing when you are not the speaker does nothing. */
    public function releaseFloor(string $channel, string $pid): array
    {
        return $this->mutate($channel, function (array &$room, int $now) use ($pid): array {
            if (!isset($room['peers'][$pid])) {
                return ['error' => 'not_joined'];
            }
            $room['peers'][$pid]['seen'] = $now;
            if ($room['speaker'] !== null && $room['speaker']['pid'] === $pid) {
                $room['speaker'] = null;
                Room::emit($room, ['type' => 'speaker_stop', 'pid' => $pid, 'reason' => 'released'], $now);
            }
            return ['released' => true];
        });
    }

    /**
     * Queue a WebRTC message (offer / answer / candidate) for one other peer.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function relay(string $channel, string $from, string $to, array $data): array
    {
        return $this->mutate($channel, function (array &$room, int $now) use ($from, $to, $data): array {
            if (!isset($room['peers'][$from])) {
                return ['error' => 'not_joined'];
            }
            $room['peers'][$from]['seen'] = $now;
            if ($to === $from || !isset($room['peers'][$to])) {
                return ['error' => 'unknown_peer'];
            }
            Room::emit($room, ['type' => 'signal', 'from' => $from, 'to' => $to, 'data' => $data], $now);
            return ['queued' => true];
        });
    }

    public function leave(string $channel, string $pid): void
    {
        $this->mutate($channel, function (array &$room, int $now) use ($pid): bool {
            if (!isset($room['peers'][$pid])) {
                return false;
            }
            $nick = $room['peers'][$pid]['nick'];
            unset($room['peers'][$pid]);
            if ($room['speaker'] !== null && $room['speaker']['pid'] === $pid) {
                $room['speaker'] = null;
                Room::emit($room, ['type' => 'speaker_stop', 'pid' => $pid, 'reason' => 'left'], $now);
            }
            Room::emit($room, ['type' => 'peer_left', 'pid' => $pid, 'nick' => $nick], $now);
            return true;
        });
    }
}
