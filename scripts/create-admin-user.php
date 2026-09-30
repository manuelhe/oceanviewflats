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

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OceanViewFlats\Admin\Cli\AdminUserProvisioner;

$exitCode = AdminUserProvisioner::run($argv);
exit($exitCode);
