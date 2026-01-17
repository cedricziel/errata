<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    public function findByPublicId(Uuid|string $publicId): ?Organization
    {
        if (is_string($publicId)) {
            $publicId = Uuid::fromString($publicId);
        }

        return $this->findOneBy(['publicId' => $publicId]);
    }

    public function findBySlug(string $slug): ?Organization
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function save(Organization $organization, bool $flush = false): void
    {
        $this->getEntityManager()->persist($organization);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Organization $organization, bool $flush = false): void
    {
        $this->getEntityManager()->remove($organization);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
