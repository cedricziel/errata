<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjectRepository;
use App\Service\Otel\OtelDataService;
use App\Service\TimeframeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projects/{publicId}/otel', name: 'otel_')]
class OtelController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly OtelDataService $otelDataService,
        private readonly TimeframeService $timeframeService,
    ) {
    }

    #[Route('/traces', name: 'traces_index')]
    public function tracesIndex(string $publicId, Request $request): Response
    {
        $project = $this->getProjectOrFail($publicId);

        $timeframe = $this->timeframeService->resolveTimeframe($request);
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $traces = $this->otelDataService->getTraces(
            $project->getPublicId()->toRfc4122(),
            $timeframe->from,
            $timeframe->to,
            $limit,
            $offset
        );

        return $this->render('otel/traces/index.html.twig', [
            'project' => $project,
            'traces' => $traces,
            'page' => $page,
            'timeframe' => $timeframe,
        ]);
    }

    #[Route('/traces/{traceId}', name: 'traces_show')]
    public function tracesShow(string $publicId, string $traceId): Response
    {
        $project = $this->getProjectOrFail($publicId);

        $spans = $this->otelDataService->getTraceSpans(
            $project->getPublicId()->toRfc4122(),
            $traceId
        );

        if (empty($spans)) {
            throw $this->createNotFoundException('Trace not found');
        }

        $spanTree = $this->otelDataService->buildSpanTree($spans);

        // Calculate trace timing for waterfall display
        $minTimestamp = PHP_INT_MAX;
        $maxTimestamp = 0;
        foreach ($spans as $span) {
            $start = $span['timestamp'] ?? 0;
            $duration = ($span['duration_ms'] ?? 0) * 1000; // Convert ms to microseconds
            if ($start < $minTimestamp) {
                $minTimestamp = $start;
            }
            if ($start + $duration > $maxTimestamp) {
                $maxTimestamp = $start + $duration;
            }
        }
        $totalDuration = $maxTimestamp - $minTimestamp;

        return $this->render('otel/traces/show.html.twig', [
            'project' => $project,
            'trace_id' => $traceId,
            'spans' => $spans,
            'span_tree' => $spanTree,
            'trace_start' => $minTimestamp,
            'trace_duration' => $totalDuration,
        ]);
    }

    #[Route('/logs', name: 'logs_index')]
    public function logsIndex(string $publicId, Request $request): Response
    {
        $project = $this->getProjectOrFail($publicId);

        $timeframe = $this->timeframeService->resolveTimeframe($request);
        $severity = $request->query->get('severity');
        $search = $request->query->get('search');
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $logs = $this->otelDataService->getLogs(
            $project->getPublicId()->toRfc4122(),
            $timeframe->from,
            $timeframe->to,
            $severity,
            $search,
            $limit,
            $offset
        );

        return $this->render('otel/logs/index.html.twig', [
            'project' => $project,
            'logs' => $logs,
            'page' => $page,
            'timeframe' => $timeframe,
            'filters' => [
                'severity' => $severity,
                'search' => $search,
            ],
        ]);
    }

    #[Route('/logs/{eventId}', name: 'logs_show')]
    public function logsShow(string $publicId, string $eventId): Response
    {
        $project = $this->getProjectOrFail($publicId);

        $log = $this->otelDataService->getLog(
            $project->getPublicId()->toRfc4122(),
            $eventId
        );

        if (null === $log) {
            throw $this->createNotFoundException('Log not found');
        }

        return $this->render('otel/logs/show.html.twig', [
            'project' => $project,
            'log' => $log,
        ]);
    }

    #[Route('/metrics', name: 'metrics_index')]
    public function metricsIndex(string $publicId, Request $request): Response
    {
        $project = $this->getProjectOrFail($publicId);

        $timeframe = $this->timeframeService->resolveTimeframe($request);
        $metricName = $request->query->get('metric');
        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $metrics = $this->otelDataService->getMetrics(
            $project->getPublicId()->toRfc4122(),
            $timeframe->from,
            $timeframe->to,
            $metricName,
            $limit,
            $offset
        );

        $metricNames = $this->otelDataService->getMetricNames(
            $project->getPublicId()->toRfc4122()
        );

        return $this->render('otel/metrics/index.html.twig', [
            'project' => $project,
            'metrics' => $metrics,
            'metric_names' => $metricNames,
            'page' => $page,
            'timeframe' => $timeframe,
            'filters' => [
                'metric' => $metricName,
            ],
        ]);
    }

    private function getProjectOrFail(string $publicId): \App\Entity\Project
    {
        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        return $project;
    }
}
