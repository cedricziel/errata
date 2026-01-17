<?php

declare(strict_types=1);

namespace App\Entity;

interface OrganizationAwareInterface
{
    public function getOrganization(): Organization;

    public function setOrganization(Organization $organization): static;
}
