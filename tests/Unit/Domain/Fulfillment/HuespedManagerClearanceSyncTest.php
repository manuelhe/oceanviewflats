<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use OceanViewFlats\Domain\Database\MigrationRunner;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearance;
use OceanViewFlats\Domain\Fulfillment\HuespedManagerClearanceSync;
use OceanViewFlats\Domain\Fulfillment\HttpTransportInterface;
use OceanViewFlats\Domain\Fulfillment\OccupantDetails;
use OceanViewFlats\Domain\Fulfillment\PdoCondominiumClearanceRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HuespedManagerClearanceSyncTest extends TestCase
{
    private PDO $pdo;
    private PdoCondominiumClearanceRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        MigrationRunner::run($this->pdo, 'test_db');
        $this->repository = new PdoCondominiumClearanceRepository($this->pdo);
    }

    public function testSuccessfulTwoStepSyncWithCookiePreservation(): void
    {
        $requests = [];
        $mockTransport = new class($requests) implements HttpTransportInterface {
            /** @param array<int, array<string, mixed>> $requests */
            public function __construct(public array &$requests) {}

            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->requests[] = [
                    'url' => $url,
                    'data' => $data,
                    'headers' => $headers,
                    'options' => $options,
                ];

                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return [
                        'statusCode' => 200,
                        'body' => json_encode(['last_id' => 495, 'unit_apt' => '1707']),
                        'headers' => ['content-type' => 'application/json'],
                        'cookies' => ['PHPSESSID' => 'test-session-token-xyz'],
                        'error' => null,
                    ];
                }

                if (str_contains($url, 'reg_hpds_pre.php')) {
                    return [
                        'statusCode' => 302,
                        'body' => '',
                        'headers' => ['location' => 'success.php?hpsucc=succ&book=495'],
                        'cookies' => [],
                        'error' => null,
                    ];
                }

                return ['statusCode' => 404, 'body' => '', 'headers' => [], 'cookies' => [], 'error' => 'Not found'];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            new OccupantDetails(
                index: 1,
                name: 'Carlos Andres Mendoza Perez',
                age: 35,
                docType: 'Passport',
                docNum: 'PA987654',
                email: 'carlos@example.com',
                firstName: 'Carlos',
                lastName: 'Mendoza',
                phone: '+34 600 123 456',
                country: 'España',
                middleName: 'Andres',
                secondLastName: 'Perez'
            ),
            new OccupantDetails(
                index: 2,
                name: 'Maria Gomez',
                age: 30,
                docType: 'Cédula de Ciudadanía',
                docNum: '1098765432',
                email: null,
                firstName: 'Maria',
                lastName: 'Gomez',
                phone: '', // Companion without phone
                country: 'Colombia',
                middleName: null,
                secondLastName: null
            ),
        ];

        $clearance = $adapter->sync(
            reservationUid: 'ovf_res_sync_test',
            propertyId: '1707',
            checkIn: '2026-11-01',
            checkOut: '2026-11-05',
            guests: $guests,
            carPlates: 'XYZ-123',
            notes: 'Late arrival at 6 PM'
        );

        $this->assertTrue($clearance->isSynced());
        $this->assertSame('495', $clearance->clearanceNumber);
        $this->assertNull($clearance->errorMessage);
        $this->assertNotNull($clearance->syncedAt);
        $this->assertSame(1, $clearance->attempts);

        // Verify repository persistence
        $persisted = $this->repository->findByReservationUid('ovf_res_sync_test');
        $this->assertNotNull($persisted);
        $this->assertTrue($persisted->isSynced());
        $this->assertSame('495', $persisted->clearanceNumber);

        // Verify Step 1 call
        $this->assertCount(2, $requests);
        $step1 = $requests[0];
        $this->assertStringContainsString('reg_guest_owner_pre.php?unit=1707&check=ep92449222', $step1['url']);
        $this->assertSame('1707', $step1['data']['send_apt']);
        $this->assertSame('2026-11-01', $step1['data']['send_indate']);
        $this->assertSame('2026-11-05', $step1['data']['send_outdate']);
        $this->assertSame('15:00', $step1['data']['send_horain']);
        $this->assertSame('11:00', $step1['data']['send_horaout']);
        $this->assertSame('TRANSFERENCIA', $step1['data']['send_fpay']);
        $this->assertSame('2', $step1['data']['send_cant_hp']);
        $this->assertSame('XYZ-123', $step1['data']['send_car']);
        $this->assertSame('Late arrival at 6 PM', $step1['data']['send_observacion']);

        // Verify Step 2 call
        $step2 = $requests[1];
        $this->assertStringContainsString('reg_hpds_pre.php', $step2['url']);
        $this->assertSame(['PHPSESSID' => 'test-session-token-xyz'], $step2['options']['cookies']);
        $this->assertSame('495', $step2['data']['consecutivo']);

        // Verify form arrays
        $this->assertSame(['PA', 'CC'], $step2['data']['typeid']);
        $this->assertSame(['PA987654', '1098765432'], $step2['data']['ide']);
        $this->assertSame(['Carlos', 'Maria'], $step2['data']['name']);
        $this->assertSame(['Andres', ''], $step2['data']['sname']);
        $this->assertSame(['Mendoza', 'Gomez'], $step2['data']['lname']);
        $this->assertSame(['Perez', ''], $step2['data']['mname']);
        $this->assertSame(['ESPANA', 'COLOMBIA'], $step2['data']['country']);
        // Crucial: companion inherited primary guest phone!
        $this->assertSame(['+34 600 123 456', '+34 600 123 456'], $step2['data']['phone']);
        $this->assertSame(['TITULAR', 'ACOMPAÑANTE'], $step2['data']['nexo']);
        $this->assertSame(['0', '0'], $step2['data']['covid3']);
        $this->assertSame(['0', '0'], $step2['data']['covid10']);
    }

    public function testCompanionWithoutPhoneInheritsPrimaryGuestPhoneWhileIndependentRetainsOwn(): void
    {
        $requests = [];
        $mockTransport = new class($requests) implements HttpTransportInterface {
            public function __construct(public array &$requests) {}

            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->requests[] = ['url' => $url, 'data' => $data];
                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return [
                        'statusCode' => 200,
                        'body' => json_encode(['last_id' => 777]),
                        'headers' => [],
                        'cookies' => [],
                        'error' => null,
                    ];
                }
                return [
                    'statusCode' => 200,
                    'body' => '<html>Registro de huéspedes exitoso. Consecutivo 777</html>',
                    'headers' => [],
                    'cookies' => [],
                    'error' => null,
                ];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'doc_type' => 'Passport',
                'doc_number' => 'USA111',
                'country' => 'United States',
                'phone' => '+1 305 555 0100',
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'doc_type' => 'Passport',
                'doc_number' => 'USA222',
                'country' => 'United States',
                'phone' => '', // Empty phone -> inherits
            ],
            [
                'first_name' => 'Bob',
                'last_name' => 'Smith',
                'doc_type' => 'Driver License',
                'doc_number' => 'DL999',
                'country' => 'Canada',
                'phone' => '+1 416 555 0200', // Own phone -> retained
            ],
        ];

        $clearance = $adapter->sync(
            reservationUid: 'ovf_res_phones',
            propertyId: '1606',
            checkIn: '2026-12-10',
            checkOut: '2026-12-15',
            guests: $guests
        );

        $this->assertTrue($clearance->isSynced());
        $this->assertSame('777', $clearance->clearanceNumber);

        $step2 = $requests[1];
        $this->assertSame(
            ['+1 305 555 0100', '+1 305 555 0100', '+1 416 555 0200'],
            $step2['data']['phone']
        );
        $this->assertSame(['TITULAR', 'ACOMPAÑANTE', 'ACOMPAÑANTE'], $step2['data']['nexo']);
    }

    public function testStep1FailsWithHttp500RecordsFailedClearance(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public int $callCount = 0;
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->callCount++;
                return [
                    'statusCode' => 500,
                    'body' => 'Database connection failed on external server',
                    'headers' => [],
                    'cookies' => [],
                    'error' => null,
                ];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            [
                'first_name' => 'Carlos',
                'last_name' => 'Perez',
                'doc_type' => 'CC',
                'doc_number' => '12345',
                'country' => 'Colombia',
                'phone' => '3001234567',
            ],
        ];

        $clearance = $adapter->sync(
            reservationUid: 'ovf_res_fail_step1',
            propertyId: '1707',
            checkIn: '2026-11-01',
            checkOut: '2026-11-05',
            guests: $guests
        );

        $this->assertTrue($clearance->isFailed());
        $this->assertFalse($clearance->isSynced());
        $this->assertStringContainsString('Step 1 returned unexpected HTTP status 500', $clearance->errorMessage ?? '');
        $this->assertSame(1, $mockTransport->callCount); // Step 2 must never be executed

        $persisted = $this->repository->findByReservationUid('ovf_res_fail_step1');
        $this->assertNotNull($persisted);
        $this->assertTrue($persisted->isFailed());
    }

    public function testStep1MissingLastIdRecordsFailedClearance(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                return [
                    'statusCode' => 200,
                    'body' => json_encode(['status' => 'error', 'message' => 'Invalid dates']),
                    'headers' => [],
                    'cookies' => [],
                    'error' => null,
                ];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            [
                'first_name' => 'Carlos',
                'last_name' => 'Perez',
                'doc_type' => 'CC',
                'doc_number' => '12345',
                'country' => 'Colombia',
                'phone' => '3001234567',
            ],
        ];

        $clearance = $adapter->sync('ovf_res_bad_json', '1707', '2026-11-01', '2026-11-05', $guests);

        $this->assertTrue($clearance->isFailed());
        $this->assertStringContainsString("Step 1 response missing valid 'last_id'", $clearance->errorMessage ?? '');
    }

    public function testStep2TransportExceptionRecordedWithoutUncaughtException(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return [
                        'statusCode' => 200,
                        'body' => json_encode(['last_id' => 999]),
                        'headers' => [],
                        'cookies' => [],
                        'error' => null,
                    ];
                }
                throw new RuntimeException('Connection timed out after 15 seconds');
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            [
                'first_name' => 'Carlos',
                'last_name' => 'Perez',
                'doc_type' => 'CC',
                'doc_number' => '12345',
                'country' => 'Colombia',
                'phone' => '3001234567',
            ],
        ];

        $clearance = $adapter->sync('ovf_res_step2_exc', '1707', '2026-11-01', '2026-11-05', $guests);

        $this->assertTrue($clearance->isFailed());
        $this->assertStringContainsString('Step 2 transport exception: Connection timed out', $clearance->errorMessage ?? '');
    }

    public function testStep2RedirectsToErrorPageRecordsFailure(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return [
                        'statusCode' => 200,
                        'body' => json_encode(['last_id' => 888]),
                        'headers' => [],
                        'cookies' => [],
                        'error' => null,
                    ];
                }
                return [
                    'statusCode' => 302,
                    'body' => '',
                    'headers' => ['location' => 'error.php?fallo=doc_duplicate'],
                    'cookies' => [],
                    'error' => null,
                ];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            [
                'first_name' => 'Carlos',
                'last_name' => 'Perez',
                'doc_type' => 'CC',
                'doc_number' => '12345',
                'country' => 'Colombia',
                'phone' => '3001234567',
            ],
        ];

        $clearance = $adapter->sync('ovf_res_step2_err', '1707', '2026-11-01', '2026-11-05', $guests);

        $this->assertTrue($clearance->isFailed());
        $this->assertStringContainsString('Step 2 redirected to error page: error.php?fallo=doc_duplicate', $clearance->errorMessage ?? '');
    }

    public function testUnconfiguredPropertyFailsGracefully(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public int $called = 0;
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->called++;
                return ['statusCode' => 200, 'body' => '', 'headers' => [], 'cookies' => [], 'error' => null];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $clearance = $adapter->sync('ovf_res_bad_prop', '9999', '2026-11-01', '2026-11-05', [
            ['first_name' => 'A', 'last_name' => 'B', 'doc_type' => 'CC', 'doc_number' => '1', 'country' => 'CO', 'phone' => '123'],
        ]);

        $this->assertTrue($clearance->isFailed());
        $this->assertStringContainsString('No check token configured for property ID: 9999', $clearance->errorMessage ?? '');
        $this->assertSame(0, $mockTransport->called);
    }

    public function testEmptyOccupantsFailsGracefully(): void
    {
        $mockTransport = new class implements HttpTransportInterface {
            public int $called = 0;
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->called++;
                return ['statusCode' => 200, 'body' => '', 'headers' => [], 'cookies' => [], 'error' => null];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $clearance = $adapter->sync('ovf_res_empty', '1707', '2026-11-01', '2026-11-05', []);

        $this->assertTrue($clearance->isFailed());
        $this->assertStringContainsString('Occupant list is empty', $clearance->errorMessage ?? '');
        $this->assertSame(0, $mockTransport->called);
    }

    public function testRetryIncrementsAttemptCount(): void
    {
        $attempt = 0;
        $mockTransport = new class($attempt) implements HttpTransportInterface {
            public function __construct(public int &$attempt) {}

            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->attempt++;
                if ($this->attempt === 1) {
                    return ['statusCode' => 500, 'body' => 'Temporary network error', 'headers' => [], 'cookies' => [], 'error' => null];
                }
                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return [
                        'statusCode' => 200,
                        'body' => json_encode(['last_id' => 505]),
                        'headers' => [],
                        'cookies' => ['PHPSESSID' => 's2'],
                        'error' => null,
                    ];
                }
                return [
                    'statusCode' => 302,
                    'body' => '',
                    'headers' => ['location' => 'success.php?hpsucc=succ'],
                    'cookies' => [],
                    'error' => null,
                ];
            }
        };

        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport
        );

        $guests = [
            ['first_name' => 'Carlos', 'last_name' => 'Perez', 'doc_type' => 'CC', 'doc_number' => '123', 'country' => 'CO', 'phone' => '+57 300 1234567'],
        ];

        // 1st attempt: fails
        $c1 = $adapter->sync('ovf_res_retry', '1707', '2026-11-01', '2026-11-05', $guests);
        $this->assertTrue($c1->isFailed());
        $this->assertSame(1, $c1->attempts);

        // 2nd attempt: succeeds
        $c2 = $adapter->sync('ovf_res_retry', '1707', '2026-11-01', '2026-11-05', $guests);
        $this->assertTrue($c2->isSynced());
        $this->assertSame(2, $c2->attempts);
        $this->assertSame('505', $c2->clearanceNumber);
    }

    public function testBaseUrlPathNormalizationDoesNotDuplicatePath(): void
    {
        $requests = [];
        $mockTransport = new class($requests) implements HttpTransportInterface {
            public function __construct(public array &$requests) {}
            public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array
            {
                $this->requests[] = $url;
                if (str_contains($url, 'reg_guest_owner_pre.php')) {
                    return ['statusCode' => 200, 'body' => json_encode(['last_id' => 101]), 'headers' => [], 'cookies' => [], 'error' => null];
                }
                return ['statusCode' => 302, 'body' => '', 'headers' => ['location' => 'success.php'], 'cookies' => [], 'error' => null];
            }
        };

        // Pass URL already containing /propietarios/production and trailing slash
        $adapter = new HuespedManagerClearanceSync(
            repository: $this->repository,
            transport: $mockTransport,
            baseUrl: 'https://salguerosunset.huespedmanager.com.co/propietarios/production/'
        );

        $adapter->sync('ovf_res_norm', '1606', '2026-11-01', '2026-11-05', [
            ['first_name' => 'Carlos', 'last_name' => 'Perez', 'doc_type' => 'CC', 'doc_number' => '123', 'country' => 'CO', 'phone' => '+57 300 1234567'],
        ]);

        $this->assertCount(2, $requests);
        $this->assertSame(
            'https://salguerosunset.huespedmanager.com.co/propietarios/production/reg_guest_owner_pre.php?unit=1606&check=ep24281580',
            $requests[0]
        );
        $this->assertSame(
            'https://salguerosunset.huespedmanager.com.co/propietarios/production/reg_hpds_pre.php',
            $requests[1]
        );
    }
}
