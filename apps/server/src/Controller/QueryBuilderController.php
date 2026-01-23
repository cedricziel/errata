<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\QueryBuilder\QueryRequest;
use App\Entity\User;
use App\Message\ExecuteQuery;
use App\Repository\ProjectRepository;
use App\Service\QueryBuilder\AsyncQueryResultStore;
use App\Service\QueryBuilder\AttributeMetadataService;
use App\Service\QueryBuilder\EventQueryService;
use App\Service\TimeframeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_USER')]
#[Route('/query')]
class QueryBuilderController extends AbstractController
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
        private readonly AttributeMetadataService $attributeMetadataService,
        private readonly ProjectRepository $projectRepository,
        private readonly TimeframeService $timeframeService,
        private readonly MessageBusInterface $messageBus,
        private readonly AsyncQueryResultStore $resultStore,
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
     * Submit a query for async execution.
     *
     * Returns immediately with a queryId and stream URL.
     * Client should subscribe to SSE stream for results.
     */
    #[Route('/submit', name: 'query_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (null === $user) {
            return new JsonResponse([
                'error' => 'User not authenticated',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Get selected project
        $selectedProjectId = $request->request->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Build the query request
        $queryRequest = $this->buildQueryRequestFromRequest($request);
        $queryRequest->startDate = $timeframe->from;
        $queryRequest->endDate = $timeframe->to;
        $queryRequest->projectId = $selectedProject?->getPublicId()?->toRfc4122();

        // Generate unique query ID
        $queryId = Uuid::v7()->toRfc4122();

        // Get organization ID
        $organizationId = $user->getDefaultOrganization()?->getPublicId()?->toRfc4122();

        // Initialize the query in the cache store
        $this->resultStore->initializeQuery(
            $queryId,
            $queryRequest->toArray(),
            (string) ($user->getId() ?? 0),
            $organizationId,
        );

        // Dispatch the message for async execution
        $this->messageBus->dispatch(new ExecuteQuery(
            $queryId,
            $queryRequest->toArray(),
            (string) ($user->getId() ?? 0),
            $organizationId,
        ));

        return new JsonResponse([
            'queryId' => $queryId,
            'streamUrl' => $this->generateUrl('query_stream', ['queryId' => $queryId], UrlGeneratorInterface::ABSOLUTE_PATH),
            'cancelUrl' => $this->generateUrl('query_cancel', ['queryId' => $queryId], UrlGeneratorInterface::ABSOLUTE_PATH),
            'statusUrl' => $this->generateUrl('query_status', ['queryId' => $queryId], UrlGeneratorInterface::ABSOLUTE_PATH),
        ]);
    }

    /**
     * Build a QueryRequest from request parameters (GET).
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

    /**
     * Build a QueryRequest from POST request body.
     */
    private function buildQueryRequestFromRequest(Request $request): QueryRequest
    {
        // Try JSON body first
        $content = $request->getContent();
        if (!empty($content)) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                return QueryRequest::fromArray($data);
            }
        }

        // Fall back to form data
        $filtersParam = $request->request->all('filters');
        $queryData = [
            'filters' => $filtersParam,
            'groupBy' => $request->request->get('groupBy'),
            'page' => $request->request->getInt('page', 1),
            'limit' => $request->request->getInt('limit', 50),
        ];

        return QueryRequest::fromArray($queryData);
    }
}
