<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Entity\Project;
use App\Repository\ApiKeyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticator for API key based authentication.
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const HEADER_NAME = 'X-Errata-Key';
    public const ATTRIBUTE_API_KEY = '_errata_api_key';
    public const ATTRIBUTE_PROJECT = '_errata_project';

    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has(self::HEADER_NAME);
    }

    public function authenticate(Request $request): Passport
    {
        $apiKeyValue = $request->headers->get(self::HEADER_NAME);

        if (empty($apiKeyValue)) {
            throw new CustomUserMessageAuthenticationException('API key is required');
        }

        $apiKey = $this->apiKeyRepository->findValidByPlainKey($apiKeyValue);

        if (null === $apiKey) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired API key');
        }

        // Check for required scope
        if (!$apiKey->hasScope(ApiKey::SCOPE_INGEST)) {
            throw new CustomUserMessageAuthenticationException('API key does not have ingest permission');
        }

        // Store the API key and project for later use
        $request->attributes->set(self::ATTRIBUTE_API_KEY, $apiKey);
        $request->attributes->set(self::ATTRIBUTE_PROJECT, $apiKey->getProject());

        // Update last used timestamp
        $this->apiKeyRepository->updateLastUsed($apiKey);

        // Create a passport with the project owner as the user
        $user = $apiKey->getProject()?->getOwner();

        if (null === $user) {
            throw new CustomUserMessageAuthenticationException('Project has no owner');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'authentication_failed',
            'message' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Get the API key from the request.
     */
    public static function getApiKey(Request $request): ?ApiKey
    {
        return $request->attributes->get(self::ATTRIBUTE_API_KEY);
    }

    /**
     * Get the project from the request.
     */
    public static function getProject(Request $request): ?Project
    {
        return $request->attributes->get(self::ATTRIBUTE_PROJECT);
    }
}
