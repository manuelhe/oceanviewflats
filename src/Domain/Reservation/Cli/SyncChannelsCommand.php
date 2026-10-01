<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Cli;

use InvalidArgumentException;
use OceanViewFlats\Domain\Reservation\ChannelSyncResult;
use OceanViewFlats\Domain\Reservation\ChannelSyncStatus;
use OceanViewFlats\Domain\Reservation\InboundChannelSyncService;
use OceanViewFlats\Domain\Reservation\InboundChannelSyncServiceInterface;
use Throwable;

/**
 * Command-line runner for inbound channel synchronization (ADR 0002).
 *
 * Supports invocation via cPanel 15-minute cron jobs or manual CLI administration,
 * providing structured summaries, cooldown bypass (--force), and per-property scoping (--property).
 */
final class SyncChannelsCommand
{
    public const SOURCE_CRON = 'cron';

    /**
     * Executes the CLI synchronization command.
     *
     * @param array<int, string> $argv
     * @param ?InboundChannelSyncServiceInterface $syncService
     * @param mixed $stdout Stream resource or null for STDOUT
     * @param mixed $stderr Stream resource or null for STDERR
     * @return int Shell exit code (0 on success or degraded with retained cache, 1 on error)
     */
    public static function run(
        array $argv,
        ?InboundChannelSyncServiceInterface $syncService = null,
        mixed $stdout = null,
        mixed $stderr = null,
    ): int {
        $out = is_resource($stdout) ? $stdout : (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        $err = is_resource($stderr) ? $stderr : (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'));

        try {
            $parsed = self::parseArguments($argv);
        } catch (InvalidArgumentException $e) {
            self::writeStream($err, "Error: " . $e->getMessage() . "\n");
            self::writeStream($err, "Run with --help for usage information.\n");
            return 1;
        }

        if ($parsed['help']) {
            self::printHelp($out);
            return 0;
        }

        try {
            $service = $syncService ?? InboundChannelSyncService::createDefault();
        } catch (Throwable $e) {
            self::writeStream($err, "[ERROR] Failed to initialize synchronization service: " . $e->getMessage() . "\n");
            return 1;
        }

        $property = $parsed['property'];
        $force = $parsed['force'];

        // Validate property if specified and service exposes configured feed URLs
        if ($property !== null) {
            /** @var array<string|int, string> $feedUrls */
            $feedUrls = $service->getFeedUrls();
            if (!empty($feedUrls) && !array_key_exists($property, $feedUrls)) {
                $configured = implode(', ', array_map('strval', array_keys($feedUrls)));
                self::writeStream(
                    $err,
                    "Error: Unknown property '{$property}'. Configured properties: {$configured}.\n"
                );
                return 1;
            }
        }

        /** @var array<string|int, ChannelSyncResult> $results */
        $results = [];

        try {
            if ($property !== null) {
                $results[$property] = $service->sync($property, $force, self::SOURCE_CRON);
            } else {
                $results = $service->syncAll($force, self::SOURCE_CRON);
            }
        } catch (InvalidArgumentException $e) {
            self::writeStream($err, "Error: " . $e->getMessage() . "\n");
            return 1;
        } catch (Throwable $e) {
            self::writeStream($err, "[ERROR] Fatal synchronization error: " . $e->getMessage() . "\n");
            return 1;
        }

        return self::renderSummary($results, $out);
    }

    /**
     * Parses CLI arguments into structured options.
     *
     * @param array<int, string> $argv
     * @return array{help: bool, force: bool, property: ?string}
     * @throws InvalidArgumentException On invalid option or missing argument value
     */
    public static function parseArguments(array $argv): array
    {
        $help = false;
        $force = false;
        $property = null;

        $count = count($argv);
        $start = (isset($argv[0]) && !str_starts_with($argv[0], '-')) ? 1 : 0;

        for ($i = $start; $i < $count; $i++) {
            $arg = trim($argv[$i]);
            if ($arg === '') {
                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                $help = true;
                continue;
            }

            if ($arg === '--force' || $arg === '-f') {
                $force = true;
                continue;
            }

            if (str_starts_with($arg, '--property=')) {
                $val = trim(substr($arg, 11));
                if ($val === '') {
                    throw new InvalidArgumentException("Option '--property' requires a non-empty property identifier.");
                }
                $property = $val;
                continue;
            }

            if (str_starts_with($arg, '-p=')) {
                $val = trim(substr($arg, 3));
                if ($val === '') {
                    throw new InvalidArgumentException("Option '-p' requires a non-empty property identifier.");
                }
                $property = $val;
                continue;
            }

            if ($arg === '--property' || $arg === '-p') {
                if ($i + 1 >= $count || str_starts_with($argv[$i + 1], '-')) {
                    throw new InvalidArgumentException("Option '{$arg}' requires a property identifier.");
                }
                $i++;
                $val = trim($argv[$i]);
                if ($val === '') {
                    throw new InvalidArgumentException("Option '{$arg}' requires a non-empty property identifier.");
                }
                $property = $val;
                continue;
            }

            if (str_starts_with($arg, '-')) {
                throw new InvalidArgumentException("Unrecognized option '{$arg}'.");
            }

            throw new InvalidArgumentException("Unexpected argument '{$arg}'.");
        }

        return [
            'help' => $help,
            'force' => $force,
            'property' => $property,
        ];
    }

    /**
     * Renders human-readable summary to stdout and determines exit code.
     *
     * @param array<string|int, ChannelSyncResult> $results
     * @param mixed $out Output stream
     * @return int Shell exit code (0 on success or degraded with retained cache, 1 on error)
     */
    private static function renderSummary(array $results, mixed $out): int
    {
        if (empty($results)) {
            self::writeStream($out, "[WARNING] No channel feeds configured for synchronization.\n");
            return 0;
        }

        $hasError = false;
        $hasDegraded = false;

        foreach ($results as $result) {
            $status = $result->getStatus();
            if ($status->isError()) {
                $hasError = true;
            } elseif ($status->isDegraded()) {
                $hasDegraded = true;
            }
        }

        if ($hasError) {
            self::writeStream($out, "[ERROR] Channel Sync Completed with errors (source: " . self::SOURCE_CRON . ")\n");
        } elseif ($hasDegraded) {
            self::writeStream($out, "[WARNING] Channel Sync Completed with warnings (source: " . self::SOURCE_CRON . ")\n");
        } else {
            self::writeStream($out, "[OK] Channel Sync Completed (source: " . self::SOURCE_CRON . ")\n");
        }

        foreach ($results as $propertyId => $result) {
            $status = $result->getStatus();
            $blockedCount = $result->getBlockedNightsCount();
            $propLabel = (string) $propertyId;

            if ($status->isHealthy()) {
                if ($result->wasSkippedDueToCooldown()) {
                    self::writeStream($out, " - Property {$propLabel}: healthy ({$blockedCount} blocked nights, skipped due to cooldown)\n");
                } else {
                    self::writeStream($out, " - Property {$propLabel}: healthy ({$blockedCount} blocked nights)\n");
                }
            } elseif ($status->isDegraded()) {
                $reason = $status->getErrorMessage() ?? $result->getMessage() ?? 'Upstream channel unavailable';
                self::writeStream($out, " - Property {$propLabel}: degraded ({$blockedCount} blocked nights, retained from cache - {$reason})\n");
            } else {
                $reason = $status->getErrorMessage() ?? $result->getMessage() ?? 'Upstream channel fetch failed';
                self::writeStream($out, " - Property {$propLabel}: error ({$blockedCount} blocked nights, no cached dates available - {$reason})\n");
            }
        }

        // Return exit code 0 on success or retained cache on degraded; non-zero on fatal error
        return $hasError ? 1 : 0;
    }

    /**
     * Prints CLI usage instructions.
     *
     * @param mixed $out
     */
    public static function printHelp(mixed $out): void
    {
        $help = <<<HELP
OceanViewFlats - Inbound Channel Synchronization CLI

Usage:
  php scripts/sync-channels.php [options]

Options:
  -p, --property=<id>   Synchronize only the specified property (e.g. 1606, 1707)
  -f, --force           Bypass the 60-second cooldown window
  -h, --help            Display this help message

Examples:
  # Background cron run (sync all properties)
  php scripts/sync-channels.php

  # Synchronize a specific property
  php scripts/sync-channels.php --property=1606

  # Force immediate refresh bypassing 60-second cooldown
  php scripts/sync-channels.php --force

HELP;

        self::writeStream($out, $help);
    }

    /**
     * Writes string to given resource stream safely.
     *
     * @param mixed $stream
     * @param string $data
     */
    private static function writeStream(mixed $stream, string $data): void
    {
        if (is_resource($stream)) {
            fwrite($stream, $data);
        } else {
            echo $data;
        }
    }
}
