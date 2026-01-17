<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ApiKey;
use App\Entity\Issue;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\IssueRepository;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Browser\Test\HasBrowser;

abstract class AbstractIntegrationTestCase extends WebTestCase
{
    use HasBrowser;

    protected EntityManagerInterface $entityManager;
    protected UserRepository $userRepository;
    protected ProjectRepository $projectRepository;
    protected ApiKeyRepository $apiKeyRepository;
    protected IssueRepository $issueRepository;
    protected OrganizationRepository $organizationRepository;
    protected OrganizationMembershipRepository $organizationMembershipRepository;

    protected function setUp(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $em;

        /** @var UserRepository $userRepository */
        $userRepository = static::getContainer()->get(UserRepository::class);
        $this->userRepository = $userRepository;

        /** @var ProjectRepository $projectRepository */
        $projectRepository = static::getContainer()->get(ProjectRepository::class);
        $this->projectRepository = $projectRepository;

        /** @var ApiKeyRepository $apiKeyRepository */
        $apiKeyRepository = static::getContainer()->get(ApiKeyRepository::class);
        $this->apiKeyRepository = $apiKeyRepository;

        /** @var IssueRepository $issueRepository */
        $issueRepository = static::getContainer()->get(IssueRepository::class);
        $this->issueRepository = $issueRepository;

        /** @var OrganizationRepository $organizationRepository */
        $organizationRepository = static::getContainer()->get(OrganizationRepository::class);
        $this->organizationRepository = $organizationRepository;

        /** @var OrganizationMembershipRepository $organizationMembershipRepository */
        $organizationMembershipRepository = static::getContainer()->get(OrganizationMembershipRepository::class);
        $this->organizationMembershipRepository = $organizationMembershipRepository;

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function resetDatabase(): void
    {
        $connection = $this->entityManager->getConnection();

        // Disable foreign key checks for SQLite
        $connection->executeStatement('PRAGMA foreign_keys = OFF');

        // Clear tables in order that respects foreign keys
        $connection->executeStatement('DELETE FROM api_keys');
        $connection->executeStatement('DELETE FROM issues');
        $connection->executeStatement('DELETE FROM projects');
        $connection->executeStatement('DELETE FROM organization_memberships');
        $connection->executeStatement('DELETE FROM organizations');
        $connection->executeStatement('DELETE FROM users');

        // Re-enable foreign key checks
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->entityManager->clear();
    }

    protected function createTestUser(
        string $email = 'test@example.com',
        string $password = 'password123',
        ?string $name = 'Test User',
    ): User {
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $this->userRepository->save($user, true);

        // Create a personal organization for the user
        $organization = $this->createTestOrganization($name ?? $email);

        // Create membership with owner role
        $membership = new OrganizationMembership();
        $membership->setUser($user);
        $membership->setOrganization($organization);
        $membership->setRole(OrganizationMembership::ROLE_OWNER);
        $this->organizationMembershipRepository->save($membership, true);

        // Refresh user to get the membership relationship
        $this->entityManager->refresh($user);

        return $user;
    }

    protected function createTestOrganization(
        string $name = 'Test Organization',
        ?string $slug = null,
    ): Organization {
        $organization = new Organization();
        $organization->setName($name);
        // Generate unique slug by adding a random suffix
        $baseSlug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $organization->setSlug($slug ?? $baseSlug.'-'.bin2hex(random_bytes(4)));

        $this->organizationRepository->save($organization, true);

        return $organization;
    }

    protected function createTestProject(
        User $owner,
        string $name = 'Test Project',
        ?Organization $organization = null,
    ): Project {
        $project = new Project();
        $project->setName($name);
        $project->setOwner($owner);

        // Use provided organization or get user's default organization
        $org = $organization ?? $owner->getDefaultOrganization();
        if (null === $org) {
            throw new \RuntimeException('User must have an organization to create a project');
        }
        $project->setOrganization($org);

        $this->projectRepository->save($project, true);

        return $project;
    }

    /**
     * Create a test API key and return both the entity and plain key.
     *
     * @param array<string> $scopes
     *
     * @return array{apiKey: ApiKey, plainKey: string}
     */
    protected function createTestApiKey(
        Project $project,
        array $scopes = [ApiKey::SCOPE_INGEST],
        string $environment = ApiKey::ENV_DEVELOPMENT,
        bool $active = true,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $keyData = ApiKey::generateKey();

        $apiKey = new ApiKey();
        $apiKey->setKeyHash($keyData['hash']);
        $apiKey->setKeyPrefix($keyData['prefix']);
        $apiKey->setProject($project);
        $apiKey->setScopes($scopes);
        $apiKey->setEnvironment($environment);
        $apiKey->setIsActive($active);
        $apiKey->setLabel('Test Key');

        if (null !== $expiresAt) {
            $apiKey->setExpiresAt($expiresAt);
        }

        $this->apiKeyRepository->save($apiKey, true);

        return [
            'apiKey' => $apiKey,
            'plainKey' => $keyData['plain'],
        ];
    }

    protected function createTestIssue(
        Project $project,
        string $type = Issue::TYPE_ERROR,
        string $status = Issue::STATUS_OPEN,
        ?string $fingerprint = null,
    ): Issue {
        $issue = new Issue();
        $issue->setProject($project);
        $issue->setType($type);
        $issue->setStatus($status);
        $issue->setFingerprint($fingerprint ?? hash('sha256', uniqid('test', true)));
        $issue->setTitle('Test Issue');

        $this->issueRepository->save($issue, true);

        return $issue;
    }

    /**
     * Create a valid event payload for testing.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function createValidEventPayload(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'error',
            'message' => 'Test error message',
            'severity' => 'error',
            'app_version' => '1.0.0',
            'app_build' => '100',
            'os_name' => 'iOS',
            'os_version' => '17.0',
            'device_model' => 'iPhone 15',
        ], $overrides);
    }
}
