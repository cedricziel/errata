<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projects', name: 'project_')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ApiKeyRepository $apiKeyRepository,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        $projects = $user ? $this->projectRepository->findByOwner($user) : [];

        return $this->render('project/index.html.twig', [
            'projects' => $projects,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('project', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $name = $request->request->get('name');
            $bundleId = $request->request->get('bundle_identifier');
            $platform = $request->request->get('platform');

            if (empty($name)) {
                $this->addFlash('error', 'Project name is required');

                return $this->redirectToRoute('project_new', [], Response::HTTP_SEE_OTHER);
            }

            $project = new Project();
            $project->setName($name);
            $project->setBundleIdentifier($bundleId ?: null);
            $project->setPlatform($platform ?: 'ios');
            /** @var User $user */
            $user = $this->getUser();
            $project->setOwner($user);

            // Set the organization from the user's default organization
            $organization = $user->getDefaultOrganization();
            if (null === $organization) {
                $this->addFlash('error', 'You must belong to an organization to create a project');

                return $this->redirectToRoute('project_new', [], Response::HTTP_SEE_OTHER);
            }
            $project->setOrganization($organization);

            $this->projectRepository->save($project, true);

            // Create a default API key
            $keyData = ApiKey::generateKey();
            $apiKey = new ApiKey();
            $apiKey->setKeyHash($keyData['hash']);
            $apiKey->setKeyPrefix($keyData['prefix']);
            $apiKey->setProject($project);
            $apiKey->setLabel('Default');
            $apiKey->setScopes([ApiKey::SCOPE_INGEST]);

            $this->apiKeyRepository->save($apiKey, true);

            $this->addFlash('success', 'Project created successfully');
            $this->addFlash('api_key', $keyData['plain']);

            return $this->redirectToRoute('project_show', ['publicId' => $project->getPublicId()->toRfc4122()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('project/new.html.twig');
    }

    #[Route('/{publicId}', name: 'show')]
    public function show(string $publicId): Response
    {
        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        $apiKeys = $this->apiKeyRepository->findByProject($project);

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'apiKeys' => $apiKeys,
        ]);
    }

    #[Route('/{publicId}/edit', name: 'edit')]
    public function edit(string $publicId, Request $request): Response
    {
        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('project', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $name = $request->request->get('name');
            $bundleId = $request->request->get('bundle_identifier');
            $platform = $request->request->get('platform');

            if (empty($name)) {
                $this->addFlash('error', 'Project name is required');

                return $this->redirectToRoute('project_edit', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
            }

            $project->setName($name);
            $project->setBundleIdentifier($bundleId ?: null);
            $project->setPlatform($platform ?: 'ios');
            $project->setUpdatedAt(new \DateTimeImmutable());

            $this->projectRepository->save($project, true);

            $this->addFlash('success', 'Project updated successfully');

            return $this->redirectToRoute('project_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('project/edit.html.twig', [
            'project' => $project,
        ]);
    }

    #[Route('/{publicId}/settings/opentelemetry', name: 'settings_opentelemetry')]
    public function opentelemetrySettings(string $publicId): Response
    {
        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        $apiKeys = $this->apiKeyRepository->findByProject($project);

        return $this->render('project/settings/opentelemetry.html.twig', [
            'project' => $project,
            'apiKeys' => $apiKeys,
        ]);
    }

    #[Route('/{publicId}/keys/new', name: 'create_key', methods: ['POST'])]
    public function createKey(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('api_key', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        $label = $request->request->get('label', 'API Key');
        $environment = $request->request->get('environment', ApiKey::ENV_DEVELOPMENT);

        $keyData = ApiKey::generateKey();
        $apiKey = new ApiKey();
        $apiKey->setKeyHash($keyData['hash']);
        $apiKey->setKeyPrefix($keyData['prefix']);
        $apiKey->setProject($project);
        $apiKey->setLabel($label);
        $apiKey->setEnvironment($environment);
        $apiKey->setScopes([ApiKey::SCOPE_INGEST]);

        $this->apiKeyRepository->save($apiKey, true);

        $this->addFlash('success', 'API key created successfully');
        $this->addFlash('api_key', $keyData['plain']);

        return $this->redirectToRoute('project_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{publicId}/keys/{keyId}/revoke', name: 'revoke_key', methods: ['POST'])]
    public function revokeKey(string $publicId, int $keyId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('api_key', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $project = $this->projectRepository->findByPublicId($publicId);

        if (null === $project) {
            throw $this->createNotFoundException('Project not found');
        }

        // Verify user has access
        $user = $this->getUser();
        if ($project->getOwner() !== $user) {
            throw $this->createAccessDeniedException('You do not have access to this project');
        }

        $apiKey = $this->apiKeyRepository->find($keyId);

        if (null === $apiKey || $apiKey->getProject() !== $project) {
            throw $this->createNotFoundException('API key not found');
        }

        $apiKey->setIsActive(false);
        $this->apiKeyRepository->save($apiKey, true);

        $this->addFlash('success', 'API key revoked successfully');

        return $this->redirectToRoute('project_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }
}
