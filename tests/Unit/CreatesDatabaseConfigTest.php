<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Process\CreatesDatabaseConfig;
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

class CreatesDatabaseConfigTest extends TestCase
{
    protected CreatesDatabaseConfig $processor;
    protected vfsStreamDirectory $root;

    protected function setUp(): void
    {
        $this->processor = new CreatesDatabaseConfig();
        $this->root = vfsStream::setup('project');
    }

    public function testGenerateConfigContentForMySQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateConfigContent');
        $method->setAccessible(true);

        $params = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'evo_',
            'method' => 'SET CHARACTER SET',
            'engine' => 'innodb',
        ];

        $content = $method->invoke($this->processor, $params);
        
        $this->assertStringContainsString("'driver' => env('DB_TYPE', 'mysql')", $content);
        $this->assertStringContainsString("'host' => env('DB_HOST', 'localhost')", $content);
        $this->assertStringContainsString("'database' => env('DB_DATABASE', 'test_db')", $content);
        $this->assertStringContainsString("'username' => env('DB_USERNAME', 'test_user')", $content);
        $this->assertStringContainsString("'engine' => env('DB_ENGINE', 'innodb')", $content);
    }

    public function testGenerateConfigContentForPostgreSQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('generateConfigContent');
        $method->setAccessible(true);

        $params = [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass',
            'charset' => 'utf8',
            'collation' => 'utf8',
            'prefix' => 'evo_',
            'method' => 'SET NAMES',
            'engine' => '',
        ];

        $content = $method->invoke($this->processor, $params);
        
        $this->assertStringContainsString("'driver' => env('DB_TYPE', 'pgsql')", $content);
        $this->assertStringContainsString("'engine' => env('DB_ENGINE', '')", $content);
    }

    public function testGetDefaultPortForMySQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDefaultPort');
        $method->setAccessible(true);

        $this->assertEquals(3306, $method->invoke($this->processor, 'mysql'));
    }

    public function testGetDefaultPortForPostgreSQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDefaultPort');
        $method->setAccessible(true);

        $this->assertEquals(5432, $method->invoke($this->processor, 'pgsql'));
    }

    public function testGetDefaultCharsetForMySQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDefaultCharset');
        $method->setAccessible(true);

        $this->assertEquals('utf8mb4', $method->invoke($this->processor, 'mysql'));
    }

    public function testGetDefaultCharsetForPostgreSQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDefaultCharset');
        $method->setAccessible(true);

        $this->assertEquals('utf8', $method->invoke($this->processor, 'pgsql'));
    }

    public function testGetDefaultCollationForMySQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDefaultCollation');
        $method->setAccessible(true);

        $collation = $method->invoke($this->processor, 'mysql', 'utf8mb4');
        $this->assertEquals('utf8mb4_0900_ai_ci', $collation);
    }

    public function testGetDatabaseEngineForMySQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDatabaseEngine');
        $method->setAccessible(true);

        $this->assertEquals('innodb', $method->invoke($this->processor, 'mysql'));
    }

    public function testGetDatabaseEngineForPostgreSQL(): void
    {
        $reflection = new \ReflectionClass($this->processor);
        $method = $reflection->getMethod('getDatabaseEngine');
        $method->setAccessible(true);

        $this->assertEquals('', $method->invoke($this->processor, 'pgsql'));
    }
}
