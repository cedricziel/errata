<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\ApiKey;
use App\Tests\Integration\AbstractIntegrationTestCase;

class ProjectControllerTest extends AbstractIntegrationTestCase
{
    public function testProjectIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/projects');

        $this->assertResponseRedirects('/login');
    }

    public function testProjectIndexShowsUserProjects(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'My Test Project');

        $this->loginUser($user);

        $this->client->request('GET', '/projects');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'My Test Project');
    }

    public function testNewProjectFormDisplays(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('GET', '/projects/new');

        $this->assertResponseIsSuccessful();
    }

    public function testCreatingProjectWorkAndSetsOwner(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('POST', '/projects/new', [
            'name' => 'New Project',
            'bundle_identifier' => 'com.example.newproject',
            'platform' => 'ios',
        ]);

        // Should redirect after successful creation
        $this->assertResponseRedirects();

        // Verify project was created
        $projects = $this->projectRepository->findByOwner($user);
        $this->assertCount(1, $projects);
        $this->assertSame('New Project', $projects[0]->getName());
        $this->assertSame($user, $projects[0]->getOwner());
    }

    public function testNewProjectCreatesDefaultApiKeyWithIngestScope(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('POST', '/projects/new', [
            'name' => 'Project With API Key',
            'bundle_identifier' => 'com.example.apikey',
        ]);

        $this->assertResponseRedirects();

        // Verify project was created
        $projects = $this->projectRepository->findByOwner($user);
        $this->assertCount(1, $projects);

        // Verify API key was created with SCOPE_INGEST
        $apiKeys = $this->apiKeyRepository->findByProject($projects[0]);
        $this->assertCount(1, $apiKeys);
        $this->assertTrue($apiKeys[0]->hasScope(ApiKey::SCOPE_INGEST));
    }

    public function testProjectNameIsRequired(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('POST', '/projects/new', [
            'name' => '',
        ]);

        // Should redirect back to form with error
        $this->assertResponseRedirects('/projects/new');

        // Verify no project was created
        $projects = $this->projectRepository->findByOwner($user);
        $this->assertCount(0, $projects);
    }

    public function testProjectDetailPageDisplays(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Detail Test Project');

        $this->loginUser($user);

        $this->client->request('GET', '/projects/' . $project->getPublicId()->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Detail Test Project');
    }

    public function testCannotViewOtherUsersProjectReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project = $this->createTestProject($user1, 'User1 Project');

        // Login as user2
        $this->loginUser($user2);

        $this->client->request('GET', '/projects/' . $project->getPublicId()->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEditProjectUpdatesFields(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Original Name');

        $this->loginUser($user);

        $this->client->request('POST', '/projects/' . $project->getPublicId()->toRfc4122() . '/edit', [
            'name' => 'Updated Name',
            'bundle_identifier' => 'com.example.updated',
            'platform' => 'android',
        ]);

        $this->assertResponseRedirects('/projects/' . $project->getPublicId()->toRfc4122());

        // Verify project was updated
        $this->entityManager->refresh($project);
        $this->assertSame('Updated Name', $project->getName());
        $this->assertSame('com.example.updated', $project->getBundleIdentifier());
        $this->assertSame('android', $project->getPlatform());
    }

    public function testCreateApiKeyWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/' . $project->getPublicId()->toRfc4122() . '/keys/new',
            [
                'label' => 'Production Key',
                'environment' => ApiKey::ENV_PRODUCTION,
            ]
        );

        $this->assertResponseRedirects('/projects/' . $project->getPublicId()->toRfc4122());

        // Verify API key was created
        $apiKeys = $this->apiKeyRepository->findByProject($project);
        $this->assertCount(1, $apiKeys);
        $this->assertSame('Production Key', $apiKeys[0]->getLabel());
        $this->assertSame(ApiKey::ENV_PRODUCTION, $apiKeys[0]->getEnvironment());
    }

    public function testRevokeApiKeyDeactivatesIt(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $keyData = $this->createTestApiKey($project);

        $this->assertTrue($keyData['apiKey']->isActive());

        $this->loginUser($user);

        $this->client->request(
            'POST',
            '/projects/' . $project->getPublicId()->toRfc4122() . '/keys/' . $keyData['apiKey']->getId() . '/revoke'
        );

        $this->assertResponseRedirects('/projects/' . $project->getPublicId()->toRfc4122());

        // Verify API key was deactivated
        $this->entityManager->refresh($keyData['apiKey']);
        $this->assertFalse($keyData['apiKey']->isActive());
    }

    public function testCannotRevokeOtherUsersApiKeyReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project = $this->createTestProject($user1);
        $keyData = $this->createTestApiKey($project);

        // Login as user2
        $this->loginUser($user2);

        $this->client->request(
            'POST',
            '/projects/' . $project->getPublicId()->toRfc4122() . '/keys/' . $keyData['apiKey']->getId() . '/revoke'
        );

        $this->assertResponseStatusCodeSame(403);

        // Verify API key was NOT deactivated
        $this->entityManager->refresh($keyData['apiKey']);
        $this->assertTrue($keyData['apiKey']->isActive());
    }

    public function testNonExistentProjectReturns404(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('GET', '/projects/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }
}
