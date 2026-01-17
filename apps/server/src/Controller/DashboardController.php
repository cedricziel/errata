<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\IssueRepository;
use App\Repository\ProjectRepository;
use App\Service\TimeframeService;
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
        private readonly TimeframeService $timeframeService,
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

        // Resolve the global timeframe
        $timeframe = $this->timeframeService->resolveTimeframe($request);

        $stats = null;
        $issuesOverTimeChart = null;
        $eventsByTypeChart = null;
        $topIssues = [];

        if (null !== $selectedProject) {
            $stats = $this->getProjectStats($selectedProject, $timeframe->from, $timeframe->to);
            $issuesOverTimeChart = $this->createIssuesOverTimeChart($selectedProject, $timeframe->from, $timeframe->to);
            $eventsByTypeChart = $this->createEventsByTypeChart($selectedProject, $timeframe->from, $timeframe->to);
            $topIssues = $this->issueRepository->findOpenIssues($selectedProject, 10);
        }

        return $this->render('dashboard/index.html.twig', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'stats' => $stats,
            'issuesOverTimeChart' => $issuesOverTimeChart,
            'eventsByTypeChart' => $eventsByTypeChart,
            'topIssues' => $topIssues,
            'timeframe' => $timeframe,
        ]);
    }

    /**
     * Get statistics for a project within a timeframe.
     *
     * @return array<string, mixed>
     */
    private function getProjectStats(Project $project, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $openCount = $this->issueRepository->countByProjectInTimeframe($project, Issue::STATUS_OPEN, $from, $to);
        $resolvedCount = $this->issueRepository->countByProjectInTimeframe($project, Issue::STATUS_RESOLVED, $from, $to);
        $totalCount = $this->issueRepository->countByProjectInTimeframe($project, null, $from, $to);

        $countsByType = $this->issueRepository->getIssueCountsByTypeInTimeframe($project, $from, $to);

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
    private function createIssuesOverTimeChart(Project $project, \DateTimeInterface $from, \DateTimeInterface $to): Chart
    {
        // Calculate the number of days in the timeframe
        $interval = $from->diff($to);
        $days = max(1, $interval->days);

        $data = $this->issueRepository->getIssuesOverTimeInRange($project, $from, $to);

        $labels = [];
        $counts = [];

        // Create DateTimeImmutable versions for manipulation
        $startDate = \DateTimeImmutable::createFromInterface($from);
        $endDate = \DateTimeImmutable::createFromInterface($to);

        // For short timeframes (less than 2 days), use hourly buckets
        if ($days < 2) {
            $bucketInterval = new \DateInterval('PT1H');
            $dateFormat = 'H:i';
        } else {
            $bucketInterval = new \DateInterval('P1D');
            $dateFormat = 'M j';
        }

        $period = new \DatePeriod($startDate, $bucketInterval, $endDate->modify('+1 second'));

        $dataMap = [];
        foreach ($data as $row) {
            $dataMap[$row['date']] = (int) $row['count'];
        }

        foreach ($period as $date) {
            if ($days < 2) {
                $dateStr = $date->format('Y-m-d H:00');
            } else {
                $dateStr = $date->format('Y-m-d');
            }
            $labels[] = $date->format($dateFormat);
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
    private function createEventsByTypeChart(Project $project, \DateTimeInterface $from, \DateTimeInterface $to): Chart
    {
        $countsByType = $this->issueRepository->getIssueCountsByTypeInTimeframe($project, $from, $to);

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
