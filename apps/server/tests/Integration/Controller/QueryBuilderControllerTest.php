<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Message\ComputeFacetBatch;
use App\Message\ExecuteQuery;
use App\Tests\Integration\AbstractIntegrationTestCase;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class QueryBuilderControllerTest extends AbstractIntegrationTestCase
{
    use InteractsWithMessenger;

    public function testQueryPageRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/query')
            ->assertRedirectedTo('/login');
    }

    public function testQueryPageDisplaysForLoggedInUser(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Query Builder');
    }

    public function testQueryPageShowsFilterBuilder(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Add Filter')
            ->assertSeeIn('body', 'Run Query');
    }

    public function testQueryPageShowsFacetPanel(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Facets');
    }

    public function testQueryPageShowsResultsArea(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Results');
    }

    public function testQueryPageWithProject(): void
    {
        $user = $this->createTestUser();
        $project = $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query?project='.$project->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertSeeIn('body', 'Test Project');
    }

    public function testQueryPageShowsGroupBySelector(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Group by');
    }

    public function testQueryPageWithFilters(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        // Test with a basic filter
        $this->browser()
            ->actingAs($user)
            ->visit('/query?filters[0][attribute]=event_type&filters[0][operator]=eq&filters[0][value]=error')
            ->assertSuccessful();
    }

    public function testQueryResultsEndpoint(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query/results')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Results');
    }

    public function testQueryFacetsEndpointReturnsJson(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query/facets')
            ->assertSuccessful()
            ->assertJson();
    }

    public function testQueryExportEndpoint(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query/export')
            ->assertSuccessful()
            ->assertHeaderContains('Content-Type', 'text/csv');
    }

    public function testQueryPageWithMultipleFilters(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        // Test with multiple filters
        $this->browser()
            ->actingAs($user)
            ->visit('/query?filters[0][attribute]=event_type&filters[0][operator]=eq&filters[0][value]=error&filters[1][attribute]=severity&filters[1][operator]=eq&filters[1][value]=error')
            ->assertSuccessful();
    }

    public function testQueryPageWithGroupBy(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query?groupBy=event_type')
            ->assertSuccessful();
    }

    public function testQueryPageWithPagination(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->visit('/query?page=1&limit=10')
            ->assertSuccessful();
    }

    public function testNavigationShowsQueryLink(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/')
            ->assertSuccessful()
            ->assertSeeIn('nav', 'Query');
    }

    public function testQuerySubmitEndpointReturnsQueryIdAndUrls(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'groupBy' => null,
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->assertJson()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('queryId', $response);
                $this->assertArrayHasKey('streamUrl', $response);
                $this->assertArrayHasKey('cancelUrl', $response);
                $this->assertArrayHasKey('statusUrl', $response);
                $this->assertStringContainsString('/query/stream/', $response['streamUrl']);
                $this->assertStringContainsString('/query/cancel/', $response['cancelUrl']);
                $this->assertStringContainsString('/query/status/', $response['statusUrl']);
            });
    }

    public function testQuerySubmitRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->post('/query/submit', [
                'json' => ['filters' => []],
            ])
            ->assertRedirectedTo('/login');
    }

    public function testQueryStatusEndpointReturnsStatus(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // First submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Then check its status
        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/'.$queryId)
            ->assertSuccessful()
            ->assertJson()
            ->use(function ($browser) use ($queryId) {
                $response = $browser->json()->decoded();
                $this->assertSame($queryId, $response['queryId']);
                $this->assertArrayHasKey('status', $response);
            });
    }

    public function testQueryStatusEndpointReturnsNotFoundForInvalidQueryId(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/nonexistent-query-id')
            ->assertStatus(404);
    }

    public function testQueryCancelEndpointRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->post('/query/cancel/some-query-id')
            ->assertRedirectedTo('/login');
    }

    public function testQueryCancelEndpointReturnsNotFoundForInvalidQueryId(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->post('/query/cancel/nonexistent-query-id')
            ->assertStatus(404);
    }

    public function testQueryStreamEndpointRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/query/stream/some-query-id')
            ->assertRedirectedTo('/login');
    }

    public function testQueryCancelEndpointSucceedsForPendingQuery(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // First submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Then cancel it
        $this->browser()
            ->actingAs($user)
            ->post('/query/cancel/'.$queryId)
            ->assertSuccessful()
            ->assertJson()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertTrue($response['success']);
                $this->assertSame('Cancellation requested', $response['message']);
            });
    }

    public function testFullAsyncQueryFlow(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // 1. Submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // 2. Verify message was queued
        $this->transport('async_query')
            ->queue()
            ->assertContains(ExecuteQuery::class, 1);

        // 3. Check initial status (should be pending)
        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/'.$queryId)
            ->assertSuccessful()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertSame('pending', $response['status']);
            });

        // 4. Process the queued message
        $this->transport('async_query')->process();

        // 5. Check status after processing (should be completed)
        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/'.$queryId)
            ->assertSuccessful()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertSame('completed', $response['status']);
                $this->assertTrue($response['hasResult']);
            });
    }

    public function testSubmitVerifiesQueryInitializedInCache(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // Submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Verify the query was initialized in the cache
        /** @var \App\Service\QueryBuilder\AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(\App\Service\QueryBuilder\AsyncQueryResultStore::class);
        $state = $resultStore->getQueryState($queryId);

        $this->assertNotNull($state);
        $this->assertSame('pending', $state['status']);
        $this->assertArrayHasKey('queryRequest', $state);
    }

    public function testQueryWithFiltersSubmitsSuccessfully(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [
                        ['attribute' => 'event_type', 'operator' => 'eq', 'value' => 'error'],
                        ['attribute' => 'severity', 'operator' => 'eq', 'value' => 'error'],
                    ],
                    'groupBy' => 'event_type',
                    'page' => 1,
                    'limit' => 25,
                ],
            ])
            ->assertSuccessful()
            ->assertJson()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertArrayHasKey('queryId', $response);
            });
    }

    // === Parallel Facet Computation Tests ===

    public function testFullAsyncQueryFlowWithDeferredFacets(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // 1. Submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // 2. Verify ExecuteQuery message was queued
        $this->transport('async_query')
            ->queue()
            ->assertContains(ExecuteQuery::class, 1);

        // 3. Process the ExecuteQuery message
        $this->transport('async_query')->process(1);

        // 4. Verify ComputeFacetBatch messages were dispatched (4 batches)
        $this->transport('async_query')
            ->queue()
            ->assertContains(ComputeFacetBatch::class, 4);

        // 5. Verify query status is completed with priority facets
        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/'.$queryId)
            ->assertSuccessful()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertSame('completed', $response['status']);
                $this->assertTrue($response['hasResult']);
            });

        // 6. Verify facet batch tracking was initialized
        /** @var \App\Service\QueryBuilder\AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(\App\Service\QueryBuilder\AsyncQueryResultStore::class);
        $state = $resultStore->getQueryState($queryId);

        $this->assertArrayHasKey('facetBatches', $state);
        $this->assertCount(4, $state['facetBatches']);

        // 7. Process all facet batch messages
        $this->transport('async_query')->process(4);

        // 8. Verify all batches completed
        $state = $resultStore->getQueryState($queryId);
        foreach (['device', 'app', 'trace', 'user'] as $batchId) {
            $this->assertSame('completed', $state['facetBatches'][$batchId]['status']);
        }
    }

    public function testQuerySubmitDispatchesCorrectFacetBatches(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful();

        // Process ExecuteQuery
        $this->transport('async_query')->process(1);

        // Check that all 4 facet batches were dispatched
        $queue = $this->transport('async_query')->queue();

        // Extract batch IDs from queued messages
        $batchIds = [];
        foreach ($queue->messages() as $message) {
            if ($message instanceof ComputeFacetBatch) {
                $batchIds[] = $message->batchId;
            }
        }

        $this->assertContains('device', $batchIds);
        $this->assertContains('app', $batchIds);
        $this->assertContains('trace', $batchIds);
        $this->assertContains('user', $batchIds);
    }

    public function testCancelledQuerySkipsFacetBatches(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // Submit a query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->assertSuccessful()
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Cancel the query before processing
        $this->browser()
            ->actingAs($user)
            ->post('/query/cancel/'.$queryId)
            ->assertSuccessful();

        // Process the ExecuteQuery (should detect cancellation)
        $this->transport('async_query')->process(1);

        // Verify no facet batches were dispatched
        $this->transport('async_query')
            ->queue()
            ->assertNotContains(ComputeFacetBatch::class);
    }

    public function testFacetBatchesCanBeProcessedInParallel(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // Submit query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Process ExecuteQuery
        $this->transport('async_query')->process(1);

        /** @var \App\Service\QueryBuilder\AsyncQueryResultStore $resultStore */
        $resultStore = static::getContainer()->get(\App\Service\QueryBuilder\AsyncQueryResultStore::class);

        // Process batches one at a time and verify progressive completion
        for ($i = 1; $i <= 4; ++$i) {
            $this->transport('async_query')->process(1);

            $completedCount = 4 - count($resultStore->getPendingFacetBatches($queryId));
            $this->assertSame($i, $completedCount);
        }

        // All batches should be complete
        $this->assertTrue($resultStore->areFacetBatchesComplete($queryId));
    }

    public function testStatusEndpointReflectsFacetBatchState(): void
    {
        $user = $this->createTestUser();
        $this->createTestProject($user, 'Test Project');

        $queryId = null;

        // Submit query
        $this->browser()
            ->actingAs($user)
            ->post('/query/submit', [
                'json' => [
                    'filters' => [],
                    'page' => 1,
                    'limit' => 50,
                ],
            ])
            ->use(function ($browser) use (&$queryId) {
                $response = $browser->json()->decoded();
                $queryId = $response['queryId'];
            });

        // Process ExecuteQuery
        $this->transport('async_query')->process(1);

        // Status should be completed (even with pending facet batches)
        $this->browser()
            ->actingAs($user)
            ->visit('/query/status/'.$queryId)
            ->assertSuccessful()
            ->use(function ($browser) {
                $response = $browser->json()->decoded();
                $this->assertSame('completed', $response['status']);
            });
    }
}
