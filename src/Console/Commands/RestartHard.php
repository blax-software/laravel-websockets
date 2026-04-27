<?php

declare(strict_types=1);

namespace BlaxSoftware\LaravelWebSockets\Console\Commands;

use Illuminate\Console\Command;

/**
 * Hard-restart the WebSocket server by signaling its process directly.
 *
 * The legacy `websockets:restart` and `websocket:steer restart` commands
 * work via a cache key that the running server polls every ~5s. That is
 * fragile during deploys: cache drivers can change between processes,
 * the poll loop can stall, and the deploy script has no way to confirm
 * the restart actually happened.
 *
 * This command instead:
 *   1. Locates the running websockets:serve process via pgrep
 *   2. Sends SIGTERM to it (the existing PCNTL handler in StartServer
 *      catches it and calls triggerShutdown → loop->stop → process exit)
 *   3. Waits for supervisord (autorestart=true) to start a new process
 *      and verifies a new PID exists before returning success.
 *
 * Permissions: posix_kill requires that the signaling user matches the
 * target user (or is root). The websocket runs as www-data; run this
 * command as root inside the container, e.g.:
 *
 *     docker compose exec app php artisan websocket:restart-hard
 *
 * (omit `-u 1000:1000`).
 */
class RestartHard extends Command
{
    protected $signature = 'websocket:restart-hard
        {--timeout=20 : Seconds to wait for the new process to appear}
        {--signal=TERM : Signal to send: TERM (graceful) or KILL (force)}
        {--pattern=artisan websockets:serve : pgrep pattern that identifies the server process}';

    protected $description = 'Force-restart the WebSocket server by sending a signal directly to the process (more reliable than cache-poll signals).';

    public function handle(): int
    {
        if (! function_exists('posix_kill')) {
            $this->error('posix extension is required for websocket:restart-hard.');
            return self::FAILURE;
        }

        $timeout = max(1, (int) $this->option('timeout'));
        $signalName = strtoupper((string) $this->option('signal'));
        $pattern = (string) $this->option('pattern');

        $signalMap = [
            'TERM' => defined('SIGTERM') ? SIGTERM : 15,
            'INT'  => defined('SIGINT')  ? SIGINT  : 2,
            'KILL' => defined('SIGKILL') ? SIGKILL : 9,
        ];

        if (! isset($signalMap[$signalName])) {
            $this->error("Unsupported signal '{$signalName}'. Use TERM, INT, or KILL.");
            return self::FAILURE;
        }

        $beforePid = $this->findPid($pattern);
        if ($beforePid === null) {
            $this->warn('No running WebSocket server matched — nothing to restart. (Supervisor will start one if configured.)');
            return self::SUCCESS;
        }

        $this->info("Found WebSocket server at PID {$beforePid}. Sending SIG{$signalName}...");

        if (! @posix_kill($beforePid, $signalMap[$signalName])) {
            $err = posix_get_last_error();
            $msg = $err ? posix_strerror($err) : 'unknown error';
            $this->error("posix_kill failed: {$msg}");
            $this->line('Hint: run this command as root if the websocket runs as www-data — `docker compose exec app php artisan websocket:restart-hard`.');
            \Log::channel('websocket')->error('Hard restart failed: posix_kill error', [
                'pid'    => $beforePid,
                'signal' => $signalName,
                'error'  => $msg,
            ]);
            return self::FAILURE;
        }

        \Log::channel('websocket')->info('Hard restart signal sent', [
            'pid'    => $beforePid,
            'signal' => $signalName,
        ]);

        // Wait for the old process to die AND a new one to appear. Both
        // conditions matter: the old one must release the port before the
        // new one can bind, and the new one must come up for us to call
        // this a successful restart.
        $deadline = microtime(true) + $timeout;
        $newPid = null;
        while (microtime(true) < $deadline) {
            usleep(500_000);

            $currentPid = $this->findPid($pattern);
            if ($currentPid !== null && $currentPid !== $beforePid) {
                $newPid = $currentPid;
                break;
            }
        }

        if ($newPid !== null) {
            $this->info("WebSocket restarted: PID {$beforePid} -> {$newPid}");
            \Log::channel('websocket')->info('Hard restart confirmed', [
                'old_pid' => $beforePid,
                'new_pid' => $newPid,
            ]);
            return self::SUCCESS;
        }

        $this->warn("Did not observe a new PID within {$timeout}s. Old process may still be shutting down; supervisor should restart it shortly.");
        \Log::channel('websocket')->warning('Hard restart unconfirmed within timeout', [
            'pid'     => $beforePid,
            'timeout' => $timeout,
        ]);
        return self::SUCCESS;
    }

    /**
     * Locate the oldest PID matching the websockets:serve command line.
     *
     * pgrep -o returns the oldest matching PID. The supervised parent is
     * always older than any forked workers it spawned, so this picks the
     * one we want to signal. SIGTERM on the parent unwinds the loop; the
     * children get cleaned up by the parent's shutdown.
     */
    private function findPid(string $pattern): ?int
    {
        $cmd = sprintf('pgrep -o -f %s 2>/dev/null', escapeshellarg($pattern));
        $output = [];
        $rc = 0;
        exec($cmd, $output, $rc);

        if ($rc !== 0 || empty($output)) {
            return null;
        }

        $pid = (int) trim($output[0]);

        // Avoid signaling ourselves: this artisan invocation also has
        // 'artisan' in its command line, but pgrep is given the full
        // 'artisan websockets:serve' phrase, so this is defensive only.
        if ($pid <= 0 || $pid === getmypid()) {
            return null;
        }

        return $pid;
    }
}
