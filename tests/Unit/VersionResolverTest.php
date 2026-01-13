<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Utilities\VersionResolver;
use PHPUnit\Framework\TestCase;

class VersionResolverTest extends TestCase
{
    protected VersionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new class () extends VersionResolver {
            protected function fetchReleases(): array
            {
                return [
                    ['tag_name' => 'v3.5.0-beta', 'prerelease' => true],
                    ['tag_name' => 'v3.5.0', 'prerelease' => false],
                    ['tag_name' => 'v3.4.9', 'prerelease' => false],
                ];
            }
        };
    }

    public function testGetLatestVersion(): void
    {
        $version = $this->resolver->getLatestVersion();
        
        $this->assertSame('v3.5.0', $version);
    }

    public function testGetDownloadUrl(): void
    {
        $url = $this->resolver->getDownloadUrl('v3.3.0');
        $this->assertEquals(
            'https://github.com/evolution-cms/evolution/archive/refs/tags/v3.3.0.zip',
            $url
        );
    }

    public function testSatisfiesVersionWithCaret(): void
    {
        // Test ^8.3 constraint
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('satisfiesVersion');
        $method->setAccessible(true);

        // ^8.3 should match 8.3.0, 8.3.5, 8.4.0 but not 8.2.0 or 9.0.0
        $this->assertTrue($method->invoke($this->resolver, '8.3.0', '^8.3'));
        $this->assertTrue($method->invoke($this->resolver, '8.3.5', '^8.3'));
        $this->assertTrue($method->invoke($this->resolver, '8.4.0', '^8.3'));
        $this->assertFalse($method->invoke($this->resolver, '8.2.0', '^8.3'));
        $this->assertFalse($method->invoke($this->resolver, '9.0.0', '^8.3'));
    }

    public function testSatisfiesVersionWithGreaterOrEqual(): void
    {
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('satisfiesVersion');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->resolver, '8.3.0', '>=8.3.0'));
        $this->assertTrue($method->invoke($this->resolver, '8.4.0', '>=8.3.0'));
        $this->assertFalse($method->invoke($this->resolver, '8.2.0', '>=8.3.0'));
    }

    public function testSatisfiesVersionWithTilde(): void
    {
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('satisfiesVersion');
        $method->setAccessible(true);

        // ~8.3.0 should match >=8.3.0 <8.4.0
        $this->assertTrue($method->invoke($this->resolver, '8.3.0', '~8.3.0'));
        $this->assertTrue($method->invoke($this->resolver, '8.3.5', '~8.3.0'));
        $this->assertFalse($method->invoke($this->resolver, '8.4.0', '~8.3.0'));
        $this->assertFalse($method->invoke($this->resolver, '8.2.0', '~8.3.0'));
    }

    public function testIsPreRelease(): void
    {
        $reflection = new \ReflectionClass($this->resolver);
        $method = $reflection->getMethod('isPreRelease');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->resolver, 'v3.3.0-alpha'));
        $this->assertTrue($method->invoke($this->resolver, 'v3.3.0-beta'));
        $this->assertTrue($method->invoke($this->resolver, 'v3.3.0-rc1'));
        $this->assertTrue($method->invoke($this->resolver, 'v3.3.0-dev'));
        $this->assertFalse($method->invoke($this->resolver, 'v3.3.0'));
        $this->assertFalse($method->invoke($this->resolver, '3.3.0'));
    }
}
