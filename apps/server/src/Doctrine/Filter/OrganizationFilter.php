<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use App\Entity\OrganizationAwareInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class OrganizationFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $reflClass = $targetEntity->getReflectionClass();

        if (!$reflClass->implementsInterface(OrganizationAwareInterface::class)) {
            return '';
        }

        if (!$this->hasParameter('organization_id')) {
            return '';
        }

        return sprintf(
            '%s.organization_id = %s',
            $targetTableAlias,
            $this->getParameter('organization_id')
        );
    }
}
