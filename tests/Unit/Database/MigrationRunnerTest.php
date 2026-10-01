<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Database;

use OceanViewFlats\Domain\Database\MigrationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testRunsMigrationsAgainstSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $logs = MigrationRunner::run($pdo, 'memory_db');

        $this->assertNotEmpty($logs);
        $this->assertContains('Table `reservations` verified/created.', $logs);
        $this->assertContains('Table `admin_users` verified/created.', $logs);
        $this->assertContains('Table `calendar_blocks` verified/created.', $logs);

        // Verify tables exist in SQLite master
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        $this->assertContains('reservations', $tables);
        $this->assertContains('payment_idempotency', $tables);
        $this->assertContains('guest_registries', $tables);
        $this->assertContains('admin_users', $tables);
        $this->assertContains('admin_audit_logs', $tables);
        $this->assertContains('calendar_blocks', $tables);
        $this->assertContains('property_rates', $tables);
        $this->assertContains('reservation_refunds', $tables);
    }

    public function testMigrationsAreIdempotent(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $firstRunLogs = MigrationRunner::run($pdo, 'memory_db');
        $this->assertNotEmpty($firstRunLogs);

        // Second run must succeed with zero errors or schema conflicts
        $secondRunLogs = MigrationRunner::run($pdo, 'memory_db');
        $this->assertSame($firstRunLogs, $secondRunLogs);
    }

    public function testConnectThrowsExpectedPdoExceptionForInvalidHost(): void
    {
        $this->expectException(PDOException::class);

        MigrationRunner::connect([
            'host' => '127.0.0.1',
            'port' => 65432, // Non-existent MySQL port
            'dbname' => 'nonexistent_db',
            'user' => 'bad_user',
            'pass' => 'bad_pass',
        ]);
    }
}
