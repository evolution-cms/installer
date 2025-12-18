<?php namespace EvolutionCMS\Installer\Concerns;

use PDO;
use PDOException;
use EvolutionCMS\Installer\Utilities\Console;

trait ConfiguresDatabase
{
    use HandlesCollations;

    /**
     * Create database connection.
     *
     * @param array $config
     * @return PDO
     */
    protected function createConnection(array $config): PDO
    {
        $type = $config['type'];
        $host = $config['host'];
        $port = $config['port'] ?? null;
        $database = $config['name'] ?? null;
        $user = $config['user'];
        $password = $config['password'] ?? '';

        $dsn = $this->buildDsn($type, $host, $port, $database);

        try {
            $dbh = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            return $dbh;
        } catch (PDOException $e) {
            Console::error("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build DSN string.
     *
     * @param string $type
     * @param string $host
     * @param int|null $port
     * @param string|null $database
     * @return string
     */
    protected function buildDsn(string $type, string $host, ?int $port, ?string $database): string
    {
        if ($type === 'pgsql') {
            $dsn = "pgsql:host={$host}";
            if ($port) {
                $dsn .= ";port={$port}";
            }
            if ($database) {
                $dsn .= ";dbname={$database}";
            }
            return $dsn;
        }

        // MySQL
        $dsn = "mysql:host={$host}";
        if ($port) {
            $dsn .= ";port={$port}";
        }
        if ($database) {
            $dsn .= ";dbname={$database}";
        }
        
        return $dsn;
    }

    /**
     * Test database connection.
     *
     * @param array $config
     * @return bool
     */
    protected function testConnection(array $config): bool
    {
        try {
            $dbh = $this->createConnection($config);
            Console::success("Database connection successful!");
            return true;
        } catch (PDOException $e) {
            Console::error("Database connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create database if it doesn't exist.
     *
     * @param array $config
     * @param string $collation
     * @return bool
     */
    protected function createDatabase(array $config, string $collation): bool
    {
        $configWithoutDb = $config;
        unset($configWithoutDb['name']);

        try {
            $dbh = $this->createConnection($configWithoutDb);
            
            $databaseName = $config['name'];
            $charset = $this->getCharsetFromCollation($collation);

            if ($config['type'] === 'pgsql') {
                // PostgreSQL: Check if database exists first
                $checkQuery = "SELECT 1 FROM pg_database WHERE datname = '{$databaseName}'";
                $result = $dbh->query($checkQuery);
                
                if ($result->rowCount() === 0) {
                    // PostgreSQL doesn't use collation in CREATE DATABASE
                    $query = "CREATE DATABASE \"{$databaseName}\" ENCODING '{$charset}'";
                    $dbh->exec($query);
                    Console::success("Database '{$databaseName}' created successfully!");
                } else {
                    Console::info("Database '{$databaseName}' already exists.");
                }
            } else {
                // MySQL: Use IF NOT EXISTS
                $query = "CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET {$charset} COLLATE {$collation}";
                $dbh->exec($query);
                Console::success("Database '{$databaseName}' created successfully!");
            }
            
            return true;
        } catch (PDOException $e) {
            // Database might already exist, which is okay
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'duplicate') !== false) {
                Console::info("Database '{$config['name']}' already exists.");
                return true;
            }
            
            Console::error("Failed to create database: " . $e->getMessage());
            return false;
        }
    }
}

