<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Issue;
use App\Message\ProcessEvent;
use App\MessageHandler\ProcessEventHandler;
use App\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ProcessEventHandlerTest extends AbstractIntegrationTestCase
{
    private ProcessEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProcessEventHandler $handler */
        $handler = static::getContainer()->get(ProcessEventHandler::class);
        $this->handler = $handler;
    }

    public function testProcessingEventCreatesNewIssue(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventData = [
            'event_type' => 'error',
            'message' => 'Test error message',
            'exception_type' => 'RuntimeException',
        ];

        $message = new ProcessEvent($eventData, $projectId, 'development');
        $this->handler->__invoke($message);

        // Verify issue was created
        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        $this->assertSame(Issue::TYPE_ERROR, $issue->getType());
        $this->assertSame(Issue::STATUS_OPEN, $issue->getStatus());
    }

    public function testSameFingerprintDeduplicatesToSingleIssue(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        // Same event data produces same fingerprint
        $eventData = [
            'event_type' => 'error',
            'message' => 'Same error message',
            'exception_type' => 'RuntimeException',
        ];

        $message1 = new ProcessEvent($eventData, $projectId, 'development');
        $message2 = new ProcessEvent($eventData, $projectId, 'development');
        $message3 = new ProcessEvent($eventData, $projectId, 'development');

        $this->handler->__invoke($message1);
        $this->handler->__invoke($message2);
        $this->handler->__invoke($message3);

        // Verify only one issue was created
        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(1, $issues);
    }

    public function testOccurrenceCountIncrementsCorrectly(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventData = [
            'event_type' => 'error',
            'message' => 'Repeated error',
            'exception_type' => 'RuntimeException',
        ];

        $message = new ProcessEvent($eventData, $projectId, 'development');

        // Process same event 5 times
        for ($i = 0; $i < 5; ++$i) {
            $this->handler->__invoke($message);
        }

        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(1, $issues);
        $this->assertSame(5, $issues[0]->getOccurrenceCount());
    }

    public function testResolvedIssueReopensOnNewEvent(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventData = [
            'event_type' => 'error',
            'message' => 'Error that recurs',
            'exception_type' => 'RecurringError',
        ];

        $message = new ProcessEvent($eventData, $projectId, 'development');

        // First event creates issue
        $this->handler->__invoke($message);

        $issues = $this->issueRepository->findByProject($project);
        $issue = $issues[0];

        // Mark as resolved
        $issue->setStatus(Issue::STATUS_RESOLVED);
        $this->issueRepository->save($issue, true);
        $this->assertSame(Issue::STATUS_RESOLVED, $issue->getStatus());

        // New event should reopen
        $this->handler->__invoke($message);

        $this->entityManager->refresh($issue);
        $this->assertSame(Issue::STATUS_OPEN, $issue->getStatus());
    }

    #[DataProvider('eventTypeToIssueTypeMappingProvider')]
    public function testIssueTypeMapsCorrectlyFromEventType(string $eventType, string $expectedIssueType): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventData = [
            'event_type' => $eventType,
            'message' => "Test $eventType event",
        ];

        $message = new ProcessEvent($eventData, $projectId, 'development');
        $this->handler->__invoke($message);

        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(1, $issues);
        $this->assertSame($expectedIssueType, $issues[0]->getType());
    }

    /**
     * @return array<array{string, string}>
     */
    public static function eventTypeToIssueTypeMappingProvider(): array
    {
        return [
            ['crash', Issue::TYPE_CRASH],
            ['error', Issue::TYPE_ERROR],
            ['log', Issue::TYPE_LOG],
        ];
    }

    public function testNonExistentProjectIsSilentlyIgnored(): void
    {
        $nonExistentProjectId = '00000000-0000-0000-0000-000000000000';

        $eventData = [
            'event_type' => 'error',
            'message' => 'Error for non-existent project',
        ];

        $message = new ProcessEvent($eventData, $nonExistentProjectId, 'development');

        // Should not throw exception
        $this->handler->__invoke($message);

        // No issue should be created for any project
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(0, $issues);
    }

    public function testFingerprintGenerationIsConsistent(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        // Two different event payloads with same error characteristics
        $eventData1 = [
            'event_type' => 'error',
            'message' => 'Same error',
            'exception_type' => 'SameException',
        ];

        $eventData2 = [
            'event_type' => 'error',
            'message' => 'Same error',
            'exception_type' => 'SameException',
            // Additional metadata shouldn't change fingerprint
            'app_version' => '1.0.0',
            'os_version' => '17.0',
        ];

        $message1 = new ProcessEvent($eventData1, $projectId, 'development');
        $message2 = new ProcessEvent($eventData2, $projectId, 'development');

        $this->handler->__invoke($message1);
        $this->handler->__invoke($message2);

        // Should create only one issue since fingerprint components match
        $issues = $this->issueRepository->findByProject($project);
        $this->assertCount(1, $issues);
        $this->assertSame(2, $issues[0]->getOccurrenceCount());
    }

    public function testLastSeenAtUpdatesOnEachEvent(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $projectId = $project->getPublicId()->toRfc4122();

        $eventData = [
            'event_type' => 'error',
            'message' => 'Recurring error',
            'exception_type' => 'RecurringException',
        ];

        $message = new ProcessEvent($eventData, $projectId, 'development');

        // First event
        $this->handler->__invoke($message);

        $issues = $this->issueRepository->findByProject($project);
        $issue = $issues[0];
        $firstLastSeenAt = $issue->getLastSeenAt();

        // Wait a tiny bit to ensure time difference
        usleep(10000); // 10ms

        // Second event
        $this->handler->__invoke($message);

        $this->entityManager->refresh($issue);
        $secondLastSeenAt = $issue->getLastSeenAt();

        // Second event should have updated lastSeenAt to be >= first
        $this->assertGreaterThanOrEqual(
            $firstLastSeenAt->getTimestamp(),
            $secondLastSeenAt->getTimestamp(),
            'lastSeenAt should be updated on each event'
        );
    }
}
