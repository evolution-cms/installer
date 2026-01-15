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
        $host = $config['host'] ?? '';
        $port = $config['port'] ?? null;
        $database = $config['name'] ?? null;
        $user = $config['user'] ?? '';
        $password = $config['password'] ?? '';

        $dsn = $this->buildDsn($type, $host, $port, $database);

        try {
            // SQLite doesn't need username/password
            if ($type === 'sqlite') {
                $dbh = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } else {
                $dbh = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            }

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
        if ($type === 'sqlite') {
            // SQLite: database is the path to the file
            $path = $database ?: ':memory:';
            return "sqlite:{$path}";
        }

        if ($type === 'sqlsrv') {
            // SQL Server
            $dsn = "sqlsrv:Server={$host}";
            if ($port) {
                $dsn .= ",{$port}";
            }
            if ($database) {
                $dsn .= ";Database={$database}";
            }
            return $dsn;
        }

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

        // MySQL / MariaDB
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
        $type = $config['type'];
        $databaseName = $config['name'];

        if ($type === 'sqlite') {
            // SQLite: Create database file
            $databasePath = $databaseName ?: 'database.sqlite';
            $directory = dirname($databasePath);
            
            if (!empty($directory) && !is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Create empty file (PDO will create the database when connecting)
            if (!file_exists($databasePath)) {
                touch($databasePath);
                chmod($databasePath, 0666);
                Console::success("Database file '{$databasePath}' created successfully!");
            } else {
                Console::info("Database file '{$databasePath}' already exists.");
            }
            
            return true;
        }

        if ($type === 'sqlsrv') {
            // SQL Server: Connect to master database to create new database
            $configWithoutDb = $config;
            $configWithoutDb['name'] = 'master';
            
            try {
                $dbh = $this->createConnection($configWithoutDb);
                $charset = $this->getCharsetFromCollation($collation);
                
                // Check if database exists
                $checkQuery = "SELECT name FROM sys.databases WHERE name = '{$databaseName}'";
                $result = $dbh->query($checkQuery);
                
                if ($result->rowCount() === 0) {
                    $query = "CREATE DATABASE [{$databaseName}] COLLATE {$collation}";
                    $dbh->exec($query);
                    Console::success("Database '{$databaseName}' created successfully!");
                } else {
                    Console::info("Database '{$databaseName}' already exists.");
                }
                
                return true;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    Console::info("Database '{$databaseName}' already exists.");
                    return true;
                }
                
                Console::error("Failed to create database: " . $e->getMessage());
                return false;
            }
        }

        // MySQL / PostgreSQL
        $configWithoutDb = $config;
        unset($configWithoutDb['name']);
        if ($type === 'pgsql') {
            // PostgreSQL requires a database name; otherwise it defaults to using the username.
            // Use a maintenance database for CREATE DATABASE operations.
            $configWithoutDb['name'] = 'postgres';
        }

        try {
            $dbh = $this->createConnection($configWithoutDb);
            $charset = $this->getCharsetFromCollation($collation);

            if ($type === 'pgsql') {
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
                // MySQL / MariaDB: Use IF NOT EXISTS
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
