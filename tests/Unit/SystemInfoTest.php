<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Utilities\SystemInfo;
use PHPUnit\Framework\TestCase;

class SystemInfoTest extends TestCase
{
    public function testGetPhpVersion(): void
    {
        $version = SystemInfo::getPhpVersion();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
    }

    public function testGetOS(): void
    {
        $os = SystemInfo::getOS();
        $this->assertNotEmpty($os);
        $this->assertStringContainsString('-', $os);
    }

    public function testGetMemoryLimit(): void
    {
        $limit = SystemInfo::getMemoryLimit();
        $this->assertNotEmpty($limit);
        $this->assertNotEquals('Unknown', $limit);
    }

    public function testHasExtensionWithExistingExtension(): void
    {
        // json extension should always be available in PHP 8.3+
        $result = SystemInfo::hasExtension('json');
        $this->assertTrue($result);
    }

    public function testHasExtensionWithNonExistingExtension(): void
    {
        $result = SystemInfo::hasExtension('nonexistent_extension_12345');
        $this->assertFalse($result);
    }

    public function testGetDiskFreeSpace(): void
    {
        $free = SystemInfo::getDiskFreeSpace();
        $this->assertNotNull($free);
        $this->assertStringContainsString('free', $free);
        $this->assertMatchesRegularExpression('/\d+\.\d+ (B|KB|MB|GB|TB) free/', $free);
    }

    public function testGetComposerVersion(): void
    {
        $version = SystemInfo::getComposerVersion();
        // May be null if composer is not in PATH, but if it exists, should match version pattern
        if ($version !== null) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
        } else {
            // If composer is not in PATH, that's acceptable - just mark as skipped assertion
            $this->assertNull($version, 'Composer version may be null if composer is not in PATH');
        }
    }
}

