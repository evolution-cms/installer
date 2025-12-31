<?php namespace EvolutionCMS\Installer\Utilities;

use Symfony\Component\Process\Process;

class SystemInfo
{
    /**
     * Get operating system information.
     *
     * @return string
     */
    public static function getOS(): string
    {
        $os = php_uname('s');
        $version = php_uname('r');
        $arch = php_uname('m');

        // Format OS name
        $osName = match(true) {
            str_contains(strtolower($os), 'darwin') => 'macOS',
            str_contains(strtolower($os), 'linux') => 'Linux',
            str_contains(strtolower($os), 'win') => 'Windows',
            default => $os,
        };

        return sprintf('%s - %s (%s)', $osName, $version, $arch);
    }

    /**
     * Get PHP version.
     *
     * @return string
     */
    public static function getPhpVersion(): string
    {
        return PHP_VERSION;
    }

    /**
     * Get memory limit.
     *
     * @return string
     */
    public static function getMemoryLimit(): string
    {
        $limit = ini_get('memory_limit');
        return $limit ?: 'Unknown';
    }

    /**
     * Check if extension is loaded.
     *
     * @param string $extension
     * @return bool
     */
    public static function hasExtension(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /**
     * Get Composer version if available.
     *
     * @return string|null
     */
    public static function getComposerVersion(): ?string
    {
        // Get home directory
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $userInfo = @posix_getpwuid(posix_geteuid());
            if ($userInfo && isset($userInfo['dir'])) {
                $home = $userInfo['dir'];
            }
        }

        // Try to find composer executable path first
        $composerPath = self::findComposerExecutable();
        if ($composerPath !== null && $composerPath !== 'composer' && file_exists($composerPath)) {
            // If we found a specific path, use it directly
            if (function_exists('shell_exec')) {
                $disabled = ini_get('disable_functions');
                if (!$disabled || stripos($disabled, 'shell_exec') === false) {
                    $output = @shell_exec(escapeshellarg($composerPath) . ' -V 2>&1');
                    if ($output) {
                        $output = trim($output);
                        if (preg_match('/Composer\s+(?:version\s+)?([0-9]+\.[0-9]+\.[0-9]+)/', $output, $matches)) {
                            return $matches[1];
                        }
                    }
                }
            }
        }

        // Try shell_exec with bash and proper PATH (for Hestia and similar environments)
        if (function_exists('shell_exec')) {
            $disabled = ini_get('disable_functions');
            if (!$disabled || stripos($disabled, 'shell_exec') === false) {
                // Try with bash -c to ensure proper shell environment and aliases
                $commands = [
                    'bash -c "source ' . escapeshellarg($home . '/.bash_aliases') . ' 2>/dev/null; composer -V 2>&1"',
                    'bash -c "source ' . escapeshellarg($home . '/.bashrc') . ' 2>/dev/null; composer -V 2>&1"',
                    'bash -c "source ' . escapeshellarg($home . '/.profile') . ' 2>/dev/null; composer -V 2>&1"',
                    'bash -c "export PATH=$PATH:/usr/local/bin:/usr/bin:/bin; composer -V 2>&1"',
                    'composer -V 2>&1',
                ];

                foreach ($commands as $cmd) {
                    $output = @shell_exec($cmd);
                    if ($output) {
                        $output = trim($output);
                        if (preg_match('/Composer\s+(?:version\s+)?([0-9]+\.[0-9]+\.[0-9]+)/', $output, $matches)) {
                            return $matches[1];
                        }
                    }
                }
            }
        }

        // Try exec as fallback
        if (function_exists('exec')) {
            $disabled = ini_get('disable_functions');
            if (!$disabled || stripos($disabled, 'exec') === false) {
                $commands = [
                    'bash -c "composer -V 2>&1"',
                    'composer -V 2>&1',
                ];

                foreach ($commands as $cmd) {
                    $output = [];
                    @exec($cmd, $output);
                    if (!empty($output)) {
                        $outputStr = implode("\n", $output);
                        if (preg_match('/Composer\s+(?:version\s+)?([0-9]+\.[0-9]+\.[0-9]+)/', $outputStr, $matches)) {
                            return $matches[1];
                        }
                    }
                }
            }
        }

        // Try to find composer executable path
        $composerPath = self::findComposerExecutable();
        if ($composerPath === null) {
            return null;
        }

        // Try both -V and --version commands using Process
        $commands = [
            [$composerPath, '-V'],
            [$composerPath, '--version'],
        ];

        foreach ($commands as $command) {
            try {
                $process = new Process($command);
                $process->setTimeout(10);
                // Set environment variables to ensure proper PATH
                $env = $_ENV ?? [];
                $path = getenv('PATH');
                if (!$path && isset($_SERVER['PATH'])) {
                    $path = $_SERVER['PATH'];
                }
                if (!$path) {
                    $path = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin';
                }
                $env['PATH'] = $path;
                $env['HOME'] = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
                $process->setEnv($env);
                $process->run();

                if ($process->isSuccessful()) {
                    // Check both stdout and stderr (composer might output to stderr)
                    $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

                    if (empty($output)) {
                        continue;
                    }

                    // Match both "Composer version X.X.X" and "Composer X.X.X" formats
                    // Also handle formats like "Composer version 2.8.4 2024-12-11 11:57:47"
                    if (preg_match('/Composer\s+(?:version\s+)?([0-9]+\.[0-9]+\.[0-9]+)/', $output, $matches)) {
                        return $matches[1];
                    }

                    // Fallback: try to extract any version-like pattern
                    if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', $output, $matches)) {
                        return $matches[1];
                    }
                }
            } catch (\Exception $e) {
                // Continue to next attempt
                continue;
            }
        }

        return null;
    }

    /**
     * Get Composer executable path/command if available.
     *
     * Returns either a full path (preferred) or the string "composer" if it is
     * available in PATH. Returns null if Composer cannot be found.
     */
    public static function getComposerExecutable(): ?string
    {
        return self::findComposerExecutable();
    }

    /**
     * Find Composer executable path.
     *
     * @return string|null
     */
    private static function findComposerExecutable(): ?string
    {
        // Get home directory first
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');

        // Try to get actual user home from posix_getpwuid if available
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $userInfo = @posix_getpwuid(posix_geteuid());
            if ($userInfo && isset($userInfo['dir'])) {
                $home = $userInfo['dir'];
            }
        }

        // Try reading .bash_aliases for composer alias FIRST (most reliable for Hestia)
        $bashAliases = $home . '/.bash_aliases';
        if (file_exists($bashAliases) && is_readable($bashAliases)) {
            $content = @file_get_contents($bashAliases);
            if ($content) {
                // Try multiple regex patterns to match alias
                $patterns = [
                    '/alias\s+composer\s*=\s*["\']?([^"\'\s]+)["\']?/',  // alias composer=/path
                    '/alias\s+composer\s*=\s*["\']([^"\']+)["\']/',      // alias composer="/path"
                    '/composer\s*=\s*([^\s]+)/',                          // composer=/path (without alias keyword)
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $content, $matches)) {
                        $aliasPath = trim($matches[1]);
                        // Expand ~ to home directory
                        if (str_starts_with($aliasPath, '~')) {
                            $aliasPath = $home . substr($aliasPath, 1);
                        }
                        // Remove quotes if present
                        $aliasPath = trim($aliasPath, '"\'');
                        if ($aliasPath && file_exists($aliasPath) && is_executable($aliasPath)) {
                            return $aliasPath;
                        }
                    }
                }
            }
        }

        // Try common locations (especially /home/user/.composer/composer)
        $commonPaths = [
            $home . '/.composer/composer',  // Direct composer installation (your case)
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            '/bin/composer',
            $home . '/.composer/vendor/bin/composer',
            $home . '/.config/composer/vendor/bin/composer',
            '/opt/hestia/bin/composer',
            '/usr/local/hestia/bin/composer',
        ];

        foreach ($commonPaths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try using 'which' command with bash (for Hestia and similar environments)
        if (function_exists('shell_exec')) {
            $disabled = ini_get('disable_functions');
            if (!$disabled || stripos($disabled, 'shell_exec') === false) {
                $commands = [
                    'bash -c "source ' . escapeshellarg($home . '/.bash_aliases') . ' 2>/dev/null; which composer 2>/dev/null"',
                    'bash -c "source ' . escapeshellarg($home . '/.bashrc') . ' 2>/dev/null; which composer 2>/dev/null"',
                    'bash -c "source ' . escapeshellarg($home . '/.profile') . ' 2>/dev/null; which composer 2>/dev/null"',
                    'bash -c "export PATH=$PATH:/usr/local/bin:/usr/bin:/bin; which composer 2>/dev/null"',
                    'which composer 2>/dev/null',
                ];

                foreach ($commands as $cmd) {
                    $which = @shell_exec($cmd);
                    if ($which) {
                        $which = trim($which);
                        if (!empty($which) && file_exists($which) && is_executable($which)) {
                            return $which;
                        }
                    }
                }
            }
        }

        // Try direct 'composer' command first
        if (self::isExecutableAvailable('composer')) {
            return 'composer';
        }

        $commonPaths = [
            $home . '/.composer/composer',  // Direct composer installation
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            '/bin/composer',
            $home . '/.composer/vendor/bin/composer',
            $home . '/.config/composer/vendor/bin/composer',
            '/opt/hestia/bin/composer',
            '/usr/local/hestia/bin/composer',
        ];

        foreach ($commonPaths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }


        // Try using 'whereis' command
        if (function_exists('shell_exec')) {
            $disabled = ini_get('disable_functions');
            if (!$disabled || stripos($disabled, 'shell_exec') === false) {
                $whereis = @shell_exec('whereis -b composer 2>/dev/null');
                if ($whereis && preg_match('/composer:\s+(\S+)/', $whereis, $matches)) {
                    $path = trim($matches[1]);
                    if (!empty($path) && is_executable($path)) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if executable is available in PATH.
     *
     * @param string $command
     * @return bool
     */
    private static function isExecutableAvailable(string $command): bool
    {
        try {
            $process = new Process([$command, '--version']);
            $process->setTimeout(5);
            $env = $_ENV;
            $env['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
            if (isset($_SERVER['PATH'])) {
                $env['PATH'] = $_SERVER['PATH'];
            }
            $process->setEnv($env);
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get disk free space for current directory.
     *
     * @return string|null
     */
    public static function getDiskFreeSpace(): ?string
    {
        $free = disk_free_space('.');
        if ($free === false) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($free >= 1024 && $unitIndex < count($units) - 1) {
            $free /= 1024;
            $unitIndex++;
        }

        return sprintf('%.1f %s free', round($free, 1), $units[$unitIndex]);
    }
}
