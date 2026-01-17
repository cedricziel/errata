<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Issue;
use App\Tests\Integration\AbstractIntegrationTestCase;

class IssueControllerTest extends AbstractIntegrationTestCase
{
    public function testIssueIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/issues');

        $this->assertResponseRedirects('/login');
    }

    public function testIssueIndexShowsUserIssues(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue1 = $this->createTestIssue($project, Issue::TYPE_ERROR);
        $issue2 = $this->createTestIssue($project, Issue::TYPE_CRASH);

        $this->loginUser($user);

        $this->client->request('GET', '/issues');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Issues');
    }

    public function testFilterByStatusWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $openIssue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);
        $resolvedIssue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_RESOLVED);

        $this->loginUser($user);

        $this->client->request('GET', '/issues', ['status' => Issue::STATUS_OPEN]);

        $this->assertResponseIsSuccessful();
    }

    public function testFilterByTypeWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);

        $errorIssue = $this->createTestIssue($project, Issue::TYPE_ERROR);
        $crashIssue = $this->createTestIssue($project, Issue::TYPE_CRASH);

        $this->loginUser($user);

        $this->client->request('GET', '/issues', ['type' => Issue::TYPE_ERROR]);

        $this->assertResponseIsSuccessful();
    }

    public function testIssueDetailPageDisplaysCorrectly(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project);

        $this->loginUser($user);

        $this->client->request('GET', '/issues/' . $issue->getPublicId()->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Test Issue');
    }

    public function testCannotViewOtherUsersIssuesReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $issue = $this->createTestIssue($project1);

        // Login as user2
        $this->loginUser($user2);

        // Try to access user1's issue
        $this->client->request('GET', '/issues/' . $issue->getPublicId()->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testNonExistentIssueReturns404(): void
    {
        $user = $this->createTestUser();
        $this->loginUser($user);

        $this->client->request('GET', '/issues/00000000-0000-0000-0000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateStatusToResolvedWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);

        $this->loginUser($user);

        $this->client->request(
            'POST',
            '/issues/' . $issue->getPublicId()->toRfc4122() . '/status',
            ['status' => Issue::STATUS_RESOLVED]
        );

        $this->assertResponseRedirects('/issues/' . $issue->getPublicId()->toRfc4122());

        // Verify issue was updated
        $this->entityManager->refresh($issue);
        $this->assertSame(Issue::STATUS_RESOLVED, $issue->getStatus());
    }

    public function testUpdateStatusToIgnoredWorks(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issue = $this->createTestIssue($project, Issue::TYPE_ERROR, Issue::STATUS_OPEN);

        $this->loginUser($user);

        $this->client->request(
            'POST',
            '/issues/' . $issue->getPublicId()->toRfc4122() . '/status',
            ['status' => Issue::STATUS_IGNORED]
        );

        $this->assertResponseRedirects('/issues/' . $issue->getPublicId()->toRfc4122());

        // Verify issue was updated
        $this->entityManager->refresh($issue);
        $this->assertSame(Issue::STATUS_IGNORED, $issue->getStatus());
    }

    public function testCannotUpdateOtherUsersIssueStatusReturns403(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');

        $project1 = $this->createTestProject($user1);
        $issue = $this->createTestIssue($project1, Issue::TYPE_ERROR, Issue::STATUS_OPEN);

        // Login as user2
        $this->loginUser($user2);

        // Try to update user1's issue
        $this->client->request(
            'POST',
            '/issues/' . $issue->getPublicId()->toRfc4122() . '/status',
            ['status' => Issue::STATUS_RESOLVED]
        );

        $this->assertResponseStatusCodeSame(403);

        // Verify issue was NOT updated
        $this->entityManager->refresh($issue);
        $this->assertSame(Issue::STATUS_OPEN, $issue->getStatus());
    }
}
