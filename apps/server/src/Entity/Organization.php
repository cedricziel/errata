<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private ?Uuid $publicId = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 255, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, OrganizationMembership> */
    #[ORM\OneToMany(targetEntity: OrganizationMembership::class, mappedBy: 'organization', orphanRemoval: true)]
    private Collection $memberships;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'organization')]
    private Collection $projects;

    public function __construct()
    {
        $this->publicId = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
        $this->projects = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

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

    /** @return Collection<int, OrganizationMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(OrganizationMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setOrganization($this);
        }

        return $this;
    }

    public function removeMembership(OrganizationMembership $membership): static
    {
        $this->memberships->removeElement($membership);

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
            $project->setOrganization($this);
        }

        return $this;
    }

    public function removeProject(Project $project): static
    {
        $this->projects->removeElement($project);

        return $this;
    }
}
