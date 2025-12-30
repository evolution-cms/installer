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
            if (!mkdir($configPath, 0755, true) && !is_dir($configPath)) {
                Console::error("Failed to create database configuration directory: {$configPath}");
                return false;
            }
        }

        $configFile = $configPath . '/default.php';

        if (!file_exists($configFile) && !is_writable($configPath)) {
            Console::error("Database configuration directory is not writable: {$configPath}");
            return false;
        }
        
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

        if (file_exists($configFile) && !is_writable($configFile)) {
            @chmod($configFile, 0644);
            if (!is_writable($configFile)) {
                @unlink($configFile);
            }
        }

        if (file_exists($configFile) && !is_writable($configFile)) {
            Console::error("Database configuration file is not writable and couldn't be removed: {$configFile}");
            return false;
        }

        if (file_put_contents($configFile, $configContent) === false) {
            $error = error_get_last();
            $details = $error['message'] ?? 'unknown error';
            Console::error("Failed to create database configuration file: {$configFile} ({$details})");
            return false;
        }

        // Restrict permissions (owner read/write only; best-effort on Windows mounts)
        @chmod($configFile, 0600);

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
        $rawDriver = (string) $params['driver'];
        $driver = $this->escapePhpString($rawDriver);
        $host = $this->escapePhpString((string) $params['host']);
        $port = $this->escapePhpString((string) ($params['port'] ?? ''));
        $rawDatabase = (string) $params['database'];
        $database = $this->escapePhpString($rawDatabase);
        $username = $this->escapePhpString((string) $params['username']);
        $password = $this->escapePhpString((string) $params['password']);
        $charset = $this->escapePhpString((string) $params['charset']);
        $collation = $this->escapePhpString((string) $params['collation']);
        $prefix = $this->escapePhpString((string) $params['prefix']);
        $method = $this->escapePhpString((string) $params['method']);
        $engine = $this->escapePhpString((string) $params['engine']);
        $foreignKeysLine = $rawDriver === 'sqlite'
            ? "    'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),\n"
            : '';
        $databaseDefault = "'{$database}'";
        if ($rawDriver === 'sqlite' && !$this->isAbsolutePath($rawDatabase)) {
            $rel = ltrim($rawDatabase, "/\\");
            $rel = $this->escapePhpString($rel);
            $databaseDefault = "dirname(__DIR__, 4) . '/{$rel}'";
        }

        return <<<PHP
<?php return [
    'driver' => env('DB_TYPE', '{$driver}'),
    'host' => env('DB_HOST', '{$host}'),
    'port' => env('DB_PORT', '{$port}'),
    'database' => env('DB_DATABASE', {$databaseDefault}),
    'username' => env('DB_USERNAME', '{$username}'),
    'password' => env('DB_PASSWORD', '{$password}'),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', '{$charset}'),
    'collation' => env('DB_COLLATION', '{$collation}'),
    'prefix' => env('DB_PREFIX', '{$prefix}'),
{$foreignKeysLine}    'method' => env('DB_METHOD', '{$method}'),
    'strict' => env('DB_STRICT', false),
    'engine' => env('DB_ENGINE', '{$engine}'),
    'options' => [
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]
];
PHP;
    }

    protected function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        if (str_starts_with($path, '\\\\')) {
            return true;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, 'file:') || str_starts_with($path, 'phar://')) {
            return true;
        }

        return false;
    }

    protected function escapePhpString(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
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
            'utf8mb4' => $type === 'mysql' ? 'utf8mb4_0900_ai_ci' : 'utf8mb4_unicode_520_ci',
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
