<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TimeframeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/timeframe', name: 'timeframe_')]
#[IsGranted('ROLE_USER')]
class TimeframeController extends AbstractController
{
    public function __construct(
        private readonly TimeframeService $timeframeService,
    ) {
    }

    #[Route('/set', name: 'set', methods: ['POST'])]
    public function set(Request $request): Response
    {
        $preset = $request->request->get('preset');
        $customFrom = $request->request->get('custom_from');
        $customTo = $request->request->get('custom_to');

        if ('custom' === $preset && $customFrom && $customTo) {
            try {
                $from = new \DateTimeImmutable($customFrom);
                $to = new \DateTimeImmutable($customTo);
                $this->timeframeService->setCustomRange($from, $to);
            } catch (\Exception) {
                $this->addFlash('error', 'Invalid date range');
            }
        } elseif ($preset && 'custom' !== $preset) {
            try {
                $this->timeframeService->setPreset($preset);
            } catch (\InvalidArgumentException) {
                $this->addFlash('error', 'Invalid timeframe preset');
            }
        }

        // Redirect back to the referrer or dashboard
        $referer = $request->headers->get('referer');
        if ($referer) {
            // Remove any existing from/to params from the referer URL
            // so the new session-based timeframe takes effect
            $parsedUrl = parse_url($referer);
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                unset($queryParams['from'], $queryParams['to']);
                $newQuery = http_build_query($queryParams);
                $referer = $parsedUrl['scheme'].'://'.$parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $referer .= ':'.$parsedUrl['port'];
                }
                $referer .= $parsedUrl['path'] ?? '';
                if ($newQuery) {
                    $referer .= '?'.$newQuery;
                }
            }

            return $this->redirect($referer);
        }

        return $this->redirectToRoute('dashboard');
    }
}
