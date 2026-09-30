<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Cli;

use InvalidArgumentException;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Db\DatabaseFactory;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service encapsulating administrator account provisioning from the CLI
 * with Argon2id password hashing, input validation, and idempotent upserts.
 */
final class AdminUserProvisioner
{
    /**
     * @param array<string, int> $argonOptions
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $argonOptions = AuthService::DEFAULT_ARGON2_OPTIONS
    ) {
    }

    /**
     * Provisions (creates or updates) an administrator account.
     *
     * @return array{
     *     status: 'created'|'updated',
     *     email: string,
     *     name: string,
     *     role: string,
     *     id: int
     * }
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on database failure
     */
    public function provision(string $email, string $name, string $password, string $role = 'admin'): array
    {
        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address provided.');
        }

        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Name is required and cannot be empty.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters long.');
        }

        $trimmedRole = trim($role);
        if ($trimmedRole === '') {
            $trimmedRole = 'admin';
        }

        $passwordHash = $this->hashPassword($password);

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM admin_users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $normalizedEmail]);
            /** @var array{id: int|string}|false $existing */
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing !== false) {
                $userId = (int) $existing['id'];
                $updateStmt = $this->pdo->prepare('
                    UPDATE admin_users
                    SET password_hash = :password_hash,
                        name = :name,
                        role = :role,
                        is_active = 1,
                        failed_login_attempts = 0,
                        locked_until = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $updateStmt->execute([
                    ':password_hash' => $passwordHash,
                    ':name' => $trimmedName,
                    ':role' => $trimmedRole,
                    ':id' => $userId,
                ]);

                return [
                    'status' => 'updated',
                    'email' => $normalizedEmail,
                    'name' => $trimmedName,
                    'role' => $trimmedRole,
                    'id' => $userId,
                ];
            }

            $insertStmt = $this->pdo->prepare('
                INSERT INTO admin_users (email, password_hash, name, role, is_active, failed_login_attempts, locked_until)
                VALUES (:email, :password_hash, :name, :role, 1, 0, NULL)
            ');
            $insertStmt->execute([
                ':email' => $normalizedEmail,
                ':password_hash' => $passwordHash,
                ':name' => $trimmedName,
                ':role' => $trimmedRole,
            ]);

            $lastId = $this->pdo->lastInsertId();
            $newId = is_numeric($lastId) ? (int) $lastId : 0;

            return [
                'status' => 'created',
                'email' => $normalizedEmail,
                'name' => $trimmedName,
                'role' => $trimmedRole,
                'id' => $newId,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException('Database provisioning error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Hashes password using Argon2id if available, falling back gracefully.
     */
    public function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, $this->argonOptions);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Parses CLI arguments from getopt and/or an $argv array.
     *
     * @param array<int, string> $argvInput
     * @param array<string, mixed> $getoptOpts
     * @return array{email: ?string, name: ?string, password: ?string, role: string}
     */
    public static function parseArguments(array $argvInput, array $getoptOpts = []): array
    {
        $opts = $getoptOpts;

        $count = count($argvInput);
        $start = (isset($argvInput[0]) && !str_starts_with($argvInput[0], '-')) ? 1 : 0;

        for ($i = $start; $i < $count; $i++) {
            $arg = $argvInput[$i];
            if (str_starts_with($arg, '--')) {
                $raw = substr($arg, 2);
                if (str_contains($raw, '=')) {
                    [$k, $v] = explode('=', $raw, 2);
                    $opts[$k] = $v;
                } else {
                    $k = $raw;
                    if ($i + 1 < $count && !str_starts_with($argvInput[$i + 1], '-')) {
                        $opts[$k] = $argvInput[++$i];
                    } else {
                        $opts[$k] = true;
                    }
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $k = substr($arg, 1);
                if ($i + 1 < $count && !str_starts_with($argvInput[$i + 1], '-')) {
                    $opts[$k] = $argvInput[++$i];
                } else {
                    $opts[$k] = true;
                }
            }
        }

        $email = $opts['email'] ?? $opts['e'] ?? null;
        $name = $opts['name'] ?? $opts['n'] ?? null;
        $password = $opts['password'] ?? $opts['p'] ?? null;
        $role = $opts['role'] ?? $opts['r'] ?? 'admin';

        return [
            'email' => is_string($email) ? $email : null,
            'name' => is_string($name) ? $name : null,
            'password' => is_string($password) ? $password : null,
            'role' => is_string($role) && $role !== '' ? $role : 'admin',
        ];
    }

    /**
     * Resolves a PDO connection using an injected instance, global test instance,
     * or dual-path configuration discovery (public/api/config.php or public_html/api/config.php).
     */
    public static function resolvePdo(?PDO $injectedPdo = null, ?string $baseDir = null): ?PDO
    {
        if ($injectedPdo instanceof PDO) {
            return $injectedPdo;
        }

        if (isset($GLOBALS['TEST_PDO']) && $GLOBALS['TEST_PDO'] instanceof PDO) {
            return $GLOBALS['TEST_PDO'];
        }

        $root = $baseDir ?? dirname(__DIR__, 3);
        $possibleConfigs = [
            $root . '/public/api/config.php',
            $root . '/public_html/api/config.php',
        ];

        $config = null;
        foreach ($possibleConfigs as $path) {
            if (file_exists($path)) {
                $config = require $path;
                break;
            }
        }

        if (!is_array($config) || empty($config['db']) || !is_array($config['db'])) {
            return null;
        }

        /** @var array{host?: string, dbname?: string, user?: string, pass?: string} $dbConfig */
        $dbConfig = $config['db'];
        try {
            return DatabaseFactory::createConnection($dbConfig);
        } catch (Throwable) {
            return null;
        }
    }
}
