<?php namespace EvolutionCMS\Installer\Concerns;

trait HandlesCollations
{
    /**
     * Get recommended collation based on database type and charset.
     *
     * @param string $databaseType
     * @param string $charset
     * @param string|null $serverVersion
     * @return string
     */
    protected function getRecommendedCollation(string $databaseType, string $charset = 'utf8mb4', ?string $serverVersion = null): string
    {
        if ($databaseType === 'pgsql') {
            return 'utf8';
        }

        if ($databaseType === 'sqlsrv') {
            return 'SQL_Latin1_General_CP1_CI_AS';
        }

        if ($databaseType === 'sqlite') {
            return 'utf8';
        }

        if ($databaseType === 'mysql' && $charset === 'utf8mb4') {
            if ($serverVersion !== null) {
                if (!$this->isMariaDbVersion($serverVersion) && $this->isMySql8OrNewer($serverVersion)) {
                    return 'utf8mb4_0900_ai_ci';
                }
            }

            // Safe default for MySQL < 8 and MariaDB
            return 'utf8mb4_unicode_520_ci';
        }

        if ($charset === 'utf8') {
            return 'utf8_general_ci';
        }

        return 'utf8mb4_unicode_520_ci';
    }

    protected function isMariaDbVersion(string $serverVersion): bool
    {
        return stripos($serverVersion, 'mariadb') !== false;
    }

    protected function isMySql8OrNewer(string $serverVersion): bool
    {
        if (!preg_match('/^(?<major>\\d+)\\.(?<minor>\\d+)/', $serverVersion, $m)) {
            return false;
        }

        $major = (int) $m['major'];
        $minor = (int) $m['minor'];

        return $major > 8 || ($major === 8 && $minor >= 0);
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
