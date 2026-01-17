<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly IssueRepository $issueRepository,
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $projects = $user ? $this->projectRepository->findByOwner($user) : [];

        // Get selected project (first one by default)
        $selectedProjectId = $request->query->get('project');
        $selectedProject = null;

        if ($selectedProjectId) {
            $selectedProject = $this->projectRepository->findByPublicId($selectedProjectId);
        }

        if (null === $selectedProject && !empty($projects)) {
            $selectedProject = $projects[0];
        }

        $stats = null;
        $issuesOverTimeChart = null;
        $eventsByTypeChart = null;
        $topIssues = [];

        if (null !== $selectedProject) {
            $stats = $this->getProjectStats($selectedProject);
            $issuesOverTimeChart = $this->createIssuesOverTimeChart($selectedProject);
            $eventsByTypeChart = $this->createEventsByTypeChart($selectedProject);
            $topIssues = $this->issueRepository->findOpenIssues($selectedProject, 10);
        }

        return $this->render('dashboard/index.html.twig', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'stats' => $stats,
            'issuesOverTimeChart' => $issuesOverTimeChart,
            'eventsByTypeChart' => $eventsByTypeChart,
            'topIssues' => $topIssues,
        ]);
    }

    /**
     * Get statistics for a project.
     *
     * @return array<string, mixed>
     */
    private function getProjectStats(Project $project): array
    {
        $openCount = $this->issueRepository->countByProject($project, Issue::STATUS_OPEN);
        $resolvedCount = $this->issueRepository->countByProject($project, Issue::STATUS_RESOLVED);
        $totalCount = $this->issueRepository->countByProject($project);

        $countsByType = $this->issueRepository->getIssueCountsByType($project);

        return [
            'open_issues' => $openCount,
            'resolved_issues' => $resolvedCount,
            'total_issues' => $totalCount,
            'crashes' => $countsByType[Issue::TYPE_CRASH] ?? 0,
            'errors' => $countsByType[Issue::TYPE_ERROR] ?? 0,
        ];
    }

    /**
     * Create chart for issues over time.
     */
    private function createIssuesOverTimeChart(Project $project): Chart
    {
        $data = $this->issueRepository->getIssuesOverTime($project, 30);

        $labels = [];
        $counts = [];

        // Fill in missing days
        $end = new \DateTimeImmutable();
        $start = $end->modify('-30 days');
        $period = new \DatePeriod($start, new \DateInterval('P1D'), $end->modify('+1 day'));

        $dataMap = [];
        foreach ($data as $row) {
            $dataMap[$row['date']] = (int) $row['count'];
        }

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            $counts[] = $dataMap[$dateStr] ?? 0;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'New Issues',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'data' => $counts,
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * Create chart for events by type.
     */
    private function createEventsByTypeChart(Project $project): Chart
    {
        $countsByType = $this->issueRepository->getIssueCountsByType($project);

        $labels = [];
        $data = [];
        $colors = [
            Issue::TYPE_CRASH => 'rgb(239, 68, 68)',
            Issue::TYPE_ERROR => 'rgb(249, 115, 22)',
            Issue::TYPE_LOG => 'rgb(59, 130, 246)',
        ];

        foreach ($countsByType as $type => $count) {
            $labels[] = ucfirst($type);
            $data[] = $count;
        }

        // Ensure we have some data
        if (empty($data)) {
            $labels = ['No Data'];
            $data = [1];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => array_values($colors),
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ]);

        return $chart;
    }
}
