<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Fixed-window rate limiter that keeps one tiny JSON counter file per key.
 */
final class RateLimiter
{
    public function __construct(private string $dir)
    {
    }

    /**
     * Count one hit for $key.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    public function hit(string $key, int $limit, int $windowSeconds = 60): array
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $file = $this->dir . '/rl_' . sha1($key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return ['allowed' => true, 'retry_after' => 0]; // never block users because of a disk problem
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return ['allowed' => true, 'retry_after' => 0];
            }
            $now = time();
            $window = intdiv($now, $windowSeconds);
            $data = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($data) || (int) ($data['w'] ?? -1) !== $window) {
                $data = ['w' => $window, 'c' => 0];
            }
            $data['c'] = (int) $data['c'] + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);

            if ($data['c'] > $limit) {
                return ['allowed' => false, 'retry_after' => max(1, ($window + 1) * $windowSeconds - $now)];
            }
            return ['allowed' => true, 'retry_after' => 0];
        } finally {
            fclose($handle);
        }
    }
}
