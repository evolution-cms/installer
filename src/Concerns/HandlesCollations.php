<?php namespace EvolutionCMS\Installer\Concerns;

use PDO;
use PDOException;
use EvolutionCMS\Installer\Utilities\Console;

trait HandlesCollations
{
    /**
     * Get available collations from database server.
     *
     * @param PDO $dbh
     * @return array
     */
    protected function getAvailableCollations(PDO $dbh): array
    {
        $collations = [];
        
        try {
            $result = $dbh->query('SHOW COLLATION');
            if ($result) {
                foreach ($result as $row) {
                    $collations[$row[0]] = $row[0];
                }
            }
        } catch (PDOException $e) {
            Console::warning('Could not retrieve available collations: ' . $e->getMessage());
        }

        return $collations;
    }

    /**
     * Get database collation.
     *
     * @param PDO $dbh
     * @return string|null
     */
    protected function getDatabaseCollation(PDO $dbh): ?string
    {
        try {
            $result = $dbh->query("SHOW VARIABLES LIKE 'collation_database'");
            if ($result && $result->errorCode() == 0) {
                $data = $result->fetch(PDO::FETCH_ASSOC);
                return $data['Value'] ?? null;
            }
        } catch (PDOException $e) {
            Console::warning('Could not retrieve database collation: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Check if collation is available in the server.
     *
     * @param PDO $dbh
     * @param string $collation
     * @return bool
     */
    protected function isCollationAvailable(PDO $dbh, string $collation): bool
    {
        try {
            $stmt = $dbh->prepare("SHOW COLLATION WHERE Collation = ?");
            $stmt->execute([$collation]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Resolve collation for database - uses database collation if selected one is not available.
     *
     * This handles the case when database has a collation (like utf8mb4_uca1400_ai_ci)
     * that is not in the list of available collations through SHOW COLLATION.
     *
     * @param PDO $dbh
     * @param string $selectedCollation
     * @return string
     */
    protected function resolveCollation(PDO $dbh, string $selectedCollation): string
    {
        $databaseActualCollation = $this->getDatabaseCollation($dbh);

        // If collations match, use the selected one
        if ($databaseActualCollation === $selectedCollation) {
            return $selectedCollation;
        }

        // Check if selected collation is available
        $selectedAvailable = $this->isCollationAvailable($dbh, $selectedCollation);

        // Check if database collation is available
        $databaseAvailable = $databaseActualCollation ? $this->isCollationAvailable($dbh, $databaseActualCollation) : false;

        // If database collation is not available in SHOW COLLATION list,
        // but database already has it, use database collation
        if ($databaseActualCollation && !$databaseAvailable && !$selectedAvailable) {
            Console::info("Using database collation: {$databaseActualCollation} (not in available list)");
            return $databaseActualCollation;
        }

        // If selected collation is available, use it
        if ($selectedAvailable) {
            return $selectedCollation;
        }

        // If database collation is available and selected one is not, use database collation
        if ($databaseActualCollation && $databaseAvailable) {
            Console::warning("Selected collation '{$selectedCollation}' not available. Using database collation: {$databaseActualCollation}");
            return $databaseActualCollation;
        }

        // Default fallback
        return $selectedCollation ?: 'utf8mb4_unicode_ci';
    }

    /**
     * Get recommended collation based on database type and charset.
     *
     * @param string $databaseType
     * @param string $charset
     * @return string
     */
    protected function getRecommendedCollation(string $databaseType, string $charset = 'utf8mb4'): string
    {
        if ($databaseType === 'pgsql') {
            return 'utf8';
        }

        $recommended = [
            'utf8mb4' => 'utf8mb4_unicode_ci',
            'utf8' => 'utf8_general_ci',
        ];

        return $recommended[$charset] ?? 'utf8mb4_unicode_ci';
    }

    /**
     * Get charset from collation.
     *
     * @param string $collation
     * @return string
     */
    protected function getCharsetFromCollation(string $collation): string
    {
        $pos = strpos($collation, '_');
        return $pos !== false ? substr($collation, 0, $pos) : 'utf8mb4';
    }
}

