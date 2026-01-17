<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationMembershipRepository::class)]
#[ORM\Table(name: 'organization_memberships')]
#[ORM\UniqueConstraint(name: 'unique_user_org', columns: ['user_id', 'organization_id'])]
class OrganizationMembership
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false)]
    private Organization $organization;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function setOrganization(Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;

        return $this;
    }

    public function isOwner(): bool
    {
        return self::ROLE_OWNER === $this->role;
    }

    public function isAdmin(): bool
    {
        return self::ROLE_ADMIN === $this->role;
    }

    public function isMember(): bool
    {
        return self::ROLE_MEMBER === $this->role;
    }

    public function canManageMembers(): bool
    {
        return self::ROLE_OWNER === $this->role || self::ROLE_ADMIN === $this->role;
    }
}
