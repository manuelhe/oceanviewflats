<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Setup;

use InvalidArgumentException;
use OceanViewFlats\Admin\Cli\AdminUserProvisioner;
use OceanViewFlats\Admin\Config\ConfigPathResolver;
use OceanViewFlats\Admin\Db\DatabaseFactory;
use OceanViewFlats\Domain\Database\MigrationRunner;
use OceanViewFlats\Domain\Support\EnvLoader;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Service orchestrating secure web-based setup and migrations.
 *
 * Enforces token authentication and permanent auto-lockout once an administrator user exists.
 */
final class SetupRunner
{
    /**
     * @param list<string> $allowedTokens
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dbName,
        private readonly array $allowedTokens
    ) {
    }

    /**
     * Factory creating a SetupRunner instance from project configuration.
     */
    public static function create(?PDO $injectedPdo = null, ?string $baseDir = null): self
    {
        $root = $baseDir ?? (getenv('OVF_CONFIG_ROOT') ?: dirname(__DIR__, 3));
        if (class_exists(EnvLoader::class)) {
            EnvLoader::load($root);
        }

        $configPath = ConfigPathResolver::resolveConfigPath($root);
        /** @var mixed $config */
        $config = require $configPath;

        if (!is_array($config) || empty($config['db']) || !is_array($config['db'])) {
            throw new RuntimeException("Database configuration ('db') missing in '{$configPath}'.");
        }

        /** @var array{host?: string, dbname?: string, user?: string, pass?: string} $dbConfig */
        $dbConfig = $config['db'];
        $dbName = (string) ($dbConfig['dbname'] ?? 'oceanviewflats_db');

        $pdo = $injectedPdo instanceof PDO
            ? $injectedPdo
            : MigrationRunner::connect($dbConfig);

        $allowedTokens = [];
        $candidates = [
            getenv('OVF_SETUP_TOKEN') ?: ($_ENV['OVF_SETUP_TOKEN'] ?? $_SERVER['OVF_SETUP_TOKEN'] ?? null),
            getenv('OVF_ADMIN_SESSION_SECRET') ?: ($_ENV['OVF_ADMIN_SESSION_SECRET'] ?? $_SERVER['OVF_ADMIN_SESSION_SECRET'] ?? null),
            getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? null),
        ];

        foreach ($candidates as $token) {
            if (is_string($token) && trim($token) !== '') {
                $allowedTokens[] = trim($token);
            }
        }

        return new self($pdo, $dbName, array_values(array_unique($allowedTokens)));
    }

    /**
     * Validates whether the provided token matches any configured pre-shared secret.
     */
    public function isTokenValid(?string $token): bool
    {
        if ($token === null || trim($token) === '' || empty($this->allowedTokens)) {
            return false;
        }

        $trimmedToken = trim($token);
        foreach ($this->allowedTokens as $secret) {
            if (hash_equals($secret, $trimmedToken)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether setup is permanently locked out because an administrator user already exists.
     */
    public function isLocked(): bool
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM admin_users');
            if ($stmt === false) {
                return false;
            }
            $count = (int) $stmt->fetchColumn();
            return $count > 0;
        } catch (Throwable) {
            // If the table does not exist yet, setup is not locked
            return false;
        }
    }

    /**
     * Executes database migrations idempotently.
     *
     * @return array{success: bool, logs: list<string>}
     */
    public function runMigrations(): array
    {
        if ($this->isLocked()) {
            throw new RuntimeException('Setup is locked: administrator account already exists.');
        }

        $logs = MigrationRunner::run($this->pdo, $this->dbName);
        return [
            'success' => true,
            'logs' => $logs,
        ];
    }

    /**
     * Provisions the initial administrator account.
     *
     * @param array<string, mixed> $input
     * @return array{status: 'created'|'updated', email: string, name: string, role: string, id: int}
     */
    public function provisionAdmin(array $input): array
    {
        if ($this->isLocked()) {
            throw new RuntimeException('Setup is locked: administrator account already exists.');
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = trim((string) ($input['role'] ?? 'admin'));

        if ($name === '') {
            throw new InvalidArgumentException('Administrator name is required.');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $provisioner = new AdminUserProvisioner($this->pdo);
        return $provisioner->provision(
            $email,
            $name,
            $password,
            $role !== '' ? $role : 'admin'
        );
    }

    /**
     * Handles incoming web requests and returns an HTTP response structure.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     * @return array{status: int, data: array<string, mixed>}
     */
    public function handleRequest(array $server, array $get, array $post): array
    {
        $token = (string) ($get['token'] ?? $server['HTTP_X_SETUP_TOKEN'] ?? $post['token'] ?? '');

        if (!$this->isTokenValid($token)) {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Unauthorized: Invalid or missing setup token.',
                ],
            ];
        }

        if ($this->isLocked()) {
            return [
                'status' => 403,
                'data' => [
                    'success' => false,
                    'error' => 'Setup is locked: An administrator account already exists. Please log in to the admin dashboard.',
                ],
            ];
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $action = (string) ($post['action'] ?? $get['action'] ?? '');

        try {
            if ($action === 'migrate') {
                $result = $this->runMigrations();
                return [
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'message' => 'Database migrations completed successfully.',
                        'logs' => $result['logs'],
                    ],
                ];
            }

            if ($action === 'create_admin') {
                if ($method !== 'POST') {
                    return [
                        'status' => 405,
                        'data' => [
                            'success' => false,
                            'error' => 'Administrator provisioning requires an HTTP POST request.',
                        ],
                    ];
                }

                $user = $this->provisionAdmin($post);
                return [
                    'status' => 200,
                    'data' => [
                        'success' => true,
                        'message' => 'Administrator provisioned successfully. Setup is now permanently locked.',
                        'user' => [
                            'name' => $user['name'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                        ],
                    ],
                ];
            }

            return [
                'status' => 200,
                'data' => [
                    'success' => true,
                    'message' => 'Setup utility ready. You can run migrations and provision your initial administrator.',
                    'actions' => ['migrate', 'create_admin'],
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 400,
                'data' => [
                    'success' => false,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }
}
