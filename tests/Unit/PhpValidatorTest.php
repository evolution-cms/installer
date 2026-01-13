<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Validators\PhpValidator;
use PHPUnit\Framework\TestCase;

class PhpValidatorTest extends TestCase
{
    protected PhpValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PhpValidator();
    }

    public function testGetRequiredVersion(): void
    {
        $this->assertEquals('8.3.0', $this->validator->getRequiredVersion());
    }

    public function testValidateWithSupportedVersion(): void
    {
        $result = $this->validator->validate('8.3.5');
        $this->assertTrue($result);
    }

    public function testValidateWithUnsupportedVersion(): void
    {
        ob_start();
        $result = $this->validator->validate('8.2.0');
        ob_end_clean();
        $this->assertFalse($result);
    }

    public function testValidateWithExactMinimumVersion(): void
    {
        $result = $this->validator->validate('8.3.0');
        $this->assertTrue($result);
    }

    public function testValidateWithHigherVersion(): void
    {
        $result = $this->validator->validate('8.4.0');
        $this->assertTrue($result);
    }

    public function testValidateWithNullUsesCurrentVersion(): void
    {
        $result = $this->validator->validate(null);
        // Should pass because we're running PHP 8.3+
        $this->assertTrue($result);
    }

    public function testIsSupported(): void
    {
        $result = $this->validator->isSupported();
        // Should pass because we're running PHP 8.3+
        $this->assertTrue($result);
    }
}
