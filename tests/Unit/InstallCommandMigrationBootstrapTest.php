<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Commands\InstallCommand;
use PHPUnit\Framework\TestCase;

final class InstallCommandMigrationBootstrapTest extends TestCase
{
    public function testBootstrapInstallMigrationHistoryRegistersOlderStubMigrations(): void
    {
        $cmd = new TestableMigrationBootstrapInstallCommand();
        $projectPath = $this->makeTempProjectDir();
        $databasePath = $projectPath . '/database.sqlite';

        @mkdir($projectPath . '/install/stubs/migrations', 0755, true);
        file_put_contents($projectPath . '/install/stubs/migrations/2018_06_29_182342_create_role_permissions_table.php', "<?php\n");
        file_put_contents($projectPath . '/install/stubs/migrations/2020_10_08_112342_remove_column_from_role_table.php', "<?php\n");
        file_put_contents($projectPath . '/install/stubs/migrations/2025_12_25_000000_initial_schema.php', "<?php\n");
        file_put_contents($projectPath . '/install/stubs/migrations/2026_03_29_000000_create_file_groups_table.php', "<?php\n");

        $dbh = new \PDO('sqlite:' . $databasePath);
        $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dbh->exec('CREATE TABLE "evo_migrations_install" (migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL)');
        $dbh->exec('CREATE TABLE "evo_active_user_locks" (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $dbh->exec('INSERT INTO "evo_migrations_install" (migration, batch) VALUES ("2025_12_25_000000_initial_schema", 1)');

        $inserted = $cmd->bootstrapInstallMigrationHistoryPublic($projectPath, [
            'type' => 'sqlite',
            'name' => $databasePath,
            'prefix' => 'evo_',
        ]);

        $this->assertSame(2, $inserted);

        $history = $dbh->query('SELECT migration, batch FROM "evo_migrations_install" ORDER BY migration')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([
            ['migration' => '2018_06_29_182342_create_role_permissions_table', 'batch' => 1],
            ['migration' => '2020_10_08_112342_remove_column_from_role_table', 'batch' => 1],
            ['migration' => '2025_12_25_000000_initial_schema', 'batch' => 1],
        ], $history);

        $this->removeTempDir($projectPath);
    }

    public function testBootstrapInstallMigrationHistorySkipsWhenBaselineIsMissing(): void
    {
        $cmd = new TestableMigrationBootstrapInstallCommand();
        $projectPath = $this->makeTempProjectDir();
        $databasePath = $projectPath . '/database.sqlite';

        @mkdir($projectPath . '/install/stubs/migrations', 0755, true);
        file_put_contents($projectPath . '/install/stubs/migrations/2018_06_29_182342_create_role_permissions_table.php', "<?php\n");
        file_put_contents($projectPath . '/install/stubs/migrations/2025_12_25_000000_initial_schema.php', "<?php\n");

        $dbh = new \PDO('sqlite:' . $databasePath);
        $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dbh->exec('CREATE TABLE "evo_migrations_install" (migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL)');
        $dbh->exec('CREATE TABLE "evo_active_user_locks" (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        $inserted = $cmd->bootstrapInstallMigrationHistoryPublic($projectPath, [
            'type' => 'sqlite',
            'name' => $databasePath,
            'prefix' => 'evo_',
        ]);

        $this->assertSame(0, $inserted);
        $count = (int) $dbh->query('SELECT COUNT(*) FROM "evo_migrations_install"')->fetchColumn();
        $this->assertSame(0, $count);

        $this->removeTempDir($projectPath);
    }

    private function makeTempProjectDir(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $dir = $base . DIRECTORY_SEPARATOR . 'evo-installer-bootstrap-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail("Unable to create temp dir: {$dir}");
        }
        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

final class TestableMigrationBootstrapInstallCommand extends InstallCommand
{
    public function bootstrapInstallMigrationHistoryPublic(string $projectPath, array $dbConfig): int
    {
        return $this->bootstrapInstallMigrationHistory($projectPath, $dbConfig);
    }
}
