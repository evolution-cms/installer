<?php namespace EvolutionCMS\Installer\Process;

use EvolutionCMS\Installer\Utilities\Console;

class CreatesDatabaseConfig
{
    /**
     * Create database configuration file.
     *
     * @param string $projectPath
     * @param array $config
     * @return bool
     */
    public function __invoke(string $projectPath, array $config): bool
    {
        $dbConfig = $config['database'];
        
        // Map database type and set defaults
        $dbType = $dbConfig['type'];
        $dbPort = $dbConfig['port'] ?? $this->getDefaultPort($dbType);
        $charset = $dbConfig['charset'] ?? $this->getDefaultCharset($dbType);
        $collation = $dbConfig['collation'] ?? $this->getDefaultCollation($dbType, $charset);
        $engine = $this->getDatabaseEngine($dbType);
        $method = $dbConfig['method'] ?? 'SET CHARACTER SET';
        $tablePrefix = $dbConfig['prefix'] ?? 'evo_';

        $configPath = $projectPath . '/core/config/database/connections';
        
        if (!is_dir($configPath)) {
            mkdir($configPath, 0755, true);
        }

        $configFile = $configPath . '/default.php';
        
        $configContent = $this->generateConfigContent([
            'driver' => $dbType,
            'host' => $dbConfig['host'] ?? '',
            'port' => $dbPort,
            'database' => $dbConfig['name'],
            'username' => $dbConfig['user'] ?? '',
            'password' => $dbConfig['password'] ?? '',
            'charset' => $charset,
            'collation' => $collation,
            'prefix' => $tablePrefix,
            'method' => $method,
            'engine' => $engine,
        ]);

        if (file_put_contents($configFile, $configContent) === false) {
            Console::error("Failed to create database configuration file.");
            return false;
        }

        // Set secure permissions (read-only for owner and group)
        chmod($configFile, 0404);

        Console::success("Database configuration created successfully!");
        return true;
    }

    /**
     * Generate configuration file content.
     *
     * @param array $params
     * @return string
     */
    protected function generateConfigContent(array $params): string
    {
        $engineCode = $params['engine'] ? ", '{$params['engine']}'" : '';
        $driver = $params['driver'];
        
        // SQLite doesn't need host, port, username, password
        if ($driver === 'sqlite') {
            return <<<PHP
<?php
return [
    'driver' => env('DB_TYPE', '{$driver}'),
    'database' => env('DB_DATABASE', '{$params['database']}'),
    'prefix' => env('DB_PREFIX', '{$params['prefix']}'),
    'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
    'options' => [
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]
];
PHP;
        }
        
        return <<<PHP
<?php
return [
    'driver' => env('DB_TYPE', '{$driver}'),
    'host' => env('DB_HOST', '{$params['host']}'),
    'port' => env('DB_PORT', '{$params['port']}'),
    'database' => env('DB_DATABASE', '{$params['database']}'),
    'username' => env('DB_USERNAME', '{$params['username']}'),
    'password' => env('DB_PASSWORD', '{$params['password']}'),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', '{$params['charset']}'),
    'collation' => env('DB_COLLATION', '{$params['collation']}'),
    'prefix' => env('DB_PREFIX', '{$params['prefix']}'),
    'method' => env('DB_METHOD', '{$params['method']}'),
    'strict' => env('DB_STRICT', false),
    'engine' => env('DB_ENGINE'{$engineCode}),
    'options' => [
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]
];
PHP;
    }

    /**
     * Get default port for database type.
     *
     * @param string $type
     * @return int
     */
    protected function getDefaultPort(string $type): ?int
    {
        return match($type) {
            'pgsql' => 5432,
            'mysql' => 3306,
            'sqlsrv' => 1433,
            'sqlite' => null, // SQLite doesn't use port
            default => 3306,
        };
    }

    /**
     * Get default charset for database type.
     *
     * @param string $type
     * @return string
     */
    protected function getDefaultCharset(string $type): string
    {
        return match($type) {
            'pgsql' => 'utf8',
            'mysql' => 'utf8mb4',
            'sqlite' => 'utf8',
            'sqlsrv' => 'utf8',
            default => 'utf8mb4',
        };
    }

    /**
     * Get default collation for database type and charset.
     *
     * @param string $type
     * @param string $charset
     * @return string
     */
    protected function getDefaultCollation(string $type, string $charset): string
    {
        if ($type === 'pgsql') {
            return 'utf8';
        }

        if ($type === 'sqlite') {
            return 'utf8'; // SQLite doesn't use collation in the same way
        }

        if ($type === 'sqlsrv') {
            return 'SQL_Latin1_General_CP1_CI_AS'; // Default SQL Server collation
        }

        return match($charset) {
            'utf8mb4' => 'utf8mb4_unicode_520_ci',
            'utf8' => 'utf8_general_ci',
            default => 'utf8mb4_unicode_520_ci',
        };
    }

    /**
     * Get database engine based on type.
     *
     * @param string $type
     * @return string
     */
    protected function getDatabaseEngine(string $type): string
    {
        return match($type) {
            'pgsql' => '',
            'mysql' => 'innodb',
            'sqlite' => '',
            'sqlsrv' => '',
            default => 'innodb',
        };
    }
}

