<?php namespace EvolutionCMS\Installer\Utilities;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class VersionResolver
{
    /**
     * GitHub repository for Evolution CMS
     */
    protected const GITHUB_REPO = 'evolution-cms/evolution';

    /**
     * GitHub API base URL
     */
    protected const GITHUB_API = 'https://api.github.com';

    /**
     * Get the latest compatible version of Evolution CMS for current PHP version.
     *
     * @param string|null $phpVersion PHP version (default: current version)
     * @param bool $silent If true, suppress console output
     * @return string|null Latest compatible version tag or null
     */
    public function getLatestCompatibleVersion(?string $phpVersion = null, bool $silent = false): ?string
    {
        $phpVersion = $phpVersion ?? PHP_VERSION;
        
        if (!$silent) {
            Console::info("Checking compatible Evolution CMS versions for PHP {$phpVersion}...");
        }

        try {
            $releases = $this->fetchReleases();
            
            foreach ($releases as $release) {
                $version = $release['tag_name'] ?? null;
                
                if (!$version) {
                    continue;
                }

                // Skip pre-releases and development versions
                if ($this->isPreRelease($version)) {
                    continue;
                }

                // Check if this version is compatible
                if ($this->isCompatible($version, $phpVersion)) {
                    if (!$silent) {
                        Console::success("Found compatible version: {$version}");
                    }
                    return $version;
                }
            }

            if (!$silent) {
                Console::warning("No compatible version found for PHP {$phpVersion}.");
            }
            return null;
        } catch (\Exception $e) {
            if (!$silent) {
                Console::error("Failed to resolve version: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Fetch releases from GitHub API.
     *
     * @return array
     * @throws GuzzleException
     */
    protected function fetchReleases(): array
    {
        $client = new Client([
            'base_uri' => self::GITHUB_API,
            'timeout' => 25,
        ]);

        $response = $client->get("/repos/" . self::GITHUB_REPO . "/releases", [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'EvolutionCMS-Installer',
            ],
            'query' => [
                'per_page' => 50, // Get last 50 releases
                'page' => 1,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Check if version is a pre-release.
     *
     * @param string $version
     * @return bool
     */
    protected function isPreRelease(string $version): bool
    {
        // Skip alpha, beta, RC, dev versions
        return preg_match('/-(alpha|beta|rc|dev)/i', $version) === 1;
    }

    /**
     * Check if Evolution CMS version is compatible with PHP version.
     *
     * @param string $evoVersion
     * @param string $phpVersion
     * @return bool
     */
    protected function isCompatible(string $evoVersion, string $phpVersion): bool
    {
        try {
            // Try to get PHP requirements from GitHub API
            $phpRequirement = $this->getPhpRequirement($evoVersion);
            
            if (!$phpRequirement) {
                // If we can't determine, assume latest versions need PHP 8.3+
                // This is a fallback for older releases that might not have composer.json easily accessible
                return version_compare($phpVersion, '8.3.0', '>=');
            }

                return $this->satisfiesVersion($phpVersion, $phpRequirement);
        } catch (\Exception $e) {
            // On error, default to checking if PHP is 8.3+
            // Suppress warning in silent mode
            return version_compare($phpVersion, '8.3.0', '>=');
        }
    }

    /**
     * Get PHP requirement from composer.json in release.
     *
     * @param string $version
     * @return string|null
     */
    protected function getPhpRequirement(string $version): ?string
    {
        try {
            $client = new Client([
                'base_uri' => 'https://raw.githubusercontent.com',
                'timeout' => 25,
            ]);

            // Try to get composer.json from the release tag
            $response = $client->get("/" . self::GITHUB_REPO . "/{$version}/core/composer.json", [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'EvolutionCMS-Installer',
                ],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() === 200) {
                $composer = json_decode($response->getBody()->getContents(), true);
                return $composer['require']['php'] ?? null;
            }

            // Fallback: try root composer.json
            $response = $client->get("/" . self::GITHUB_REPO . "/{$version}/composer.json", [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'EvolutionCMS-Installer',
                ],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() === 200) {
                $composer = json_decode($response->getBody()->getContents(), true);
                return $composer['require']['php'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if PHP version satisfies the requirement (simplified Composer constraint).
     *
     * @param string $phpVersion
     * @param string $requirement e.g., "^8.3", ">=8.3.0", "~8.3.0"
     * @return bool
     */
    protected function satisfiesVersion(string $phpVersion, string $requirement): bool
    {
        // Remove whitespace
        $requirement = trim($requirement);
        
        // Handle multiple constraints (space-separated OR)
        if (strpos($requirement, '|') !== false) {
            $constraints = explode('|', $requirement);
            foreach ($constraints as $constraint) {
                if ($this->satisfiesVersion($phpVersion, trim($constraint))) {
                    return true;
                }
            }
            return false;
        }

        // Handle ^ constraint (e.g., ^8.3 means >=8.3.0 <9.0.0)
        if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?/', $requirement, $matches)) {
            $major = (int)$matches[1];
            $minor = (int)$matches[2];
            $patch = isset($matches[3]) ? (int)$matches[3] : 0;
            
            $minVersion = "{$major}.{$minor}.{$patch}";
            $nextMajor = $major + 1;
            $maxVersion = "{$nextMajor}.0.0";
            
            return version_compare($phpVersion, $minVersion, '>=') && 
                   version_compare($phpVersion, $maxVersion, '<');
        }

        // Handle ~ constraint (e.g., ~8.3.0 means >=8.3.0 <8.4.0)
        if (preg_match('/^~(\d+)\.(\d+)\.(\d+)/', $requirement, $matches)) {
            $major = (int)$matches[1];
            $minor = (int)$matches[2];
            $patch = (int)$matches[3];
            
            $minVersion = "{$major}.{$minor}.{$patch}";
            $nextMinor = $minor + 1;
            $maxVersion = "{$major}.{$nextMinor}.0";
            
            return version_compare($phpVersion, $minVersion, '>=') && 
                   version_compare($phpVersion, $maxVersion, '<');
        }

        // Handle >= constraint
        if (preg_match('/^>=([\d.]+)/', $requirement, $matches)) {
            return version_compare($phpVersion, $matches[1], '>=');
        }

        // Handle > constraint
        if (preg_match('/^>([\d.]+)/', $requirement, $matches)) {
            return version_compare($phpVersion, $matches[1], '>');
        }

        // Handle = constraint or exact version
        if (preg_match('/^=?([\d.]+)/', $requirement, $matches)) {
            return version_compare($phpVersion, $matches[1], '>=');
        }

        // Default: try direct comparison
        return version_compare($phpVersion, $requirement, '>=');
    }

    /**
     * Get download URL for a specific version.
     *
     * @param string $version
     * @return string
     */
    public function getDownloadUrl(string $version): string
    {
        return "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$version}.zip";
    }

    /**
     * Get download URL for a specific branch.
     *
     * @param string $branch
     * @return string
     */
    public function getBranchDownloadUrl(string $branch): string
    {
        return "https://github.com/" . self::GITHUB_REPO . "/archive/refs/heads/{$branch}.zip";
    }

    /**
     * Get latest version (without compatibility check).
     *
     * @return string|null
     */
    public function getLatestVersion(): ?string
    {
        try {
            $releases = $this->fetchReleases();
            
            foreach ($releases as $release) {
                $version = $release['tag_name'] ?? null;
                
                if ($version && !$this->isPreRelease($version)) {
                    return $version;
                }
            }

            return null;
        } catch (\Exception $e) {
            Console::error("Failed to get latest version: " . $e->getMessage());
            return null;
        }
    }
}
