<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Commands\InstallCommand;
use EvolutionCMS\Installer\Utilities\TuiRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommandAdminDirectoryTest extends TestCase
{
    private function makeCommand(): TestableInstallCommand
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $tui = new TuiRenderer($output);

        $cmd = new TestableInstallCommand();
        $cmd->setTui($tui);
        return $cmd;
    }

    public function testSanitizeAdminDirectoryDefaultsToManager(): void
    {
        $cmd = $this->makeCommand();
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic(null));
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic(''));
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic('   '));
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic('менеджер'));
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic('///'));
    }

    public function testSanitizeAdminDirectoryAllowsBasicChars(): void
    {
        $cmd = $this->makeCommand();
        $this->assertSame('manager', $cmd->sanitizeAdminDirectoryPublic('manager'));
        $this->assertSame('my-admin_dir', $cmd->sanitizeAdminDirectoryPublic('my-admin_dir'));
        $this->assertSame('myadmindir', $cmd->sanitizeAdminDirectoryPublic('my admin dir'));
        $this->assertSame('admin', $cmd->sanitizeAdminDirectoryPublic('../admin'));
    }

    public function testWriteCoreCustomEnvWritesMgrDirWhenCustom(): void
    {
        $cmd = $this->makeCommand();

        $projectPath = $this->makeTempProjectDir();
        @mkdir($projectPath . '/core/custom', 0755, true);

        $cmd->writeCoreCustomEnvPublic($projectPath, [
            'database' => [
                'type' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'evo_db',
                'user' => 'root',
                'password' => 'secret',
                'prefix' => 'evo_',
            ],
            'admin' => [
                'directory' => 'admin',
            ],
        ]);

        $env = (string) file_get_contents($projectPath . '/core/custom/.env');
        $this->assertStringContainsString("DB_TYPE=\"mysql\"\n", $env);
        $this->assertStringContainsString("DB_HOST=\"localhost\"\n", $env);
        $this->assertStringContainsString("DB_PORT=\"3306\"\n", $env);
        $this->assertStringContainsString("DB_DATABASE=\"evo_db\"\n", $env);
        $this->assertStringContainsString("MGR_DIR=\"admin\"\n", $env);

        $this->removeTempDir($projectPath);
    }

    public function testWriteCoreCustomEnvDoesNotWriteMgrDirForManager(): void
    {
        $cmd = $this->makeCommand();

        $projectPath = $this->makeTempProjectDir();
        @mkdir($projectPath . '/core/custom', 0755, true);

        $cmd->writeCoreCustomEnvPublic($projectPath, [
            'database' => [
                'type' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'evo_db',
                'user' => 'root',
                'password' => 'secret',
                'prefix' => 'evo_',
            ],
            'admin' => [
                'directory' => 'manager',
            ],
        ]);

        $env = (string) file_get_contents($projectPath . '/core/custom/.env');
        $this->assertStringNotContainsString("MGR_DIR=", $env);

        $this->removeTempDir($projectPath);
    }

    public function testApplyManagerDirectoryRenamesManagerFolder(): void
    {
        $cmd = $this->makeCommand();

        $projectPath = $this->makeTempProjectDir();
        @mkdir($projectPath . '/manager', 0755, true);

        $cmd->applyManagerDirectoryPublic($projectPath, [
            'admin' => [
                'directory' => 'admin',
            ],
        ]);

        $this->assertDirectoryDoesNotExist($projectPath . '/manager');
        $this->assertDirectoryExists($projectPath . '/admin');

        $this->removeTempDir($projectPath);
    }

    private function makeTempProjectDir(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $dir = $base . DIRECTORY_SEPARATOR . 'evo-installer-test-' . bin2hex(random_bytes(8));
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

final class TestableInstallCommand extends InstallCommand
{
    public function setTui(TuiRenderer $tui): void
    {
        $this->tui = $tui;
    }

    public function sanitizeAdminDirectoryPublic(?string $value): string
    {
        return $this->sanitizeAdminDirectory($value);
    }

    public function writeCoreCustomEnvPublic(string $projectPath, array $options): void
    {
        $this->writeCoreCustomEnv($projectPath, $options);
    }

    public function applyManagerDirectoryPublic(string $projectPath, array $options): void
    {
        $this->applyManagerDirectory($projectPath, $options);
    }
}

