<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationMembership>
 */
class OrganizationMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMembership::class);
    }

    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['joinedAt' => 'DESC']);
    }

    public function findByOrganization(Organization $organization): array
    {
        return $this->findBy(['organization' => $organization], ['joinedAt' => 'ASC']);
    }

    public function findOneByUserAndOrganization(User $user, Organization $organization): ?OrganizationMembership
    {
        return $this->findOneBy([
            'user' => $user,
            'organization' => $organization,
        ]);
    }

    /**
     * @return OrganizationMembership[]
     */
    public function findByRole(Organization $organization, string $role): array
    {
        return $this->findBy([
            'organization' => $organization,
            'role' => $role,
        ]);
    }

    /**
     * @return OrganizationMembership[]
     */
    public function findOwners(Organization $organization): array
    {
        return $this->findByRole($organization, OrganizationMembership::ROLE_OWNER);
    }

    /**
     * @return OrganizationMembership[]
     */
    public function findAdmins(Organization $organization): array
    {
        return $this->findByRole($organization, OrganizationMembership::ROLE_ADMIN);
    }

    public function save(OrganizationMembership $membership, bool $flush = false): void
    {
        $this->getEntityManager()->persist($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(OrganizationMembership $membership, bool $flush = false): void
    {
        $this->getEntityManager()->remove($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
