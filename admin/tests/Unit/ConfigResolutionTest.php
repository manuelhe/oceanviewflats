<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Unit;

use OceanViewFlats\Admin\Config\ConfigPathResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigResolutionTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    private function createTempDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/ovf_config_test_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail("Failed to create temporary directory: {$dir}");
        }
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function testResolvesLocalDevConfigPathWhenPresent(): void
    {
        $root = $this->createTempDirectory();
        $apiDir = $root . '/public/api';
        mkdir($apiDir, 0755, true);
        $configFile = $apiDir . '/config.php';
        file_put_contents($configFile, "<?php return ['db' => ['host' => '127.0.0.1']];");

        $resolved = ConfigPathResolver::resolveConfigPath($root);
        $this->assertSame($configFile, $resolved);
        $this->assertTrue(file_exists($resolved));
    }

    public function testResolvesCpanelProductionConfigPathWhenLocalIsMissing(): void
    {
        $root = $this->createTempDirectory();
        $apiDir = $root . '/public_html/api';
        mkdir($apiDir, 0755, true);
        $configFile = $apiDir . '/config.php';
        file_put_contents($configFile, "<?php return ['db' => ['host' => 'cpanel.mysql.internal']];");

        $resolved = ConfigPathResolver::resolveConfigPath($root);
        $this->assertSame($configFile, $resolved);
        $this->assertTrue(file_exists($resolved));
    }

    public function testPrioritizesLocalDevPathWhenBothExist(): void
    {
        $root = $this->createTempDirectory();

        $localDir = $root . '/public/api';
        mkdir($localDir, 0755, true);
        $localConfig = $localDir . '/config.php';
        file_put_contents($localConfig, "<?php return ['env' => 'local'];");

        $cpanelDir = $root . '/public_html/api';
        mkdir($cpanelDir, 0755, true);
        $cpanelConfig = $cpanelDir . '/config.php';
        file_put_contents($cpanelConfig, "<?php return ['env' => 'cpanel'];");

        $resolved = ConfigPathResolver::resolveConfigPath($root);
        $this->assertSame($localConfig, $resolved);
    }

    public function testThrowsRuntimeExceptionWhenNeitherConfigPathExists(): void
    {
        $root = $this->createTempDirectory();

        $expectedLocal = $root . '/public/api/config.php';
        $expectedCpanel = $root . '/public_html/api/config.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Configuration file not found. Checked: '{$expectedLocal}' and '{$expectedCpanel}'.");

        ConfigPathResolver::resolveConfigPath($root);
    }

    public function testHandlesTrailingSlashesInRootDirectoryGracefully(): void
    {
        $root = $this->createTempDirectory();
        $apiDir = $root . '/public_html/api';
        mkdir($apiDir, 0755, true);
        $configFile = $apiDir . '/config.php';
        file_put_contents($configFile, "<?php return ['db' => []];");

        $resolvedWithSlash = ConfigPathResolver::resolveConfigPath($root . '/');
        $resolvedWithMultipleSlashes = ConfigPathResolver::resolveConfigPath($root . '///');

        $this->assertSame($configFile, $resolvedWithSlash);
        $this->assertSame($configFile, $resolvedWithMultipleSlashes);
    }

    public function testLoadConfigLoadsAndReturnsArray(): void
    {
        $root = $this->createTempDirectory();
        $apiDir = $root . '/public_html/api';
        mkdir($apiDir, 0755, true);
        $configFile = $apiDir . '/config.php';
        file_put_contents($configFile, "<?php return ['db' => ['host' => '10.0.0.1', 'dbname' => 'cpanel_prod']];");

        $config = ConfigPathResolver::loadConfig($root);
        $this->assertArrayHasKey('db', $config);
        $this->assertSame('10.0.0.1', $config['db']['host'] ?? null);
        $this->assertSame('cpanel_prod', $config['db']['dbname'] ?? null);
    }

    public function testResolvesRealProjectLocalConfiguration(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $resolved = ConfigPathResolver::resolveConfigPath($repoRoot);

        $expected = $repoRoot . '/public/api/config.php';
        $this->assertSame($expected, $resolved);
        $this->assertTrue(file_exists($resolved));
    }
}
