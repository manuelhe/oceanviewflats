<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Support;

use OceanViewFlats\Domain\Support\EnvLoader;
use PHPUnit\Framework\TestCase;

final class EnvLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<string> */
    private array $modifiedKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->modifiedKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->modifiedKeys = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    private function trackEnvKey(string $key): void
    {
        if (!in_array($key, $this->modifiedKeys, true)) {
            $this->modifiedKeys[] = $key;
        }
    }

    private function createTempDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/ovf_env_test_' . bin2hex(random_bytes(8));
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

    public function testParseHtaccessExtractsSetEnvVariables(): void
    {
        $content = <<<'HTACCESS'
# Apache Environment Configuration
SetEnv DB_HOST "127.0.0.1"
SetEnv DB_NAME 'oceanview_prod'
SetEnv DB_USER cpanel_user
SetEnv DB_PASS "Complex#Pass!123"

# Other directives
RewriteEngine On
RewriteRule ^index\.php$ - [L]
HTACCESS;

        $parsed = EnvLoader::parseHtaccess($content);

        $this->assertSame([
            'DB_HOST' => '127.0.0.1',
            'DB_NAME' => 'oceanview_prod',
            'DB_USER' => 'cpanel_user',
            'DB_PASS' => 'Complex#Pass!123',
        ], $parsed);
    }

    public function testParseEnvExtractsKeyValues(): void
    {
        $content = <<<'ENV'
# Database settings
DB_HOST=localhost
DB_NAME="my_db"
DB_PASS='secret_value'
ENV;

        $parsed = EnvLoader::parseEnv($content);

        $this->assertSame([
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'my_db',
            'DB_PASS' => 'secret_value',
        ], $parsed);
    }

    public function testLoadPopulatesEnvFromAdminPublicHtaccess(): void
    {
        $root = $this->createTempDirectory();
        $htaccessDir = $root . '/admin/public';
        mkdir($htaccessDir, 0755, true);

        $testKey = 'TEST_ENV_KEY_' . bin2hex(random_bytes(4));
        $this->trackEnvKey($testKey);

        file_put_contents($htaccessDir . '/.htaccess', "SetEnv {$testKey} \"resolved_from_admin\"\n");

        $loaded = EnvLoader::load($root);

        $this->assertArrayHasKey($testKey, $loaded);
        $this->assertSame('resolved_from_admin', $loaded[$testKey]);
        $this->assertSame('resolved_from_admin', getenv($testKey));
        $this->assertSame('resolved_from_admin', $_ENV[$testKey]);
        $this->assertSame('resolved_from_admin', $_SERVER[$testKey]);
    }

    public function testLoadDiscoversPublicHtmlHtaccessWhenAdminPublicMissing(): void
    {
        $root = $this->createTempDirectory();
        $publicHtmlDir = $root . '/public_html';
        mkdir($publicHtmlDir, 0755, true);

        $testKey = 'TEST_PUBLIC_HTML_KEY_' . bin2hex(random_bytes(4));
        $this->trackEnvKey($testKey);

        file_put_contents($publicHtmlDir . '/.htaccess', "SetEnv {$testKey} \"resolved_from_public_html\"\n");

        $loaded = EnvLoader::load($root);

        $this->assertArrayHasKey($testKey, $loaded);
        $this->assertSame('resolved_from_public_html', $loaded[$testKey]);
        $this->assertSame('resolved_from_public_html', getenv($testKey));
    }

    public function testLoadDoesNotOverwriteExistingEnvironmentVariables(): void
    {
        $root = $this->createTempDirectory();
        $adminDir = $root . '/admin/public';
        mkdir($adminDir, 0755, true);

        $testKey = 'TEST_EXISTING_KEY_' . bin2hex(random_bytes(4));
        $this->trackEnvKey($testKey);

        putenv("{$testKey}=original_env_val");
        $_ENV[$testKey] = 'original_env_val';

        file_put_contents($adminDir . '/.htaccess', "SetEnv {$testKey} \"overwritten_val\"\n");

        $loaded = EnvLoader::load($root);

        $this->assertArrayNotHasKey($testKey, $loaded);
        $this->assertSame('original_env_val', getenv($testKey));
        $this->assertSame('original_env_val', $_ENV[$testKey]);
    }

    public function testDefaultBaseDirRunsWithoutError(): void
    {
        $loaded = EnvLoader::load();
        $this->assertArrayNotHasKey('NON_EXISTENT_' . bin2hex(random_bytes(4)), $loaded);
    }
}
