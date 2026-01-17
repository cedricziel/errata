<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Issue;
use App\Tests\Integration\AbstractIntegrationTestCase;

class IssueControllerTest extends AbstractIntegrationTestCase
{
    public function testIssueIndexRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/issues')
            ->assertRedirectedTo('/login');
    }

    public function testIssueIndexShowsUserIssues(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $this->createTestIssue($project, Issue::TYPE_ERROR);
        $this->createTestIssue($project, Issue::TYPE_CRASH);

        $this->browser()
            ->actingAs($user)
            ->visit('/issues')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Issues');
    }

    public function testFilterByStatusWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_RESOLVED);

        $this->browser()
            ->actingAs($user)
            ->visit('/issues?status='.Issue::STATUS_OPEN)
            ->assertSuccessful();
    }

    public function testFilterByTypeWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $this->createTestIssue($project, Issue::TYPE_ERROR);
        $this->createTestIssue($project, Issue::TYPE_CRASH);

        $this->browser()
            ->actingAs($user)
            ->visit('/issues?type='.Issue::TYPE_ERROR)
            ->assertSuccessful();
    }

    public function testIssueDetailPageDisplaysCorrectly(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project);

        $this->browser()
            ->actingAs($user)
            ->visit('/issues/'.$issue->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertSeeIn('body', 'Test Issue');
    }

    public function testCannotViewOtherUsersIssuesReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $issue = $this->createTestIssue($project1);

        // Login as user2
        $this->browser()
            ->actingAs($user2)
            ->visit('/issues/'.$issue->getPublicId()->toRfc4122())
            ->assertStatus(403);
    }

    public function testNonExistentIssueReturns404(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/issues/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function testUpdateStatusToResolvedWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $issueId = $issue->getId();
        $publicId = $issue->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/issues/'.$publicId.'/status', [
                'body' => ['status' => Issue::STATUS_RESOLVED],
            ])
            ->assertRedirectedTo('/issues/'.$publicId);

        // Re-fetch issue from database after browser request
        $issue = $this->issueRepository->find($issueId);
        $this->assertSame(Issue::STATUS_RESOLVED, $issue->getStatus());
    }

    public function testUpdateStatusToIgnoredWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $issueId = $issue->getId();
        $publicId = $issue->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/issues/'.$publicId.'/status', [
                'body' => ['status' => Issue::STATUS_IGNORED],
            ])
            ->assertRedirectedTo('/issues/'.$publicId);

        // Re-fetch issue from database after browser request
        $issue = $this->issueRepository->find($issueId);
        $this->assertSame(Issue::STATUS_IGNORED, $issue->getStatus());
    }

    public function testCannotUpdateOtherUsersIssueStatusReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $issue = $this->createTestIssue($project1, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $issueId = $issue->getId();
        $publicId = $issue->getPublicId()->toRfc4122();

        // Login as user2
        $this->browser()
            ->actingAs($user2)
            ->post('/issues/'.$publicId.'/status', [
                'body' => ['status' => Issue::STATUS_RESOLVED],
            ])
            ->assertStatus(403);

        // Re-fetch issue from database after browser request
        $issue = $this->issueRepository->find($issueId);
        $this->assertSame(Issue::STATUS_OPEN, $issue->getStatus());
    }
}
