<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

trait OrganizationAwareTrait
{
    #[ORM\ManyToOne(targetEntity: Organization::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    private Organization $organization;

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }
}
