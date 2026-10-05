<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Repository;

use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use OceanViewFlats\Domain\Reservation\ChannelBlock;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AdminReservationRepositoryParameterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // SQLite in-memory database decorated to enforce MySQL native prepared statement restrictions
        // (disallowing duplicate named parameter placeholders per MySQL native protocol)
        $rawPdo = AdminDatabaseTestHelper::createDatabaseWithDefaultAdmin();
        $this->pdo = new class($rawPdo) extends PDO {
            public function __construct(private readonly PDO $inner)
            {
                // Bypass parent PDO constructor
            }

            /**
             * @param array<mixed> $options
             */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                preg_match_all('/:([a-zA-Z0-9_]+)/', $query, $matches);
                if (!empty($matches[1])) {
                    $counts = array_count_values($matches[1]);
                    foreach ($counts as $param => $count) {
                        if ($count > 1) {
                            throw new PDOException(
                                "SQLSTATE[HY093]: Invalid parameter number: :{$param} duplicated {$count} times",
                                93
                            );
                        }
                    }
                }
                return $this->inner->prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
            {
                return $this->inner->query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function exec(string $statement): int|false
            {
                return $this->inner->exec($statement);
            }

            public function getAttribute(int $attribute): mixed
            {
                return $this->inner->getAttribute($attribute);
            }

            public function lastInsertId(?string $name = null): string|false
            {
                return $this->inner->lastInsertId($name);
            }

            public function beginTransaction(): bool
            {
                return $this->inner->beginTransaction();
            }

            public function commit(): bool
            {
                return $this->inner->commit();
            }

            public function rollBack(): bool
            {
                return $this->inner->rollBack();
            }
        };
    }

    public function testGetOperationalScheduleDoesNotDuplicateNamedParameters(): void
    {
        $repo = new AdminReservationRepository($this->pdo);
        $events = $repo->getOperationalSchedule('all', '2026-10-01', '2026-10-07');
        $this->assertCount(0, $events);
    }

    public function testGetIncompleteRegistryAlertsDoesNotDuplicateNamedParameters(): void
    {
        $repo = new AdminReservationRepository($this->pdo);
        $alerts = $repo->getIncompleteRegistryAlerts('all', 3);
        $this->assertCount(0, $alerts);
    }

    public function testGetActiveStaysCountDoesNotDuplicateNamedParameters(): void
    {
        $repo = new AdminReservationRepository($this->pdo);
        $count = $repo->getActiveStaysCount('all');
        $this->assertSame(0, $count);
    }

    public function testGetUnonboardedChannelBlockAlertsDoesNotDuplicateNamedParameters(): void
    {
        $repo = new AdminReservationRepository($this->pdo);
        $block = new ChannelBlock('1606', '2026-10-05', '2026-10-08', 'airbnb', 'Airbnb hold');
        $alerts = $repo->getUnonboardedChannelBlockAlerts([$block]);
        $this->assertCount(1, $alerts);
    }
}
