<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Message\ComputeFacetBatch;
use App\MessageHandler\ComputeFacetBatchHandler;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Symfony\Component\Uid\Uuid;

class ComputeFacetBatchHandlerTest extends AbstractIntegrationTestCase
{
    private ComputeFacetBatchHandler $handler;
    private AsyncQueryResultStore $resultStore;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ComputeFacetBatchHandler $handler */
        $handler = static::getContainer()->get(ComputeFacetBatchHandler::class);
        $this->handler = $handler;

        /** @var AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(AsyncQueryResultStore::class);
        $this->resultStore = $resultStore;
    }

    public function testHandlerComputesFacetsAndStoresResult(): void
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

        // Initialize query and facet batches
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );
        $this->resultStore->storeResult($queryId, ['events' => [], 'total' => 0, 'facets' => []]);
        $this->resultStore->initializeFacetBatches($queryId, ['device', 'app', 'trace', 'user']);

        $message = new ComputeFacetBatch(
            queryId: $queryId,
            batchId: 'device',
            attributes: ['device_model', 'os_name', 'os_version'],
            queryContext: array_merge($queryRequest, ['organizationId' => $organizationId]),
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify the batch was marked as completed
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertSame('completed', $state['facetBatches']['device']['status']);
        $this->assertIsArray($state['facetBatches']['device']['facets']);
    }

    public function testHandlerSkipsWhenQueryIsCancelled(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        // Initialize query and request cancellation BEFORE storing result
        // (cancellation only works on non-terminal states)
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );
        $this->resultStore->requestCancellation($queryId);

        // Now store result and initialize batches (simulating the scenario where
        // cancellation was requested during main query execution)
        $this->resultStore->storeResult($queryId, ['events' => [], 'total' => 0, 'facets' => []]);
        $this->resultStore->initializeFacetBatches($queryId, ['device']);

        $message = new ComputeFacetBatch(
            queryId: $queryId,
            batchId: 'device',
            attributes: ['device_model', 'os_name', 'os_version'],
            queryContext: array_merge($queryRequest, ['organizationId' => $organizationId]),
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify the batch is still pending (not processed due to cancellation)
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertSame('pending', $state['facetBatches']['device']['status']);
    }

    public function testHandlerAppendsToMainResultFacets(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
            'page' => 1,
            'limit' => 50,
            'projectId' => $project->getPublicId()->toRfc4122(),
        ];

        // Initialize query with some priority facets
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest,
            (string) $user->getId(),
            $organizationId,
        );
        $this->resultStore->storeResult($queryId, [
            'events' => [],
            'total' => 0,
            'facets' => [
                ['attribute' => 'event_type', 'label' => 'Event Type', 'values' => []],
            ],
        ]);
        $this->resultStore->initializeFacetBatches($queryId, ['device']);

        $message = new ComputeFacetBatch(
            queryId: $queryId,
            batchId: 'device',
            attributes: ['device_model', 'os_name', 'os_version'],
            queryContext: array_merge($queryRequest, ['organizationId' => $organizationId]),
        );

        // Execute the handler
        $this->handler->__invoke($message);

        // Verify facets were appended to main result
        $state = $this->resultStore->getQueryState($queryId);
        $this->assertGreaterThan(1, count($state['result']['facets']));
    }

    public function testMultipleBatchHandlersCanRunConcurrently(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user);
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        $queryId = Uuid::v7()->toRfc4122();
        $queryRequest = [
            'filters' => [],
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
        $this->resultStore->storeResult($queryId, ['events' => [], 'total' => 0, 'facets' => []]);
        $this->resultStore->initializeFacetBatches($queryId, ['device', 'app', 'trace', 'user']);

        $batches = [
            ['batchId' => 'device', 'attributes' => ['device_model', 'os_name', 'os_version']],
            ['batchId' => 'app', 'attributes' => ['app_version', 'app_build']],
            ['batchId' => 'trace', 'attributes' => ['operation', 'span_status']],
            ['batchId' => 'user', 'attributes' => ['user_id', 'locale']],
        ];

        // Execute all batch handlers
        foreach ($batches as $batch) {
            $message = new ComputeFacetBatch(
                queryId: $queryId,
                batchId: $batch['batchId'],
                attributes: $batch['attributes'],
                queryContext: array_merge($queryRequest, ['organizationId' => $organizationId]),
            );
            $this->handler->__invoke($message);
        }

        // Verify all batches completed
        $state = $this->resultStore->getQueryState($queryId);
        foreach ($batches as $batch) {
            $this->assertSame('completed', $state['facetBatches'][$batch['batchId']]['status']);
        }

        // Verify all facet batches are complete
        $this->assertTrue($this->resultStore->areFacetBatchesComplete($queryId));
    }
}
