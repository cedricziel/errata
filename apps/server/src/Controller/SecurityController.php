<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\User;
use App\Repository\OrganizationMembershipRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepository,
        OrganizationRepository $organizationRepository,
        OrganizationMembershipRepository $organizationMembershipRepository,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $email = $request->request->get('email');
            $password = $request->request->get('password');
            $name = $request->request->get('name');
            $orgName = $request->request->get('org_name');

            // Basic validation
            $errors = [];
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please provide a valid email address.';
            }
            if (empty($password) || strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if (null !== $userRepository->findByEmail($email)) {
                $errors[] = 'An account with this email already exists.';
            }

            if (empty($errors)) {
                $user = new User();
                $user->setEmail($email);
                $user->setName($name ?: null);
                $user->setPassword($passwordHasher->hashPassword($user, $password));

                $userRepository->save($user, true);

                // Create personal organization
                $defaultOrgName = $name ?: explode('@', $email)[0];
                $organizationName = $orgName ?: ($defaultOrgName."'s Organization");

                $organization = new Organization();
                $organization->setName($organizationName);
                $organization->setSlug($this->generateUniqueSlug($organizationName, $organizationRepository));

                $organizationRepository->save($organization, true);

                // Create membership with owner role
                $membership = new OrganizationMembership();
                $membership->setUser($user);
                $membership->setOrganization($organization);
                $membership->setRole(OrganizationMembership::ROLE_OWNER);

                $organizationMembershipRepository->save($membership, true);

                $this->addFlash('success', 'Account created successfully. Please log in.');

                return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
            }

            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
        }

        return $this->render('security/register.html.twig');
    }

    private function generateUniqueSlug(string $name, OrganizationRepository $organizationRepository): string
    {
        $baseSlug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $baseSlug = trim($baseSlug, '-');
        $slug = $baseSlug;
        $counter = 1;

        while (null !== $organizationRepository->findBySlug($slug)) {
            $slug = $baseSlug.'-'.$counter;
            ++$counter;
        }

        return $slug;
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method is intercepted by the logout key on the firewall.
        throw new \LogicException('This method should not be reached.');
    }
}
