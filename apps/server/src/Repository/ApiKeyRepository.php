<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Find an API key by its hash.
     */
    public function findByKeyHash(string $keyHash): ?ApiKey
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    /**
     * Find API key by plain text key (hashes it first).
     */
    public function findByPlainKey(string $plainKey): ?ApiKey
    {
        $hash = hash('sha256', $plainKey);

        return $this->findByKeyHash($hash);
    }

    /**
     * Find active API key by plain text key.
     */
    public function findValidByPlainKey(string $plainKey): ?ApiKey
    {
        $apiKey = $this->findByPlainKey($plainKey);

        if (null === $apiKey || !$apiKey->isValid()) {
            return null;
        }

        return $apiKey;
    }

    /**
     * @return ApiKey[]
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'DESC']);
    }

    /**
     * @return ApiKey[]
     */
    public function findActiveByProject(Project $project): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.project = :project')
            ->andWhere('a.isActive = :active')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->setParameter('project', $project)
            ->setParameter('active', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function updateLastUsed(ApiKey $apiKey): void
    {
        $apiKey->setLastUsedAt(new \DateTimeImmutable());
        $this->getEntityManager()->flush();
    }

    public function save(ApiKey $apiKey, bool $flush = false): void
    {
        $this->getEntityManager()->persist($apiKey);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiKey $apiKey, bool $flush = false): void
    {
        $this->getEntityManager()->remove($apiKey);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
