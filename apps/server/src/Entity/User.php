<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private string $email = '';

    /** @var array<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'owner', orphanRemoval: true)]
    private Collection $projects;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, OrganizationMembership> */
    #[ORM\OneToMany(targetEntity: OrganizationMembership::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $memberships;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->memberships = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /** @return array<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @param array<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): static
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setOwner($this);
        }

        return $this;
    }

    public function removeProject(Project $project): static
    {
        // orphanRemoval: true will handle deletion when removed from collection
        $this->projects->removeElement($project);

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

    /** @return Collection<int, OrganizationMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(OrganizationMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setUser($this);
        }

        return $this;
    }

    public function removeMembership(OrganizationMembership $membership): static
    {
        $this->memberships->removeElement($membership);

        return $this;
    }

    public function getDefaultOrganization(): ?Organization
    {
        $membership = $this->memberships->first();

        return $membership instanceof OrganizationMembership ? $membership->getOrganization() : null;
    }

    public function isMemberOf(Organization $organization): bool
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getOrganization() === $organization) {
                return true;
            }
        }

        return false;
    }

    public function getMembershipFor(Organization $organization): ?OrganizationMembership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getOrganization() === $organization) {
                return $membership;
            }
        }

        return null;
    }

    public function hasRoleIn(Organization $organization, string $role): bool
    {
        $membership = $this->getMembershipFor($organization);

        return null !== $membership && $membership->getRole() === $role;
    }

    public function canManageMembersIn(Organization $organization): bool
    {
        $membership = $this->getMembershipFor($organization);

        return null !== $membership && $membership->canManageMembers();
    }
}
