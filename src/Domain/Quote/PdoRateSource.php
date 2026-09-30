<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

use PDO;

/**
 * Database-backed rate source that retrieves seasonal rate tiers from MySQL/SQLite
 * with optional fallback to CsvRateSource and CSV seeding utilities.
 * Backward compatibility adapter extending PdoRateRepository.
 */
class PdoRateSource extends PdoRateRepository
{
    public function __construct(
        PDO $pdo,
        ?RateSourceInterface $fallbackSource = null,
        ?PropertyRatesConfig $ratesConfig = null
    ) {
        parent::__construct($pdo, $ratesConfig, $fallbackSource);
    }
}
