<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/** Admin-triggered Composer preview and update, using the configured constraint. */
final class PluginUpdater
{
    /** @return array{state: string, message: string, fingerprint?: string} */
    public function check(): array
    {
        return $this->locked(function (): array {
            $fingerprint = $this->fingerprint();
            if ($fingerprint === null) {
                return ['state' => 'error', 'message' => 'The plugin update source is missing or differs from the installed Composer configuration.'];
            }
            if ($this->updaterBusy()) {
                return ['state' => 'error', 'message' => 'A LibreNMS or Composer update is already running. Try again when it finishes.'];
            }
            // Calling composer_wrapper.php here could self-update Composer even
            // during a dry run. Invoke the installed Composer directly.
            $composer = is_file(base_path('composer.phar')) ? ['php', base_path('composer.phar')] : ['composer'];
            $process = $this->run([...$composer, 'update', Status::PACKAGE, '--dry-run', '--no-dev', '--no-scripts', '--no-plugins', '--no-audit', '--no-ansi', '--no-interaction'], 120);
            $result = UpdateProbe::result($process->getExitCode() ?? 1, $process->getOutput() . "\n" . $process->getErrorOutput());
            if ($result['state'] === 'available') {
                $result['fingerprint'] = $fingerprint;
            }
            return $result;
        });
    }

    /** @return array{state: string, message: string} */
    public function install(?string $fingerprint): array
    {
        return $this->locked(function () use ($fingerprint): array {
            if ($fingerprint === null || ! hash_equals($this->fingerprint() ?? '', $fingerprint)) {
                return ['state' => 'error', 'message' => 'The installed version or update source changed. Check for updates again.'];
            }
            if ($this->updaterBusy()) {
                return ['state' => 'error', 'message' => 'A LibreNMS or Composer update is already running. Try again when it finishes.'];
            }
            // The shell script contains its body in a function so Composer can
            // replace the package while this process is running. No user input
            // is passed as an executable, path, or version constraint.
            $script = base_path('vendor/' . Status::PACKAGE . '/scripts/update.sh');
            if (! is_file($script)) {
                return ['state' => 'error', 'message' => 'The update script is unavailable. Check the plugin installation.'];
            }
            $process = $this->run(['/bin/sh', $script], 600);
            if (! $process->isSuccessful()) {
                Log::warning('ThemeSelector: plugin update failed', ['exit' => $process->getExitCode()]);
                return ['state' => 'error', 'message' => 'The update did not complete. Check the server permissions and LibreNMS logs before retrying.'];
            }
            // An FPM worker can still have Composer's previous installed.php
            // cached after a successful update; avoid reporting a stale version.
            return ['state' => 'success', 'message' => 'The update completed. The installed version may take a moment to appear.'];
        });
    }

    private function fingerprint(): ?string
    {
        $plugins = json_decode((string) @file_get_contents(base_path('composer.plugins.json')), true);
        $composer = json_decode((string) @file_get_contents(base_path('composer.json')), true);
        $constraint = $plugins['require'][Status::PACKAGE] ?? null;
        $installed = Status::installedVersion();
        if (! is_string($constraint) || $constraint === '' || strlen($constraint) > 256
            || ($composer['require'][Status::PACKAGE] ?? null) !== $constraint || $installed === null) {
            return null;
        }
        return hash('sha256', $constraint . "\0" . $installed);
    }

    private function updaterBusy(): bool
    {
        $process = $this->run(['pgrep', '-f', '[d]aily\\.sh|[p]hp.*composer'], 5);
        if ($process->getExitCode() === 0) {
            return true;
        }
        if ($process->getExitCode() !== 1) {
            throw new \RuntimeException('Unable to inspect running update processes');
        }
        return false;
    }

    private function run(array $command, int $timeout): Process
    {
        // Symfony's Process also inherits PHP's server variables. Request
        // headers (especially HTTP_PROXY) must never become process settings.
        // PHP-FPM commonly clears PATH. Composer's subprocesses need to find
        // Git and PHP even when the web worker inherited no search path.
        $path = getenv('PATH', true);
        $env = [
            'COMPOSER_NO_INTERACTION' => '1', 'LIBRENMS_DIR' => base_path(),
            'PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        foreach (array_keys($_SERVER) as $key) {
            if (str_starts_with($key, 'HTTP_') || str_starts_with($key, 'PHP_AUTH_')) {
                $env[$key] = false;
            }
        }
        $httpProxy = getenv('HTTP_PROXY', true);
        if (is_string($httpProxy) && $httpProxy !== '') {
            $env['HTTP_PROXY'] = $httpProxy;
        }
        $process = new Process($command, base_path(), $env);
        $process->setTimeout($timeout);
        $process->run();
        return $process;
    }

    private function locked(callable $callback): array
    {
        $handle = null;
        try {
            // Composer can clear the application cache (including Redis), so
            // a cache lock may disappear while an update is still running.
            $handle = fopen(storage_path('framework/theme-selector-update.lock'), 'c');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open update lock');
            }
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return ['state' => 'error', 'message' => 'A Theme Selector update check or installation is already running.'];
            }
            return $callback();
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: plugin update operation failed', ['reason' => get_class($e)]);
            return ['state' => 'error', 'message' => 'The update operation could not finish. Check the server configuration and try again.'];
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
