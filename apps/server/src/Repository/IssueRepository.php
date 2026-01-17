<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Issue>
 */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function findByPublicId(Uuid|string $publicId): ?Issue
    {
        if (is_string($publicId)) {
            $publicId = Uuid::fromString($publicId);
        }

        return $this->findOneBy(['publicId' => $publicId]);
    }

    public function findByFingerprint(Project $project, string $fingerprint): ?Issue
    {
        return $this->findOneBy([
            'project' => $project,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @return Issue[]
     */
    public function findByProject(Project $project, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->orderBy('i.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Issue[]
     */
    public function findOpenIssues(Project $project, int $limit = 50): array
    {
        return $this->findByProject($project, Issue::STATUS_OPEN, $limit);
    }

    /**
     * Create a query builder for filtering issues.
     */
    public function createFilterQueryBuilder(Project $project): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->orderBy('i.lastSeenAt', 'DESC');
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return Issue[]
     */
    public function findByFilters(
        Project $project,
        array $filters = [],
        int $limit = 50,
        int $offset = 0,
    ): array {
        $qb = $this->createFilterQueryBuilder($project);

        if (!empty($filters['status'])) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('i.type = :type')
                ->setParameter('type', $filters['type']);
        }

        if (!empty($filters['severity'])) {
            $qb->andWhere('i.severity = :severity')
                ->setParameter('severity', $filters['severity']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('i.title LIKE :search OR i.culprit LIKE :search')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['from'])) {
            $qb->andWhere('i.lastSeenAt >= :from')
                ->setParameter('from', new \DateTimeImmutable($filters['from']));
        }

        if (!empty($filters['to'])) {
            $qb->andWhere('i.lastSeenAt <= :to')
                ->setParameter('to', new \DateTimeImmutable($filters['to']));
        }

        return $qb
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countByProject(Project $project, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function getIssueCountsByType(Project $project): array
    {
        $result = $this->createQueryBuilder('i')
            ->select('i.type, COUNT(i.id) as count')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->groupBy('i.type')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['type']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * @return array<array{date: string, count: int}>
     */
    public function getIssuesOverTime(Project $project, int $days = 30): array
    {
        $from = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('i')
            ->select('DATE(i.firstSeenAt) as date, COUNT(i.id) as count')
            ->andWhere('i.project = :project')
            ->andWhere('i.firstSeenAt >= :from')
            ->setParameter('project', $project)
            ->setParameter('from', $from)
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Issue $issue, bool $flush = false): void
    {
        $this->getEntityManager()->persist($issue);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Issue $issue, bool $flush = false): void
    {
        $this->getEntityManager()->remove($issue);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
