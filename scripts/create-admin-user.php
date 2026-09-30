<?php
/**
 * OceanViewFlats - CLI Administrator User Provisioning Utility
 * PHP 8.3 Compatible
 *
 * Bootstraps or updates administrative accounts directly from the command line,
 * enforcing Argon2id password hashing, input validation, and credential protection.
 *
 * Usage:
 *   php scripts/create-admin-user.php --email=admin@oceanviewflats.com --name="Admin User" --password=secretPass [--role=admin]
 */

declare(strict_types=1);

$effectiveSapi = $simulatedSapi ?? php_sapi_name();
if ($effectiveSapi !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    if (!empty($noExit)) {
        return 1;
    }
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OceanViewFlats\Admin\Cli\AdminUserProvisioner;

// 1. Resolve PDO Connection (injected PDO, global test PDO, or dual-path config resolution)
/** @var PDO|null $activePdo */
$activePdo = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $activePdo = $pdo;
} elseif (isset($testPdo) && $testPdo instanceof PDO) {
    $activePdo = $testPdo;
} elseif (isset($GLOBALS['TEST_PDO']) && $GLOBALS['TEST_PDO'] instanceof PDO) {
    $activePdo = $GLOBALS['TEST_PDO'];
} else {
    $configRoot = getenv('OVF_CONFIG_ROOT') ?: dirname(__DIR__);
    $activePdo = AdminUserProvisioner::resolvePdo(null, $configRoot);
}

if ($activePdo === null) {
    fwrite(STDERR, "Error: Configuration file not found at public/api/config.php or public_html/api/config.php\n");
    $exitCode = 1;
} else {
    // 2. Parse Arguments (supports long options, short options, getopt, and custom argv)
    $getoptOpts = getopt('e:n:p:r:', ['email:', 'name:', 'password:', 'role:']);
    $rawArgv = $argv ?? ($_SERVER['argv'] ?? []);
    $cliArgs = AdminUserProvisioner::parseArguments($rawArgv, is_array($getoptOpts) ? $getoptOpts : []);

    // 3. Validate Inputs
    $email = $cliArgs['email'];
    $name = $cliArgs['name'];
    $password = $cliArgs['password'];
    $role = $cliArgs['role'];

    if ($email === null || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Error: Invalid or missing email address. Please provide a valid email via --email.\n");
        $exitCode = 1;
    } elseif ($name === null || trim($name) === '') {
        fwrite(STDERR, "Error: Name is required and cannot be empty. Please provide a name via --name.\n");
        $exitCode = 1;
    } elseif ($password === null || strlen($password) < 8) {
        fwrite(STDERR, "Error: Password must be at least 8 characters long. Please provide a valid password via --password.\n");
        $exitCode = 1;
    } else {
        // 4. Provision Administrator Account
        try {
            $provisioner = new AdminUserProvisioner($activePdo);
            $result = $provisioner->provision($email, $name, $password, $role);

            if ($result['status'] === 'created') {
                echo "Admin user successfully created: " . $result['email'] . " (Role: " . $result['role'] . ")\n";
            } else {
                echo "Admin user successfully updated: " . $result['email'] . " (Role: " . $result['role'] . ")\n";
            }
            $exitCode = 0;
        } catch (Throwable $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            $exitCode = 1;
        }
    }
}

// 5. Exit or Return (for test harness compatibility)
$isDirectExecution = !defined('PHPUNIT_COMPOSER_INSTALL')
    && !defined('__PHPUNIT_PHAR__')
    && empty($noExit)
    && (realpath(get_included_files()[0] ?? '') === realpath(__FILE__));

if ($isDirectExecution) {
    exit($exitCode);
}

return $exitCode;
