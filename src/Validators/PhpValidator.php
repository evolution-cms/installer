<?php namespace EvolutionCMS\Installer\Validators;

use EvolutionCMS\Installer\Utilities\Console;

class PhpValidator
{
    /**
     * Minimum required PHP version for Evolution CMS
     */
    protected const MIN_PHP_VERSION = '8.3.0';

    /**
     * Validate PHP version meets requirements.
     *
     * @param string|null $version PHP version to check (default: current)
     * @return bool
     */
    public function validate(?string $version = null): bool
    {
        $version = $version ?? PHP_VERSION;
        
        if (version_compare($version, self::MIN_PHP_VERSION, '<')) {
            Console::error("PHP version {$version} is not supported.");
            Console::info("Evolution CMS requires PHP " . self::MIN_PHP_VERSION . " or higher.");
            Console::info("Please upgrade your PHP version and try again.");
            return false;
        }

        // Don't output here - let caller handle display through TUI
        return true;
    }

    /**
     * Get required PHP version.
     *
     * @return string
     */
    public function getRequiredVersion(): string
    {
        return self::MIN_PHP_VERSION;
    }

    /**
     * Check if current PHP version is supported.
     *
     * @return bool
     */
    public function isSupported(): bool
    {
        return $this->validate();
    }
}

