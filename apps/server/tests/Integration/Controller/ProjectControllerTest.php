<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\ApiKey;
use App\Tests\Integration\AbstractIntegrationTestCase;

class ProjectControllerTest extends AbstractIntegrationTestCase
{
    public function testProjectIndexRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/projects')
            ->assertRedirectedTo('/login');
    }

    public function testProjectIndexShowsUserProjects(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'My Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects')
            ->assertSuccessful()
            ->assertSeeIn('body', 'My Test Project');
    }

    public function testNewProjectFormDisplays(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/new')
            ->assertSuccessful();
    }

    public function testCreatingProjectWorkAndSetsOwner(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/new', [
                'body' => [
                    'name' => 'New Project',
                    'bundle_identifier' => 'com.example.newproject',
                    'platform' => 'ios',
                ],
            ])
            ->assertRedirected();

        // Re-fetch user from database after browser request
        $user = $this->userRepository->find($userId);
        $projects = $this->projectRepository->findByOwner($user);
        $this->assertCount(1, $projects);
        $this->assertSame('New Project', $projects[0]->getName());
    }

    public function testNewProjectCreatesDefaultApiKeyWithIngestScope(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/new', [
                'body' => [
                    'name' => 'Project With API Key',
                    'bundle_identifier' => 'com.example.apikey',
                ],
            ])
            ->assertRedirected();

        // Re-fetch user from database after browser request
        $user = $this->userRepository->find($userId);
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
        $userId = $user->getId();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/new', [
                'body' => [
                    'name' => '',
                ],
            ])
            ->assertRedirectedTo('/projects/new');

        // Re-fetch user from database after browser request
        $user = $this->userRepository->find($userId);
        $projects = $this->projectRepository->findByOwner($user);
        $this->assertCount(0, $projects);
    }

    public function testProjectDetailPageDisplays(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Detail Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertSeeIn('body', 'Detail Test Project');
    }

    public function testCannotViewOtherOrganizationsProjectReturns404(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project = $this->createTestProject($user1, 'User1 Project');

        // Login as user2 (different organization) - should not find the project
        $this->browser()
            ->actingAs($user2)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122())
            ->assertStatus(404);
    }

    public function testEditProjectUpdatesFields(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Original Name');
        $projectId = $project->getId();
        $publicId = $project->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/'.$publicId.'/edit', [
                'body' => [
                    'name' => 'Updated Name',
                    'bundle_identifier' => 'com.example.updated',
                    'platform' => 'android',
                ],
            ])
            ->assertRedirectedTo('/projects/'.$publicId);

        // Re-fetch project from database after browser request
        $project = $this->projectRepository->find($projectId);
        $this->assertSame('Updated Name', $project->getName());
        $this->assertSame('com.example.updated', $project->getBundleIdentifier());
        $this->assertSame('android', $project->getPlatform());
    }

    public function testCreateApiKeyWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getId();
        $publicId = $project->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/'.$publicId.'/keys/new', [
                'body' => [
                    'label' => 'Production Key',
                    'environment' => ApiKey::ENV_PRODUCTION,
                ],
            ])
            ->assertRedirectedTo('/projects/'.$publicId);

        // Re-fetch project from database after browser request
        $project = $this->projectRepository->find($projectId);
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
        $apiKeyId = $keyData['apiKey']->getId();
        $publicId = $project->getPublicId()->toRfc4122();

        $this->assertTrue($keyData['apiKey']->isActive());

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/projects/'.$publicId.'/keys/'.$apiKeyId.'/revoke')
            ->assertRedirectedTo('/projects/'.$publicId);

        // Re-fetch API key from database after browser request
        $apiKey = $this->apiKeyRepository->find($apiKeyId);
        $this->assertFalse($apiKey->isActive());
    }

    public function testCannotRevokeOtherOrganizationsApiKeyReturns404(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project = $this->createTestProject($user1);
        $keyData = $this->createTestApiKey($project);
        $apiKeyId = $keyData['apiKey']->getId();
        $publicId = $project->getPublicId()->toRfc4122();

        // Login as user2 (different organization) - should not find the project
        $this->browser()
            ->actingAs($user2)
            ->post('/projects/'.$publicId.'/keys/'.$apiKeyId.'/revoke')
            ->assertStatus(404);

        // Re-fetch API key from database after browser request
        $apiKey = $this->apiKeyRepository->find($apiKeyId);
        $this->assertTrue($apiKey->isActive());
    }

    public function testNonExistentProjectReturns404(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function testOpenTelemetrySettingsPageLoads(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->actingAs($user)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/settings/opentelemetry')
            ->assertSuccessful()
            ->assertSeeIn('body', 'OpenTelemetry Integration')
            ->assertSeeIn('body', '/v1/traces')
            ->assertSeeIn('body', '/v1/metrics')
            ->assertSeeIn('body', '/v1/logs');
    }

    public function testOpenTelemetrySettingsRequiresAuth(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->browser()
            ->interceptRedirects()
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/settings/opentelemetry')
            ->assertRedirectedTo('/login');
    }

    public function testCannotViewOtherOrganizationsOtelSettingsReturns404(): void
    {
        $user1 = $this->createTestUser('owner@example.com');
        $user2 = $this->createTestUser('other@example.com');
        $project = $this->createTestProject($user1);

        // Login as user2 (different organization) - should not find the project
        $this->browser()
            ->actingAs($user2)
            ->visit('/projects/'.$project->getPublicId()->toRfc4122().'/settings/opentelemetry')
            ->assertStatus(404);
    }
}
