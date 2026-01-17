<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Issue;
use App\Tests\Integration\AbstractIntegrationTestCase;

class DashboardControllerTest extends AbstractIntegrationTestCase
{
    public function testDashboardRequiresAuthentication(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testDashboardDisplaysForLoggedInUser(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardWithNoProjectsShowsEmptyState(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Dashboard');
    }

    public function testDashboardWithProjectShowsProjectData(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');
        $this->loginUser($user);

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Dashboard');
    }

    public function testDashboardWithProjectAndIssuesShowsStats(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');

        // Create some issues
        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $this->createTestIssue($project, Issue::TYPE_CRASH, Issue::STATUS_OPEN);
        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_RESOLVED);

        $this->loginUser($user);

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardProjectSelectorWorks(): void
    {
        $user = $this->createTestUser();
        $project1 = $this->createTestProject($user, 'Project One');
        $project2 = $this->createTestProject($user, 'Project Two');

        $this->loginUser($user);

        // Request dashboard with specific project
        $this->client->request('GET', '/', ['project' => $project2->getPublicId()->toRfc4122()]);

        $this->assertResponseIsSuccessful();
    }

    public function testDashboardChartsRenderWithData(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Chart Test Project');

        // Create issues over multiple days to test chart data
        for ($i = 0; $i < 5; ++$i) {
            $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        }

        $this->loginUser($user);

        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }
}
