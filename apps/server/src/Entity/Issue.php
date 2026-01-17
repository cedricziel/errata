<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\Table(name: 'issues')]
#[ORM\Index(columns: ['fingerprint'], name: 'idx_issue_fingerprint')]
#[ORM\Index(columns: ['project_id', 'status'], name: 'idx_issue_project_status')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_issue_last_seen')]
#[ORM\UniqueConstraint(name: 'uniq_project_fingerprint', columns: ['project_id', 'fingerprint'])]
class Issue
{
    public const TYPE_CRASH = 'crash';
    public const TYPE_ERROR = 'error';
    public const TYPE_LOG = 'log';

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_IGNORED = 'ignored';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $publicId = null;

    #[ORM\ManyToOne(inversedBy: 'issues')]
    #[ORM\JoinColumn(nullable: false)]
    private Project $project;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $fingerprint = '';

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type = self::TYPE_ERROR;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $culprit = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $severity = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $occurrenceCount = 1;

    #[ORM\Column(type: Types::INTEGER)]
    private int $affectedUsers = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->publicId = Uuid::v7();
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): ?Uuid
    {
        return $this->publicId;
    }

    public function setPublicId(Uuid $publicId): static
    {
        $this->publicId = $publicId;

        return $this;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        if (self::STATUS_RESOLVED === $status) {
            $this->resolvedAt = new \DateTimeImmutable();
        } elseif (self::STATUS_OPEN === $status) {
            $this->resolvedAt = null;
        }

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCulprit(): ?string
    {
        return $this->culprit;
    }

    public function setCulprit(?string $culprit): static
    {
        $this->culprit = $culprit;

        return $this;
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(?string $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function setOccurrenceCount(int $occurrenceCount): static
    {
        $this->occurrenceCount = $occurrenceCount;

        return $this;
    }

    public function incrementOccurrenceCount(): static
    {
        ++$this->occurrenceCount;
        $this->lastSeenAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAffectedUsers(): int
    {
        return $this->affectedUsers;
    }

    public function setAffectedUsers(int $affectedUsers): static
    {
        $this->affectedUsers = $affectedUsers;

        return $this;
    }

    public function incrementAffectedUsers(): static
    {
        ++$this->affectedUsers;

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function isResolved(): bool
    {
        return self::STATUS_RESOLVED === $this->status;
    }

    public function isIgnored(): bool
    {
        return self::STATUS_IGNORED === $this->status;
    }
}
