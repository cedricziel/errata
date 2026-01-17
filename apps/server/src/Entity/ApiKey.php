<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'api_keys')]
#[ORM\Index(columns: ['key_hash'], name: 'idx_api_key_hash')]
class ApiKey
{
    public const SCOPE_INGEST = 'ingest';
    public const SCOPE_READ = 'read';
    public const SCOPE_ADMIN = 'admin';

    public const ENV_PRODUCTION = 'production';
    public const ENV_STAGING = 'staging';
    public const ENV_DEVELOPMENT = 'development';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private ?string $keyHash = null;

    #[ORM\Column(type: Types::STRING, length: 12)]
    private ?string $keyPrefix = null;

    #[ORM\ManyToOne(inversedBy: 'apiKeys')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    /** @var array<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [self::SCOPE_INGEST];

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $environment = self::ENV_DEVELOPMENT;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKeyHash(): ?string
    {
        return $this->keyHash;
    }

    public function setKeyHash(string $keyHash): static
    {
        $this->keyHash = $keyHash;

        return $this;
    }

    public function getKeyPrefix(): ?string
    {
        return $this->keyPrefix;
    }

    public function setKeyPrefix(string $keyPrefix): static
    {
        $this->keyPrefix = $keyPrefix;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    /** @return array<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param array<string> $scopes */
    public function setScopes(array $scopes): static
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function setEnvironment(string $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isValid(): bool
    {
        return $this->isActive && !$this->isExpired();
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Generate a new API key and return the plain text version.
     * The hash is stored in the entity.
     */
    public static function generateKey(): array
    {
        $prefix = 'err_'.bin2hex(random_bytes(4));
        $secret = bin2hex(random_bytes(24));
        $plainKey = $prefix.'_'.$secret;
        $hash = hash('sha256', $plainKey);

        return [
            'plain' => $plainKey,
            'prefix' => $prefix,
            'hash' => $hash,
        ];
    }
}
