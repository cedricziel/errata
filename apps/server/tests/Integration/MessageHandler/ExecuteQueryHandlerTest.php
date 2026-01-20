<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Enum\QueryStatus;
use App\Message\ExecuteQuery;
use App\MessageHandler\ExecuteQueryHandler;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Symfony\Component\Uid\Uuid;

class ExecuteQueryHandlerTest extends AbstractIntegrationTestCase
{
    private ExecuteQueryHandler $handler;
    private AsyncQueryResultStore $resultStore;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ExecuteQueryHandler $handler */
        $handler = static::getContainer()->get(ExecuteQueryHandler::class);
        $this->handler = $handler;

        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $this->resultStore = $resultStore;
    }

    public function testHandlerExecutesQueryAndStoresResult(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        // Initialize the query in the store first
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify the query completed successfully
        $status = $this->resultStore->getStatus($queryId);
        $this->assertSame(QueryStatus::COMPLETED, $status);

        $state = $this->resultStore->getQueryState($queryId);
        $this->assertNotNull($state['result']);
        $this->assertArrayHasKey('events', $state['result']);
        $this->assertArrayHasKey('total', $state['result']);
        $this->assertArrayHasKey('facets', $state['result']);
        $this->assertSame(100, $state['progress']);
    }

    public function testHandlerRespectsPreCancellation(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        // Initialize the query and immediately request cancellation
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );
        $this->resultStore->requestCancellation($queryId);

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify the query was cancelled
        $status = $this->resultStore->getStatus($queryId);
        $this->assertSame(QueryStatus::CANCELLED, $status);

        // Result should be null since it was cancelled
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertNull($state['result']);
    }

    public function testHandlerStoresProgressDuringExecution(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Verify initial state is pending
        $this->assertSame(QueryStatus::PENDING, $this->resultStore->getStatus($queryId));

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        $this->handler->__invoke($message);

        // After completion, progress should be 100
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertSame(100, $state['progress']);
        $this->assertSame(QueryStatus::COMPLETED->value, $state['status']);
    }

    public function testHandlerHandlesEmptyResults(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        // Query for a non-existent event type to get empty results
        $queryRequest = [
            'filters' => [
                ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'nonexistent_type_xyz'],
            ],
            'groupBy' => null,
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        $this->handler->__invoke($message);

        $state = $this->resultStore->getQueryState($queryId);
        $this->assertSame(QueryStatus::COMPLETED->value, $state['status']);
        $this->assertSame(0, $state['result']['total']);
        $this->assertEmpty($state['result']['events']);
    }

    public function testHandlerHandlesMultipleConcurrentQueries(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryIds = [];
        $messages = [];

        // Create multiple queries
        for ($i = 0; $i < 3; ++$i) {
            $queryId = Uuid::v7()->toRfc4122();
            $queryIds[] = $queryId;

            $queryRequest = [
                'filters' => [],
                'groupBy' => null,
                'page' => 1,
                'limit' => 10 + $i * 10,
                'projectId' => $project->getPublicId()->toRfc4122(),
            ];

            $this->resultStore->initializeQuery(
                $queryId,
                $queryRequest,
                (string) $user->getId(),
                $organizationId,
            );

            $messages[] = new ExecuteQuery(
                $queryId,
                $queryRequest,
                (string) $user->getId(),
                $organizationId,
            );
        }

        // Execute all handlers
        foreach ($messages as $message) {
            $this->handler->__invoke($message);
        }

        // All queries should complete successfully
        foreach ($queryIds as $queryId) {
            $status = $this->resultStore->getStatus($queryId);
            $this->assertSame(QueryStatus::COMPLETED, $status);
        }
    }
}
