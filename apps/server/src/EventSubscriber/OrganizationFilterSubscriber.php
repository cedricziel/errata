<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Doctrine\OrganizationFilterConfigurator;
use App\Entity\User;
use App\Security\ApiKeyAuthenticator;
use App\Service\OrganizationSwitcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class OrganizationFilterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OrganizationFilterConfigurator $filterConfigurator,
        private readonly OrganizationSwitcher $organizationSwitcher,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->headers->has(ApiKeyAuthenticator::HEADER_NAME)) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (null === $token) {
            return;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return;
        }

        $organization = $this->organizationSwitcher->initializeForUser($user);

        if (null !== $organization && null !== $organization->getId()) {
            $this->filterConfigurator->enable($organization->getId());
        }
    }
}
