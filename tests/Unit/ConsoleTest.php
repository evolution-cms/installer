<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Utilities\Console;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        // Capture output
        ob_start();
    }

    protected function tearDown(): void
    {
        // Clean up output
        ob_end_clean();
    }

    public function testLine(): void
    {
        ob_clean();
        Console::line('test message');
        $output = ob_get_contents();
        $this->assertStringContainsString('test message', $output);
    }

    public function testLineEmpty(): void
    {
        ob_clean();
        Console::line();
        $output = ob_get_contents();
        $this->assertEquals(PHP_EOL, $output);
    }

    // Note: writeColored is protected, so we test it indirectly through public methods

    public function testInfo(): void
    {
        ob_clean();
        Console::info('test info');
        $output = ob_get_contents();
        $this->assertStringContainsString('test info', $output);
    }

    public function testSuccess(): void
    {
        ob_clean();
        Console::success('test success');
        $output = ob_get_contents();
        $this->assertStringContainsString('test success', $output);
    }

    public function testError(): void
    {
        ob_clean();
        Console::error('test error');
        $output = ob_get_contents();
        $this->assertStringContainsString('test error', $output);
    }

    public function testWarning(): void
    {
        ob_clean();
        Console::warning('test warning');
        $output = ob_get_contents();
        $this->assertStringContainsString('test warning', $output);
    }

    public function testComment(): void
    {
        ob_clean();
        Console::comment('test comment');
        $output = ob_get_contents();
        $this->assertStringContainsString('test comment', $output);
    }
}

