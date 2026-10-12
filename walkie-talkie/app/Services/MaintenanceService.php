<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Housekeeping: removes old room files, rate-limit counters and session files.
 * Called now and then from the join page (about 1 request in 40).
 */
final class MaintenanceService
{
    public function __construct(private string $storageDir)
    {
    }

    public function runSometimes(int $oneIn = 40): void
    {
        if (random_int(1, $oneIn) === 1) {
            $this->run();
        }
    }

    public function run(): void
    {
        $now = time();
        // Rooms are touched on every poll, so an old file means nobody is there.
        $this->deleteOlderThan($this->storageDir . '/rooms', '*.json', $now - 3600);
        $this->deleteOlderThan($this->storageDir . '/ratelimit', 'rl_*.json', $now - 600);
        $this->deleteOlderThan($this->storageDir . '/sessions', 'sess_*', $now - 86400);
    }

    private function deleteOlderThan(string $dir, string $pattern, int $timestamp): void
    {
        $files = glob($dir . '/' . $pattern);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if (is_file($file) && (int) filemtime($file) < $timestamp) {
                @unlink($file);
            }
        }
    }
}
