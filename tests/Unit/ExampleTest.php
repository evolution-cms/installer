<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function testBasicExample(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Test that PHP version meets requirements.
     */
    public function testPhpVersion(): void
    {
        $this->assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'PHP version must be 8.3.0 or higher'
        );
    }
}

