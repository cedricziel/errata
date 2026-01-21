<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\QueryBuilder;

use App\Enum\QueryStatus;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class AsyncQueryResultStoreTest extends TestCase
{
    private ArrayAdapter $cache;
    private AsyncQueryResultStore $store;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->store = new AsyncQueryResultStore($this->cache);
    }

    public function testInitializeQueryCreatesEntryInPendingState(): void
    {
        $queryId = 'test-query-123';
        $queryRequest = ['filters' => [], 'groupBy' => null, 'page' => 1, 'limit' => 50];
        $userId = 'user-123';
        $organizationId = 'org-123';

        $this->store->initializeQuery($queryId, $queryRequest, $userId, $organizationId);

        $state = $this->store->getQueryState($queryId);

        $this->assertNotNull($state);
        $this->assertSame($queryId, $state['queryId']);
        $this->assertSame(QueryStatus::PENDING->value, $state['status']);
        $this->assertSame(0, $state['progress']);
        $this->assertSame($queryRequest, $state['queryRequest']);
        $this->assertSame($userId, $state['userId']);
        $this->assertSame($organizationId, $state['organizationId']);
        $this->assertNull($state['result']);
        $this->assertNull($state['error']);
        $this->assertFalse($state['cancelRequested']);
    }

    public function testMarkInProgressTransitionsFromPending(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $this->store->markInProgress($queryId, 10);

        $state = $this->store->getQueryState($queryId);
        $this->assertSame(QueryStatus::IN_PROGRESS->value, $state['status']);
        $this->assertSame(10, $state['progress']);
    }

    public function testUpdateProgressUpdatesProgressValue(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->markInProgress($queryId, 0);

        $this->store->updateProgress($queryId, 50);

        $state = $this->store->getQueryState($queryId);
        $this->assertSame(50, $state['progress']);
    }

    public function testUpdateProgressClampsBetweenZeroAndHundred(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $this->store->updateProgress($queryId, -10);
        $state = $this->store->getQueryState($queryId);
        $this->assertSame(0, $state['progress']);

        $this->store->updateProgress($queryId, 150);
        $state = $this->store->getQueryState($queryId);
        $this->assertSame(100, $state['progress']);
    }

    public function testStoreResultTransitionsToCompleted(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->markInProgress($queryId, 50);

        $result = ['events' => [['id' => 1]], 'total' => 1];
        $this->store->storeResult($queryId, $result);

        $state = $this->store->getQueryState($queryId);
        $this->assertSame(QueryStatus::COMPLETED->value, $state['status']);
        $this->assertSame(100, $state['progress']);
        $this->assertSame($result, $state['result']);
        $this->assertArrayHasKey('completedAt', $state);
    }

    public function testStoreErrorTransitionsToFailed(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->markInProgress($queryId, 30);

        $this->store->storeError($queryId, 'Something went wrong');

        $state = $this->store->getQueryState($queryId);
        $this->assertSame(QueryStatus::FAILED->value, $state['status']);
        $this->assertSame('Something went wrong', $state['error']);
        $this->assertArrayHasKey('completedAt', $state);
    }

    public function testRequestCancellationSetsCancellationFlag(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->markInProgress($queryId, 30);

        $result = $this->store->requestCancellation($queryId);

        $this->assertTrue($result);
        $this->assertTrue($this->store->isCancelled($queryId));
    }

    public function testRequestCancellationReturnsFalseForTerminalStates(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => []]);

        $result = $this->store->requestCancellation($queryId);

        $this->assertFalse($result);
    }

    public function testRequestCancellationReturnsFalseForNonexistentQuery(): void
    {
        $result = $this->store->requestCancellation('nonexistent-query');

        $this->assertFalse($result);
    }

    public function testMarkCancelledTransitionsToCancelledState(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->markInProgress($queryId, 50);
        $this->store->requestCancellation($queryId);

        $this->store->markCancelled($queryId);

        $state = $this->store->getQueryState($queryId);
        $this->assertSame(QueryStatus::CANCELLED->value, $state['status']);
        $this->assertArrayHasKey('completedAt', $state);
    }

    public function testIsCancelledReturnsTrueWhenCancellationRequested(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->requestCancellation($queryId);

        $this->assertTrue($this->store->isCancelled($queryId));
    }

    public function testIsCancelledReturnsFalseWhenNotCancelled(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $this->assertFalse($this->store->isCancelled($queryId));
    }

    public function testIsCancelledReturnsFalseForNonexistentQuery(): void
    {
        $this->assertFalse($this->store->isCancelled('nonexistent-query'));
    }

    public function testGetQueryStateReturnsNullForNonexistentQuery(): void
    {
        $this->assertNull($this->store->getQueryState('nonexistent-query'));
    }

    public function testGetStatusReturnsCorrectStatus(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $this->assertSame(QueryStatus::PENDING, $this->store->getStatus($queryId));

        $this->store->markInProgress($queryId);
        $this->assertSame(QueryStatus::IN_PROGRESS, $this->store->getStatus($queryId));

        $this->store->storeResult($queryId, []);
        $this->assertSame(QueryStatus::COMPLETED, $this->store->getStatus($queryId));
    }

    public function testGetStatusReturnsNullForNonexistentQuery(): void
    {
        $this->assertNull($this->store->getStatus('nonexistent-query'));
    }

    public function testDeleteQueryRemovesFromCache(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $this->assertNotNull($this->store->getQueryState($queryId));

        $this->store->deleteQuery($queryId);

        $this->assertNull($this->store->getQueryState($queryId));
    }

    public function testQueryStatusIsTerminalForCompletedFailedCancelled(): void
    {
        $this->assertTrue(QueryStatus::COMPLETED->isTerminal());
        $this->assertTrue(QueryStatus::FAILED->isTerminal());
        $this->assertTrue(QueryStatus::CANCELLED->isTerminal());
        $this->assertFalse(QueryStatus::PENDING->isTerminal());
        $this->assertFalse(QueryStatus::IN_PROGRESS->isTerminal());
    }

    // === Facet Batch Tests ===

    public function testInitializeFacetBatchesSetsBatchesAsPending(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);

        $batchIds = ['device', 'app', 'trace', 'user'];
        $this->store->initializeFacetBatches($queryId, $batchIds);

        $state = $this->store->getQueryState($queryId);
        $this->assertArrayHasKey('facetBatches', $state);
        $this->assertCount(4, $state['facetBatches']);

        foreach ($batchIds as $batchId) {
            $this->assertArrayHasKey($batchId, $state['facetBatches']);
            $this->assertSame('pending', $state['facetBatches'][$batchId]['status']);
            $this->assertEmpty($state['facetBatches'][$batchId]['facets']);
            $this->assertNull($state['facetBatches'][$batchId]['error']);
        }
    }

    public function testAppendFacetsMarksBatchAsCompleted(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app']);

        $facets = [
            ['attribute' => 'device_model', 'label' => 'Device Model', 'values' => []],
            ['attribute' => 'os_name', 'label' => 'OS Name', 'values' => []],
        ];

        $this->store->appendFacets($queryId, 'device', $facets);

        $state = $this->store->getQueryState($queryId);
        $this->assertSame('completed', $state['facetBatches']['device']['status']);
        $this->assertSame($facets, $state['facetBatches']['device']['facets']);
        $this->assertSame('pending', $state['facetBatches']['app']['status']);
    }

    public function testAppendFacetsAlsoAppendToMainResult(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');

        $initialFacets = [
            ['attribute' => 'event_type', 'label' => 'Event Type', 'values' => []],
        ];
        $this->store->storeResult($queryId, ['events' => [], 'facets' => $initialFacets]);
        $this->store->initializeFacetBatches($queryId, ['device']);

        $batchFacets = [
            ['attribute' => 'device_model', 'label' => 'Device Model', 'values' => []],
        ];
        $this->store->appendFacets($queryId, 'device', $batchFacets);

        $state = $this->store->getQueryState($queryId);
        $this->assertCount(2, $state['result']['facets']);
        $this->assertSame('event_type', $state['result']['facets'][0]['attribute']);
        $this->assertSame('device_model', $state['result']['facets'][1]['attribute']);
    }

    public function testMarkFacetBatchFailedStoresError(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app']);

        $this->store->markFacetBatchFailed($queryId, 'device', 'Something went wrong');

        $state = $this->store->getQueryState($queryId);
        $this->assertSame('failed', $state['facetBatches']['device']['status']);
        $this->assertSame('Something went wrong', $state['facetBatches']['device']['error']);
        $this->assertEmpty($state['facetBatches']['device']['facets']);
    }

    public function testGetPendingFacetBatchesReturnsOnlyPending(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app', 'trace', 'user']);

        // Complete some batches
        $this->store->appendFacets($queryId, 'device', []);
        $this->store->markFacetBatchFailed($queryId, 'app', 'error');

        $pending = $this->store->getPendingFacetBatches($queryId);
        $this->assertCount(2, $pending);
        $this->assertContains('trace', $pending);
        $this->assertContains('user', $pending);
    }

    public function testGetPendingFacetBatchesReturnsEmptyForNonexistentQuery(): void
    {
        $pending = $this->store->getPendingFacetBatches('nonexistent-query');
        $this->assertEmpty($pending);
    }

    public function testAreFacetBatchesCompleteReturnsTrueWhenAllDone(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app']);

        $this->assertFalse($this->store->areFacetBatchesComplete($queryId));

        $this->store->appendFacets($queryId, 'device', []);
        $this->assertFalse($this->store->areFacetBatchesComplete($queryId));

        $this->store->appendFacets($queryId, 'app', []);
        $this->assertTrue($this->store->areFacetBatchesComplete($queryId));
    }

    public function testAreFacetBatchesCompleteReturnsTrueWhenMixedCompletedAndFailed(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app']);

        $this->store->appendFacets($queryId, 'device', []);
        $this->store->markFacetBatchFailed($queryId, 'app', 'error');

        $this->assertTrue($this->store->areFacetBatchesComplete($queryId));
    }

    public function testAreFacetBatchesCompleteReturnsTrueWhenNoBatches(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);

        $this->assertTrue($this->store->areFacetBatchesComplete($queryId));
    }

    public function testGetCompletedFacetBatchesReturnsOnlyCompleted(): void
    {
        $queryId = 'test-query-123';
        $this->store->initializeQuery($queryId, [], 'user-123');
        $this->store->storeResult($queryId, ['events' => [], 'facets' => []]);
        $this->store->initializeFacetBatches($queryId, ['device', 'app', 'trace']);

        $deviceFacets = [['attribute' => 'device_model', 'values' => []]];
        $this->store->appendFacets($queryId, 'device', $deviceFacets);
        $this->store->markFacetBatchFailed($queryId, 'app', 'error');
        // trace remains pending

        $completed = $this->store->getCompletedFacetBatches($queryId);
        $this->assertCount(1, $completed);
        $this->assertArrayHasKey('device', $completed);
        $this->assertSame($deviceFacets, $completed['device']);
    }
}
