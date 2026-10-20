<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Utilities\VersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Keep both automatic entry points on stable releases using offline fixtures.
     *
     * @since 1.4.0
     */
    #[DataProvider('releaseSelectionCases')]
    public function testAutomaticSelectionUsesOnlyStableReleases(array $releases, ?string $expected): void
    {
        $resolver = new class ($releases) extends VersionResolver {
            /**
             * Inject release metadata without contacting GitHub.
             * @since 1.4.0
             */
            public function __construct(private array $releases)
            {
            }

            /**
             * Return the injected GitHub release fixtures.
             * @since 1.4.0
             */
            protected function fetchReleases(): array
            {
                return $this->releases;
            }

            /**
             * Keep compatibility checks deterministic and offline.
             * @since 1.4.0
             */
            protected function getPhpRequirement(string $version): ?string
            {
                return '^8.3';
            }
        };

        $this->assertSame($expected, $resolver->getLatestVersion());
        $this->assertSame($expected, $resolver->getLatestCompatibleVersion('8.4.16', true));
    }

    /**
     * Cover metadata flags and tag formats that must never become stable constraints.
     *
     * @return array<string, array{0: list<array<string, mixed>>, 1: ?string}>
     * @since 1.4.0
     */
    public static function releaseSelectionCases(): array
    {
        $stable = ['tag_name' => 'v3.5.8', 'prerelease' => false, 'draft' => false];

        return [
            'nightly marked as prerelease' => [
                [['tag_name' => 'nightly-3.5.x', 'prerelease' => true], $stable], 'v3.5.8',
            ],
            'nightly without metadata' => [[['tag_name' => 'nightly-3.5.x'], $stable], 'v3.5.8'],
            'numeric tag marked as prerelease' => [
                [['tag_name' => 'v3.5.9', 'prerelease' => true], $stable], 'v3.5.8',
            ],
            'draft release' => [[['tag_name' => 'v3.5.9', 'draft' => true], $stable], 'v3.5.8'],
            'branch alias' => [[['tag_name' => '3.5.x-dev'], $stable], 'v3.5.8'],
            'release candidate without metadata' => [[['tag_name' => 'v3.5.9-RC1'], $stable], 'v3.5.8'],
            'arbitrary non-version tag' => [[['tag_name' => 'latest'], $stable], 'v3.5.8'],
            'stable tag without v prefix or metadata' => [[['tag_name' => '3.5.8']], '3.5.8'],
            'no stable release' => [[['tag_name' => 'nightly-3.5.x', 'prerelease' => true]], null],
            'empty release list' => [[], null],
        ];
    }

    /**
     * Preserve PHP compatibility filtering after stable-release eligibility checks.
     *
     * @since 1.4.0
     */
    public function testCompatibleSelectionSkipsStableReleasesRequiringNewerPhp(): void
    {
        $resolver = new class extends VersionResolver {
            /**
             * Supply stable releases on either side of the PHP requirement.
             * @since 1.4.0
             */
            protected function fetchReleases(): array
            {
                return [['tag_name' => 'v4.0.0'], ['tag_name' => 'v3.5.8']];
            }

            /**
             * Emulate a future release requiring PHP 9 without network access.
             * @since 1.4.0
             */
            protected function getPhpRequirement(string $version): ?string
            {
                return $version === 'v4.0.0' ? '^9.0' : '^8.3';
            }
        };

        $this->assertSame('v4.0.0', $resolver->getLatestVersion());
        $this->assertSame('v3.5.8', $resolver->getLatestCompatibleVersion('8.4.16', true));
        $this->assertNull($resolver->getLatestCompatibleVersion('8.2.0', true));
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
