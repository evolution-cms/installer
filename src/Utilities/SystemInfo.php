<?php namespace EvolutionCMS\Installer\Utilities;

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
        $process = new \Symfony\Component\Process\Process(['composer', '--version']);
        $process->run();
        
        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            if (preg_match('/Composer version (\S+)/', $output, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
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
