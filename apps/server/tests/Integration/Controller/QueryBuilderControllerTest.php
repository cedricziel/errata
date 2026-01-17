<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\AbstractIntegrationTestCase;

class QueryBuilderControllerTest extends AbstractIntegrationTestCase
{
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
}
