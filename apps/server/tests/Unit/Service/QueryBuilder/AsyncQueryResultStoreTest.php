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
}
