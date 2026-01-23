<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Enum\QueryStatus;
use App\Message\ComputeFacetBatch;
use App\Message\ExecuteQuery;
use App\MessageHandler\ExecuteQueryHandler;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class ExecuteQueryHandlerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

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

    public function testHandlerDispatchesDeferredFacetBatches(): void
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

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify that facet batch messages were dispatched (4 batches)
        $this->transport('async_query')
            ->queue()
            ->assertContains(ComputeFacetBatch::class, 4);
    }

    public function testHandlerInitializesFacetBatchTracking(): void
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

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify facet batches were initialized
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertArrayHasKey('facetBatches', $state);
        $this->assertArrayHasKey('device', $state['facetBatches']);
        $this->assertArrayHasKey('app', $state['facetBatches']);
        $this->assertArrayHasKey('trace', $state['facetBatches']);
        $this->assertArrayHasKey('user', $state['facetBatches']);
    }

    public function testHandlerReturnsPriorityFacetsOnly(): void
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

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify result contains priority facets
        $state = $this->resultStore->getQueryState($queryId);
        $facetAttributes = array_column($state['result']['facets'], 'attribute');

        // Priority facets should be present
        $this->assertContains('event_type', $facetAttributes);
        $this->assertContains('severity', $facetAttributes);
        $this->assertContains('environment', $facetAttributes);
        $this->assertContains('exception_type', $facetAttributes);
    }

    public function testFullFlowWithFacetBatchProcessing(): void
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

        $message = new ExecuteQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );

        // Execute the main query handler
        $this->handler->__invoke($message);

        // Verify query completed with priority facets
        $this->assertSame(QueryStatus::COMPLETED, $this->resultStore->getStatus($queryId));

        // Facet batches should be pending
        $pending = $this->resultStore->getPendingFacetBatches($queryId);
        $this->assertCount(4, $pending);

        // Process all facet batch messages
        $this->transport('async_query')->process(4);

        // Now all facet batches should be complete
        $this->assertTrue($this->resultStore->areFacetBatchesComplete($queryId));

        // Verify all facet batches completed successfully
        $state = $this->resultStore->getQueryState($queryId);
        foreach (['device', 'app', 'trace', 'user'] as $batchId) {
            $this->assertSame('completed', $state['facetBatches'][$batchId]['status']);
        }
    }
}
