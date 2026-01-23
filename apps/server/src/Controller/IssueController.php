<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Service\Parquet\ParquetReaderService;
use App\Service\TimeframeService;
use Doctrine\ORM\EntityNotFoundException as DoctrineEntityNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/issues', name: 'issue_')]
class IssueController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly IssueRepository $issueRepository,
        private readonly ParquetReaderService $parquetReader,
        private readonly TimeframeService $timeframeService,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $projects = $user ? $this->projectRepository->findByOwner($user) : [];

        // Get selected project
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        if (null === $selectedProject && !empty($projects)) {
            $selectedProject = $projects[0];
        }

        if (null === $selectedProject) {
            return $this->render('issue/index.html.twig', [
                'projects' => $projects,
                'selectedProject' => null,
                'issues' => [],
                'filters' => [],
            ]);
        }

        // Resolve timeframe from global picker
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        // Get filters from query
        $filters = [
            'status' => $request->query->get('status'),
            'type' => $request->query->get('type'),
            'severity' => $request->query->get('severity'),
            'search' => $request->query->get('search'),
            'from' => $timeframe->from->format('Y-m-d H:i:s'),
            'to' => $timeframe->to->format('Y-m-d H:i:s'),
        ];

        // Remove empty filters (except from/to which are always set)
        $filters = array_filter($filters, fn ($v) => null !== $v && '' !== $v);

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $issues = $this->issueRepository->findByFilters($selectedProject, $filters, $limit, $offset);

        return $this->render('issue/index.html.twig', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'issues' => $issues,
            'filters' => $filters,
            'page' => $page,
            'timeframe' => $timeframe,
        ]);
    }

    #[Route('/{publicId}', name: 'show')]
    public function show(string $publicId, Request $request): Response
    {
        $issue = $this->issueRepository->findByPublicId($publicId);

        if (null === $issue) {
            throw $this->createNotFoundException('Issue not found');
        }

        // Try to access the project - may throw if organization filter hides it
        try {
            $project = $issue->getProject();
            // Force proxy initialization to catch EntityNotFoundException
            // getId() won't work as the ID is already known by the proxy
            $project->getName();
        } catch (DoctrineEntityNotFoundException) {
            throw $this->createNotFoundException('Issue not found');
        }

        // Verify user has access to this issue's project
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this issue');
        }

        // Get related events from Parquet
        // Use issue's time bounds to constrain the search and avoid scanning all files
        $organizationId = $project->getOrganization()->getPublicId()?->toRfc4122();
        $projectId = $project->getPublicId()->toRfc4122();
        $events = $this->parquetReader->getEventsByFingerprint(
            $issue->getFingerprint(),
            $organizationId,
            $projectId,
            null,
            20,
            $issue->getFirstSeenAt(),
            $issue->getLastSeenAt(),
        );

        return $this->render('issue/show.html.twig', [
            'issue' => $issue,
            'project' => $project,
            'events' => $events,
        ]);
    }

    #[Route('/{publicId}/status', name: 'update_status', methods: ['POST'])]
    public function updateStatus(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('issue', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $issue = $this->issueRepository->findByPublicId($publicId);

        if (null === $issue) {
            throw $this->createNotFoundException('Issue not found');
        }

        // Try to access the project - may throw if organization filter hides it
        try {
            $project = $issue->getProject();
            // Force proxy initialization to catch EntityNotFoundException
            $project->getName();
        } catch (DoctrineEntityNotFoundException) {
            throw $this->createNotFoundException('Issue not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this issue');
        }

        $status = $request->request->get('status');

        if (!in_array($status, [Issue::STATUS_OPEN, Issue::STATUS_RESOLVED, Issue::STATUS_IGNORED], true)) {
            $this->addFlash('error', 'Invalid status');

            return $this->redirectToRoute('issue_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        $issue->setStatus($status);
        $this->issueRepository->save($issue, true);

        $this->addFlash('success', 'Issue status updated to '.ucfirst($status));

        return $this->redirectToRoute('issue_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }
}
