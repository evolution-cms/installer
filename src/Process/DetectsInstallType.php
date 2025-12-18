<?php namespace EvolutionCMS\Installer\Process;

use PDO;
use PDOException;
use EvolutionCMS\Installer\Utilities\Console;

class DetectsInstallType
{
    /**
     * Detect if installation is fresh install or update.
     *
     * Returns:
     * - 1 for fresh install (no tables exist)
     * - 2 for update (tables exist)
     *
     * @param PDO $dbh
     * @param string $tablePrefix
     * @param string $databaseType
     * @return int
     */
    public function __invoke(PDO $dbh, string $tablePrefix, string $databaseType): int
    {
        try {
            // Check if site_content table exists (main Evolution CMS table)
            $tableName = $databaseType === 'pgsql' 
                ? '"' . $tablePrefix . 'site_content"'
                : '`' . $tablePrefix . 'site_content`';

            $query = "SELECT COUNT(*) FROM {$tableName} LIMIT 1";
            $dbh->query($query);
            
            // If query succeeds, table exists - this is an update
            Console::info("Existing Evolution CMS installation detected. This will be an update.");
            return 2;
        } catch (PDOException $e) {
            // Table doesn't exist - this is a fresh install
            Console::info("No existing installation detected. This will be a fresh install.");
            return 1;
        }
    }
}

