<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Application;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    public function testApplicationCanBeInstantiated(): void
    {
        $app = new Application();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testApplicationHasCorrectName(): void
    {
        $app = new Application();
        $this->assertEquals('Evolution CMS Installer', $app->getName());
    }

    public function testApplicationHasVersion(): void
    {
        $app = new Application();
        $version = $app->getVersion();
        $this->assertNotEmpty($version);
    }

    public function testGetLongVersion(): void
    {
        $app = new Application();
        $longVersion = $app->getLongVersion();
        $this->assertStringContainsString('Evolution CMS Installer', $longVersion);
        $this->assertStringContainsString('Evolution CMS', $longVersion);
    }
}

