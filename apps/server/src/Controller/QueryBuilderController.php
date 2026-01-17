<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\QueryBuilder\QueryRequest;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\QueryBuilder\AttributeMetadataService;
use App\Service\QueryBuilder\EventQueryService;
use App\Service\TimeframeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/query')]
class QueryBuilderController extends AbstractController
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly AttributeMetadataService $attributeMetadataService,
        private readonly ProjectRepository $projectRepository,
        private readonly TimeframeService $timeframeService,
    ) {
    }

    #[Route('', name: 'query_index')]
    public function index(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $projects = $user ? $this->projectRepository->findByOwner($user) : [];

        // Get selected project from query params or use first available
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        if (null === $selectedProject && !empty($projects)) {
            $selectedProject = $projects[0];
        }

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Build the query request
        $queryRequest = $this->buildQueryRequestFromParams($request);
        $queryRequest->startDate = $timeframe->from;
        $queryRequest->endDate = $timeframe->to;
        $queryRequest->projectId = $selectedProject?->getPublicId()?->toRfc4122();

        // Execute the query
        $result = $this->eventQueryService->executeQuery(
            $queryRequest,
            $user?->getDefaultOrganization()?->getPublicId()?->toRfc4122(),
        );

        // Get filter builder metadata
        $filterBuilderData = $this->attributeMetadataService->getFilterBuilderData();
        $groupableAttributes = $this->attributeMetadataService->getGroupableAttributes();

        return $this->render('query_builder/index.html.twig', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'queryRequest' => $queryRequest,
            'result' => $result,
            'filterBuilderData' => $filterBuilderData,
            'groupableAttributes' => $groupableAttributes,
            'timeframe' => $timeframe,
        ]);
    }

    #[Route('/results', name: 'query_results', methods: ['GET'])]
    public function results(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        // Get selected project
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Build the query request
        $queryRequest = $this->buildQueryRequestFromParams($request);
        $queryRequest->startDate = $timeframe->from;
        $queryRequest->endDate = $timeframe->to;
        $queryRequest->projectId = $selectedProject?->getPublicId()?->toRfc4122();

        // Execute the query
        $result = $this->eventQueryService->executeQuery(
            $queryRequest,
            $user?->getDefaultOrganization()?->getPublicId()?->toRfc4122(),
        );

        // Return Turbo Frame response for results
        return $this->render('query_builder/_results.html.twig', [
            'result' => $result,
            'queryRequest' => $queryRequest,
        ]);
    }

    #[Route('/facets', name: 'query_facets', methods: ['GET'])]
    public function facets(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        // Get selected project
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Build the query request
        $queryRequest = $this->buildQueryRequestFromParams($request);
        $queryRequest->startDate = $timeframe->from;
        $queryRequest->endDate = $timeframe->to;
        $queryRequest->projectId = $selectedProject?->getPublicId()?->toRfc4122();

        // Execute the query for facets only
        $result = $this->eventQueryService->executeQuery(
            $queryRequest,
            $user?->getDefaultOrganization()?->getPublicId()?->toRfc4122(),
        );

        return $this->json([
            'facets' => array_map(fn ($f) => $f->toArray(), $result->facets),
            'total' => $result->total,
        ]);
    }

    #[Route('/export', name: 'query_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        // Get selected project
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Build the query request without pagination limits
        $queryRequest = $this->buildQueryRequestFromParams($request);
        $queryRequest->startDate = $timeframe->from;
        $queryRequest->endDate = $timeframe->to;
        $queryRequest->projectId = $selectedProject?->getPublicId()?->toRfc4122();
        $queryRequest->limit = 10000; // Reasonable export limit

        // Get events for export
        $events = $this->eventQueryService->executeQueryForExport(
            $queryRequest,
            $user?->getDefaultOrganization()?->getPublicId()?->toRfc4122(),
        );

        $filename = sprintf('events-export-%s.csv', date('Y-m-d-His'));

        $response = new StreamedResponse(function () use ($events) {
            $handle = fopen('php://output', 'w');

            // Write headers
            if (!empty($events)) {
                fputcsv($handle, array_keys($events[0]));

                // Write data rows
                foreach ($events as $event) {
                    // Flatten arrays for CSV
                    $row = array_map(function ($value) {
                        if (is_array($value)) {
                            return json_encode($value);
                        }

                        return $value;
                    }, $event);
                    fputcsv($handle, $row);
                }
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * Build a QueryRequest from request parameters.
     */
    private function buildQueryRequestFromParams(Request $request): QueryRequest
    {
        $filtersParam = $request->query->all('filters');
        $queryData = [
            'filters' => $filtersParam,
            'groupBy' => $request->query->get('groupBy'),
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 50),
        ];

        return QueryRequest::fromArray($queryData);
    }
}
