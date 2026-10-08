<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Service;

use OceanViewFlats\Domain\Support\PublicUrlBuilder as DomainPublicUrlBuilder;

/**
 * Backward compatibility subclass for domain PublicUrlBuilder.
 *
 * @see \OceanViewFlats\Domain\Support\PublicUrlBuilder
 */
class PublicUrlBuilder extends DomainPublicUrlBuilder
{
}
