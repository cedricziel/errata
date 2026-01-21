<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\QueryBuilder;

use App\Message\ComputeFacetBatch;
use App\Service\QueryBuilder\FacetBatchDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class FacetBatchDispatcherTest extends TestCase
{
    private MessageBusInterface $bus;
    private FacetBatchDispatcher $dispatcher;
    /** @var array<Envelope> */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->dispatchedMessages = [];

        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')
            ->willReturnCallback(function ($message) {
                $envelope = new Envelope($message);
                $this->dispatchedMessages[] = $envelope;

                return $envelope;
            });

        $this->dispatcher = new FacetBatchDispatcher($this->bus);
    }

    public function testDispatchDeferredFacetsDispatchesFourBatches(): void
    {
        $queryId = 'test-query-123';
        $queryContext = ['filters' => [], 'projectId' => 'proj-123'];

        $this->dispatcher->dispatchDeferredFacets($queryId, $queryContext);

        $this->assertCount(4, $this->dispatchedMessages);
    }

    public function testDispatchDeferredFacetsCreatesCorrectMessages(): void
    {
        $queryId = 'test-query-123';
        $queryContext = ['filters' => [], 'projectId' => 'proj-123'];

        $this->dispatcher->dispatchDeferredFacets($queryId, $queryContext);

        $batchIds = [];
        foreach ($this->dispatchedMessages as $envelope) {
            $message = $envelope->getMessage();
            $this->assertInstanceOf(ComputeFacetBatch::class, $message);
            $this->assertSame($queryId, $message->queryId);
            $this->assertSame($queryContext, $message->queryContext);
            $batchIds[] = $message->batchId;
        }

        $this->assertContains('device', $batchIds);
        $this->assertContains('app', $batchIds);
        $this->assertContains('trace', $batchIds);
        $this->assertContains('user', $batchIds);
    }

    public function testDeviceBatchHasCorrectAttributes(): void
    {
        $this->dispatcher->dispatchDeferredFacets('query-123', []);

        $deviceMessage = $this->findMessageByBatchId('device');
        $this->assertNotNull($deviceMessage);
        $this->assertContains('device_model', $deviceMessage->attributes);
        $this->assertContains('os_name', $deviceMessage->attributes);
        $this->assertContains('os_version', $deviceMessage->attributes);
    }

    public function testAppBatchHasCorrectAttributes(): void
    {
        $this->dispatcher->dispatchDeferredFacets('query-123', []);

        $appMessage = $this->findMessageByBatchId('app');
        $this->assertNotNull($appMessage);
        $this->assertContains('app_version', $appMessage->attributes);
        $this->assertContains('app_build', $appMessage->attributes);
    }

    public function testTraceBatchHasCorrectAttributes(): void
    {
        $this->dispatcher->dispatchDeferredFacets('query-123', []);

        $traceMessage = $this->findMessageByBatchId('trace');
        $this->assertNotNull($traceMessage);
        $this->assertContains('operation', $traceMessage->attributes);
        $this->assertContains('span_status', $traceMessage->attributes);
    }

    public function testUserBatchHasCorrectAttributes(): void
    {
        $this->dispatcher->dispatchDeferredFacets('query-123', []);

        $userMessage = $this->findMessageByBatchId('user');
        $this->assertNotNull($userMessage);
        $this->assertContains('user_id', $userMessage->attributes);
        $this->assertContains('locale', $userMessage->attributes);
    }

    public function testGetBatchesReturnsExpectedStructure(): void
    {
        $batches = FacetBatchDispatcher::getBatches();

        $this->assertArrayHasKey('device', $batches);
        $this->assertArrayHasKey('app', $batches);
        $this->assertArrayHasKey('trace', $batches);
        $this->assertArrayHasKey('user', $batches);
    }

    public function testGetDeferredAttributesReturnsAllAttributes(): void
    {
        $attributes = FacetBatchDispatcher::getDeferredAttributes();

        $expected = [
            'device_model', 'os_name', 'os_version',
            'app_version', 'app_build',
            'operation', 'span_status',
            'user_id', 'locale',
        ];

        foreach ($expected as $attr) {
            $this->assertContains($attr, $attributes);
        }

        $this->assertCount(9, $attributes);
    }

    private function findMessageByBatchId(string $batchId): ?ComputeFacetBatch
    {
        foreach ($this->dispatchedMessages as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ComputeFacetBatch && $message->batchId === $batchId) {
                return $message;
            }
        }

        return null;
    }
}
