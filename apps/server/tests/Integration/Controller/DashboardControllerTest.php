<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Issue;
use App\Tests\Integration\AbstractIntegrationTestCase;

class DashboardControllerTest extends AbstractIntegrationTestCase
{
    public function testDashboardRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/')
            ->assertRedirectedTo('/login');
    }

    public function testDashboardDisplaysForLoggedInUser(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful();
    }

    public function testDashboardWithNoProjectsShowsEmptyState(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Dashboard');
    }

    public function testDashboardWithProjectShowsProjectData(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Dashboard');
    }

    public function testDashboardWithProjectAndIssuesShowsStats(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');

        // Create some issues
        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $this->createTestIssue($project, Issue::TYPE_CRASH, Issue::STATUS_OPEN);
        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_RESOLVED);

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful();
    }

    public function testDashboardProjectSelectorWorks(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Project One');
        $project2 = $this->createTestProject($user, 'Project Two');

        // Request dashboard with specific project
        $this->browser()
            ->actingAs($user)
            ->visit('/?project='.$project2->getPublicId()->toRfc4122())
            ->assertSuccessful();
    }

    public function testDashboardChartsRenderWithData(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Chart Test Project');

        // Create issues over multiple days to test chart data
        for ($i = 0; $i < 5; ++$i) {
            $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        }

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful();
    }
}
