<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class OrganizationSwitcher
{
    private const SESSION_KEY = 'current_organization_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    public function getCurrentOrganizationId(): ?int
    {
        $session = $this->requestStack->getSession();

        return $session->get(self::SESSION_KEY);
    }

    public function getCurrentOrganization(): ?Organization
    {
        $organizationId = $this->getCurrentOrganizationId();

        if (null === $organizationId) {
            return null;
        }

        return $this->organizationRepository->find($organizationId);
    }

    public function setCurrentOrganization(Organization $organization): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $organization->getId());
    }

    public function setCurrentOrganizationId(int $organizationId): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KEY, $organizationId);
    }

    public function clearCurrentOrganization(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }

    public function initializeForUser(User $user): ?Organization
    {
        $existingOrgId = $this->getCurrentOrganizationId();

        if (null !== $existingOrgId) {
            $organization = $this->organizationRepository->find($existingOrgId);
            if (null !== $organization && $user->isMemberOf($organization)) {
                return $organization;
            }
        }

        $defaultOrganization = $user->getDefaultOrganization();

        if (null !== $defaultOrganization) {
            $this->setCurrentOrganization($defaultOrganization);
        }

        return $defaultOrganization;
    }
}
