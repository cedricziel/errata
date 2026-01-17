<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\OrganizationSwitcher;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig extension that provides organization data as global variables.
 *
 * These variables are used to display the organization switcher dropdown
 * in the navigation bar.
 */
class OrganizationExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly OrganizationSwitcher $organizationSwitcher,
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [
                'current_organization' => null,
                'user_organizations' => [],
            ];
        }

        $currentOrganization = $this->organizationSwitcher->getCurrentOrganization();
        $memberships = $user->getMemberships();

        $organizations = [];
        foreach ($memberships as $membership) {
            $organizations[] = $membership->getOrganization();
        }

        return [
            'current_organization' => $currentOrganization,
            'user_organizations' => $organizations,
        ];
    }
}
