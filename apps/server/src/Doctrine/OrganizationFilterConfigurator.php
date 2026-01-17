<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Doctrine\Filter\OrganizationFilter;
use Doctrine\ORM\EntityManagerInterface;

class OrganizationFilterConfigurator
{
    private const FILTER_NAME = 'organization';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function enable(int $organizationId): void
    {
        $filter = $this->entityManager->getFilters()->enable(self::FILTER_NAME);

        if ($filter instanceof OrganizationFilter) {
            $filter->setParameter('organization_id', $organizationId);
        }
    }

    public function disable(): void
    {
        $filters = $this->entityManager->getFilters();

        if ($filters->isEnabled(self::FILTER_NAME)) {
            $filters->disable(self::FILTER_NAME);
        }
    }

    public function isEnabled(): bool
    {
        return $this->entityManager->getFilters()->isEnabled(self::FILTER_NAME);
    }
}
