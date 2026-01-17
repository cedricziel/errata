<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\OrganizationMembership;
use App\Tests\Integration\AbstractIntegrationTestCase;

class SecurityControllerTest extends AbstractIntegrationTestCase
{
    public function testRegistrationPageLoads(): void
    {
        $this->browser()
            ->visit('/register')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Create your account');
    }

    public function testSuccessfulRegistrationCreatesUserAndOrganization(): void
    {
        $email = 'newuser@example.com';
        $password = 'securepassword123';
        $name = 'New User';

        $this->browser()
            ->interceptRedirects()
            ->visit('/register')
            ->fillField('email', $email)
            ->fillField('password', $password)
            ->fillField('name', $name)
            ->click('Create account')
            ->assertRedirectedTo('/login');

        // Verify user was created
        $user = $this->userRepository->findByEmail($email);
        $this->assertNotNull($user);
        $this->assertSame($name, $user->getName());
        $this->assertSame($email, $user->getEmail());

        // Verify organization was created
        $memberships = $this->organizationMembershipRepository->findByUser($user);
        $this->assertCount(1, $memberships);

        $membership = $memberships[0];
        $this->assertSame(OrganizationMembership::ROLE_OWNER, $membership->getRole());

        $organization = $membership->getOrganization();
        $this->assertNotNull($organization);
        $this->assertStringContainsString('New User', $organization->getName());
    }

    public function testRegistrationWithCustomOrgName(): void
    {
        $email = 'custom@example.com';
        $password = 'securepassword123';
        $name = 'Custom User';
        $orgName = 'My Custom Company';

        $this->browser()
            ->interceptRedirects()
            ->visit('/register')
            ->fillField('email', $email)
            ->fillField('password', $password)
            ->fillField('name', $name)
            ->fillField('org_name', $orgName)
            ->click('Create account')
            ->assertRedirectedTo('/login');

        // Verify organization has custom name
        $user = $this->userRepository->findByEmail($email);
        $this->assertNotNull($user);

        $memberships = $this->organizationMembershipRepository->findByUser($user);
        $this->assertCount(1, $memberships);

        $organization = $memberships[0]->getOrganization();
        $this->assertSame($orgName, $organization->getName());
    }

    public function testRegistrationFailsWithInvalidEmail(): void
    {
        $this->browser()
            ->visit('/register')
            ->fillField('email', 'invalid-email')
            ->fillField('password', 'securepassword123')
            ->fillField('name', 'Test User')
            ->click('Create account')
            ->assertSeeIn('body', 'Please provide a valid email address');

        // Verify no user was created
        $user = $this->userRepository->findByEmail('invalid-email');
        $this->assertNull($user);
    }

    public function testRegistrationFailsWithShortPassword(): void
    {
        $this->browser()
            ->visit('/register')
            ->fillField('email', 'test@example.com')
            ->fillField('password', 'short')
            ->fillField('name', 'Test User')
            ->click('Create account')
            ->assertSeeIn('body', 'Password must be at least 8 characters');

        // Verify no user was created
        $user = $this->userRepository->findByEmail('test@example.com');
        $this->assertNull($user);
    }

    public function testRegistrationFailsWithDuplicateEmail(): void
    {
        // Create existing user
        $this->createTestUser('existing@example.com');

        $this->browser()
            ->visit('/register')
            ->fillField('email', 'existing@example.com')
            ->fillField('password', 'securepassword123')
            ->fillField('name', 'Duplicate User')
            ->click('Create account')
            ->assertSeeIn('body', 'An account with this email already exists');
    }

    public function testNewUserCanLoginAfterRegistration(): void
    {
        $email = 'logintest@example.com';
        $password = 'securepassword123';

        // Register the user
        $this->browser()
            ->interceptRedirects()
            ->visit('/register')
            ->fillField('email', $email)
            ->fillField('password', $password)
            ->fillField('name', 'Login Test')
            ->click('Create account')
            ->assertRedirectedTo('/login');

        // Login with the new user
        $this->browser()
            ->interceptRedirects()
            ->visit('/login')
            ->fillField('email', $email)
            ->fillField('password', $password)
            ->click('Sign in')
            ->assertRedirectedTo('/');
    }

    public function testLoggedInUserRedirectsFromRegisterToHome(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->visit('/register')
            ->assertRedirectedTo('/');
    }

    public function testRegistrationWithoutNameUsesEmailForOrgName(): void
    {
        $email = 'noname@example.com';
        $password = 'securepassword123';

        $this->browser()
            ->interceptRedirects()
            ->visit('/register')
            ->fillField('email', $email)
            ->fillField('password', $password)
            ->click('Create account')
            ->assertRedirectedTo('/login');

        // Verify organization was created with email-derived name
        $user = $this->userRepository->findByEmail($email);
        $this->assertNotNull($user);

        $memberships = $this->organizationMembershipRepository->findByUser($user);
        $this->assertCount(1, $memberships);

        $organization = $memberships[0]->getOrganization();
        $this->assertStringContainsString('noname', $organization->getName());
    }
}
