<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationSwitcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/organizations', name: 'organization_')]
class OrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly OrganizationMembershipRepository $membershipRepository,
        private readonly UserRepository $userRepository,
        private readonly OrganizationSwitcher $organizationSwitcher,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $memberships = $this->membershipRepository->findByUser($user);

        return $this->render('organization/index.html.twig', [
            'memberships' => $memberships,
        ]);
    }

    #[Route('/switch/{publicId}', name: 'switch', methods: ['POST'])]
    public function switch(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('organization_switch', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $organization = $this->getOrganizationWithAccess($publicId);

        $this->organizationSwitcher->setCurrentOrganization($organization);
        $this->addFlash('success', sprintf('Switched to %s', $organization->getName()));

        return $this->redirectToRoute('dashboard', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{publicId}', name: 'show')]
    public function show(string $publicId): Response
    {
        $organization = $this->getOrganizationWithAccess($publicId);

        /** @var User $user */
        $user = $this->getUser();
        $membership = $user->getMembershipFor($organization);

        return $this->render('organization/show.html.twig', [
            'organization' => $organization,
            'membership' => $membership,
        ]);
    }

    #[Route('/{publicId}/settings', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(string $publicId, Request $request): Response
    {
        $organization = $this->getOrganizationWithAccess($publicId, requireOwner: true);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('organization_settings', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $name = $request->request->get('name');

            if (empty($name)) {
                $this->addFlash('error', 'Organization name is required');

                return $this->redirectToRoute('organization_settings', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
            }

            $organization->setName($name);
            $organization->setUpdatedAt(new \DateTimeImmutable());
            $this->organizationRepository->save($organization, true);

            $this->addFlash('success', 'Organization updated successfully');

            return $this->redirectToRoute('organization_show', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        return $this->render('organization/settings.html.twig', [
            'organization' => $organization,
        ]);
    }

    #[Route('/{publicId}/settings/members', name: 'members')]
    public function members(string $publicId): Response
    {
        $organization = $this->getOrganizationWithAccess($publicId, requireAdmin: true);

        /** @var User $user */
        $user = $this->getUser();
        $currentMembership = $user->getMembershipFor($organization);
        $memberships = $this->membershipRepository->findByOrganization($organization);

        return $this->render('organization/settings/members.html.twig', [
            'organization' => $organization,
            'memberships' => $memberships,
            'currentMembership' => $currentMembership,
        ]);
    }

    #[Route('/{publicId}/members/invite', name: 'invite_member', methods: ['POST'])]
    public function inviteMember(string $publicId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('organization_member', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $organization = $this->getOrganizationWithAccess($publicId, requireAdmin: true);

        $email = $request->request->get('email');
        $role = $request->request->get('role', OrganizationMembership::ROLE_MEMBER);

        if (empty($email)) {
            $this->addFlash('error', 'Email is required');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        // Validate role
        if (!in_array($role, [OrganizationMembership::ROLE_MEMBER, OrganizationMembership::ROLE_ADMIN], true)) {
            $role = OrganizationMembership::ROLE_MEMBER;
        }

        // Only owners can add admins
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $currentMembership = $currentUser->getMembershipFor($organization);
        if (OrganizationMembership::ROLE_ADMIN === $role && !$currentMembership?->isOwner()) {
            $this->addFlash('error', 'Only owners can add admins');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        $invitedUser = $this->userRepository->findByEmail($email);

        if (null === $invitedUser) {
            $this->addFlash('error', 'No user found with that email address');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        // Check if already a member
        if ($invitedUser->isMemberOf($organization)) {
            $this->addFlash('error', 'User is already a member of this organization');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        $membership = new OrganizationMembership();
        $membership->setUser($invitedUser);
        $membership->setOrganization($organization);
        $membership->setRole($role);

        $this->membershipRepository->save($membership, true);

        $this->addFlash('success', sprintf('Added %s to the organization', $invitedUser->getEmail()));

        return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{publicId}/members/{memberId}/role', name: 'change_member_role', methods: ['POST'])]
    public function changeMemberRole(string $publicId, int $memberId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('organization_member', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $organization = $this->getOrganizationWithAccess($publicId, requireOwner: true);

        $membership = $this->membershipRepository->find($memberId);

        if (null === $membership || $membership->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Membership not found');
        }

        $newRole = $request->request->get('role');

        if (!in_array($newRole, [OrganizationMembership::ROLE_MEMBER, OrganizationMembership::ROLE_ADMIN, OrganizationMembership::ROLE_OWNER], true)) {
            $this->addFlash('error', 'Invalid role');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        // Prevent demoting the last owner
        if ($membership->isOwner() && OrganizationMembership::ROLE_OWNER !== $newRole) {
            $owners = $this->membershipRepository->findOwners($organization);
            if (1 === count($owners)) {
                $this->addFlash('error', 'Cannot demote the last owner');

                return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
            }
        }

        $membership->setRole($newRole);
        $this->membershipRepository->save($membership, true);

        $this->addFlash('success', sprintf('Updated role for %s', $membership->getUser()->getEmail()));

        return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{publicId}/members/{memberId}/remove', name: 'remove_member', methods: ['POST'])]
    public function removeMember(string $publicId, int $memberId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('organization_member', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $organization = $this->getOrganizationWithAccess($publicId, requireAdmin: true);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $currentMembership = $currentUser->getMembershipFor($organization);

        $membership = $this->membershipRepository->find($memberId);

        if (null === $membership || $membership->getOrganization() !== $organization) {
            throw $this->createNotFoundException('Membership not found');
        }

        // Cannot remove yourself
        if ($membership->getUser() === $currentUser) {
            $this->addFlash('error', 'You cannot remove yourself from the organization');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        // Admins can only remove members, not other admins or owners
        if (!$currentMembership?->isOwner() && !$membership->isMember()) {
            $this->addFlash('error', 'You can only remove members with member role');

            return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
        }

        // Prevent removing the last owner
        if ($membership->isOwner()) {
            $owners = $this->membershipRepository->findOwners($organization);
            if (1 === count($owners)) {
                $this->addFlash('error', 'Cannot remove the last owner');

                return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
            }
        }

        $email = $membership->getUser()->getEmail();
        $this->membershipRepository->remove($membership, true);

        $this->addFlash('success', sprintf('Removed %s from the organization', $email));

        return $this->redirectToRoute('organization_members', ['publicId' => $publicId], Response::HTTP_SEE_OTHER);
    }

    private function getOrganizationWithAccess(string $publicId, bool $requireOwner = false, bool $requireAdmin = false): Organization
    {
        $organization = $this->organizationRepository->findByPublicId($publicId);

        if (null === $organization) {
            throw $this->createNotFoundException('Organization not found');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isMemberOf($organization)) {
            throw $this->createAccessDeniedException('You are not a member of this organization');
        }

        $membership = $user->getMembershipFor($organization);

        if ($requireOwner && !$membership?->isOwner()) {
            throw $this->createAccessDeniedException('Only owners can perform this action');
        }

        if ($requireAdmin && !$membership?->canManageMembers()) {
            throw $this->createAccessDeniedException('Only admins and owners can perform this action');
        }

        return $organization;
    }
}
