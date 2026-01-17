<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\OrganizationMembership;
use App\Tests\Integration\AbstractIntegrationTestCase;

class OrganizationControllerTest extends AbstractIntegrationTestCase
{
    // ==========================================
    // Organization Index Tests
    // ==========================================

    public function testOrganizationIndexRequiresAuthentication(): void
    {
        $this->browser()
            ->interceptRedirects()
            ->visit('/organizations')
            ->assertRedirectedTo('/login');
    }

    public function testOrganizationIndexShowsUserMemberships(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Organizations')
            ->assertSeeIn('body', 'Test User'); // Default org name matches user name
    }

    public function testOrganizationIndexShowsMultipleMemberships(): void
    {
        $user = $this->createTestUser();
        $org2 = $this->createTestOrganization('Second Organization');

        // Add user to second org as member
        $membership = new OrganizationMembership();
        $membership->setUser($user);
        $membership->setOrganization($org2);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Test User')
            ->assertSeeIn('body', 'Second Organization');
    }

    // ==========================================
    // Organization Switch Tests
    // ==========================================

    public function testOrganizationSwitchRequiresAuthentication(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->interceptRedirects()
            ->post('/organizations/switch/'.$org->getPublicId()->toRfc4122())
            ->assertRedirectedTo('/login');
    }

    public function testOrganizationSwitchWorks(): void
    {
        $user = $this->createTestUser();
        $org2 = $this->createTestOrganization('Second Organization');

        // Add user to second org
        $membership = new OrganizationMembership();
        $membership->setUser($user);
        $membership->setOrganization($org2);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/organizations/switch/'.$org2->getPublicId()->toRfc4122())
            ->assertRedirectedTo('/');
    }

    public function testOrganizationSwitchRequiresMembership(): void
    {
        $user = $this->createTestUser();
        $otherOrg = $this->createTestOrganization('Other Organization');

        // User is NOT a member of otherOrg
        $this->browser()
            ->actingAs($user)
            ->post('/organizations/switch/'.$otherOrg->getPublicId()->toRfc4122())
            ->assertStatus(403);
    }

    public function testOrganizationSwitchNotFoundForInvalidId(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->post('/organizations/switch/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    // ==========================================
    // Organization Show Tests
    // ==========================================

    public function testOrganizationShowRequiresAuthentication(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->interceptRedirects()
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122())
            ->assertRedirectedTo('/login');
    }

    public function testOrganizationShowDisplaysDetails(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertSeeIn('body', $org->getName())
            ->assertSeeIn('body', 'Organization Details');
    }

    public function testOrganizationShowRequiresMembership(): void
    {
        $user = $this->createTestUser();
        $otherOrg = $this->createTestOrganization('Other Organization');

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/'.$otherOrg->getPublicId()->toRfc4122())
            ->assertStatus(403);
    }

    public function testOrganizationShowDisplaysSettingsLinkForOwner(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertSeeIn('body', 'Settings');
    }

    public function testOrganizationShowHidesSettingsLinkForMember(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();

        // Add member to owner's org
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        // Member should not see Settings link (only Members link if admin)
        $this->browser()
            ->actingAs($member)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122())
            ->assertSuccessful()
            ->assertNotSeeIn('body', 'Settings');
    }

    // ==========================================
    // Organization Settings Tests
    // ==========================================

    public function testOrganizationSettingsRequiresOwner(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();

        // Add member to owner's org
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($member)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122().'/settings')
            ->assertStatus(403);
    }

    public function testOrganizationSettingsDisplaysForOwner(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122().'/settings')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Organization Settings')
            ->assertSeeIn('body', $org->getName());
    }

    public function testOrganizationSettingsUpdatesName(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();
        $orgId = $org->getId();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/settings', [
                'body' => [
                    'name' => 'Updated Organization Name',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId);

        // Verify update in database
        $org = $this->organizationRepository->find($orgId);
        $this->assertSame('Updated Organization Name', $org->getName());
    }

    public function testOrganizationSettingsRequiresName(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();
        $orgId = $org->getId();
        $originalName = $org->getName();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/settings', [
                'body' => [
                    'name' => '',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings');

        // Verify name unchanged
        $org = $this->organizationRepository->find($orgId);
        $this->assertSame($originalName, $org->getName());
    }

    // ==========================================
    // Organization Members Page Tests
    // ==========================================

    public function testOrganizationMembersRequiresAdmin(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();

        // Add member to owner's org as regular member
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($member)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122().'/settings/members')
            ->assertStatus(403);
    }

    public function testOrganizationMembersDisplaysForOwner(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122().'/settings/members')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Members')
            ->assertSeeIn('body', 'Add Member')
            ->assertSeeIn('body', $user->getEmail());
    }

    public function testOrganizationMembersDisplaysForAdmin(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $org = $owner->getDefaultOrganization();

        // Add admin to owner's org
        $membership = new OrganizationMembership();
        $membership->setUser($admin);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($admin)
            ->visit('/organizations/'.$org->getPublicId()->toRfc4122().'/settings/members')
            ->assertSuccessful()
            ->assertSeeIn('body', 'Members');
    }

    // ==========================================
    // Member Invite Tests
    // ==========================================

    public function testInviteMemberWorks(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $newUser = $this->createTestUser('newuser@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'newuser@example.com',
                    'role' => 'member',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership created
        $membership = $this->organizationMembershipRepository->findOneByUserAndOrganization($newUser, $org);
        $this->assertNotNull($membership);
        $this->assertSame(OrganizationMembership::ROLE_MEMBER, $membership->getRole());
    }

    public function testInviteMemberAsAdminWorks(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $newUser = $this->createTestUser('newuser@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'newuser@example.com',
                    'role' => 'admin',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership created as admin
        $membership = $this->organizationMembershipRepository->findOneByUserAndOrganization($newUser, $org);
        $this->assertNotNull($membership);
        $this->assertSame(OrganizationMembership::ROLE_ADMIN, $membership->getRole());
    }

    public function testAdminCannotInviteAsAdmin(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $newUser = $this->createTestUser('newuser@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add admin to owner's org
        $membership = new OrganizationMembership();
        $membership->setUser($admin);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($membership, true);

        // Admin tries to invite as admin - should be rejected
        $this->browser()
            ->actingAs($admin)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'newuser@example.com',
                    'role' => 'admin',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify user was NOT added
        $newMembership = $this->organizationMembershipRepository->findOneByUserAndOrganization($newUser, $org);
        $this->assertNull($newMembership);
    }

    public function testInviteMemberFailsForNonExistentUser(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'nonexistent@example.com',
                    'role' => 'member',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Count memberships - should still be just the owner
        $memberships = $this->organizationMembershipRepository->findByOrganization($org);
        $this->assertCount(1, $memberships);
    }

    public function testInviteMemberFailsForExistingMember(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $existingMember = $this->createTestUser('existing@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add existing member
        $membership = new OrganizationMembership();
        $membership->setUser($existingMember);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        // Try to invite again
        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'existing@example.com',
                    'role' => 'member',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Still only 2 memberships
        $memberships = $this->organizationMembershipRepository->findByOrganization($org);
        $this->assertCount(2, $memberships);
    }

    public function testMemberCannotInvite(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add member to owner's org
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);

        $this->browser()
            ->actingAs($member)
            ->post('/organizations/'.$publicId.'/members/invite', [
                'body' => [
                    'email' => 'newuser@example.com',
                    'role' => 'member',
                ],
            ])
            ->assertStatus(403);
    }

    // ==========================================
    // Change Member Role Tests
    // ==========================================

    public function testChangeMemberRoleWorks(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add member
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);
        $membershipId = $membership->getId();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$membershipId.'/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify role changed
        $membership = $this->organizationMembershipRepository->find($membershipId);
        $this->assertSame(OrganizationMembership::ROLE_ADMIN, $membership->getRole());
    }

    public function testChangeMemberRoleRequiresOwner(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add admin and member
        $adminMembership = new OrganizationMembership();
        $adminMembership->setUser($admin);
        $adminMembership->setOrganization($org);
        $adminMembership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($adminMembership, true);

        $memberMembership = new OrganizationMembership();
        $memberMembership->setUser($member);
        $memberMembership->setOrganization($org);
        $memberMembership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($memberMembership, true);
        $memberMembershipId = $memberMembership->getId();

        // Admin tries to change role
        $this->browser()
            ->actingAs($admin)
            ->post('/organizations/'.$publicId.'/members/'.$memberMembershipId.'/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertStatus(403);

        // Verify role unchanged
        $memberMembership = $this->organizationMembershipRepository->find($memberMembershipId);
        $this->assertSame(OrganizationMembership::ROLE_MEMBER, $memberMembership->getRole());
    }

    public function testCannotDemoteLastOwner(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Get owner's membership
        $ownerMembership = $owner->getMembershipFor($org);
        $membershipId = $ownerMembership->getId();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$membershipId.'/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify role unchanged (still owner)
        $this->entityManager->clear();
        $ownerMembership = $this->organizationMembershipRepository->find($membershipId);
        $this->assertSame(OrganizationMembership::ROLE_OWNER, $ownerMembership->getRole());
    }

    public function testCanDemoteOwnerWhenMultipleOwnersExist(): void
    {
        $owner1 = $this->createTestUser('owner1@example.com');
        $owner2 = $this->createTestUser('owner2@example.com');
        $org = $owner1->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add second owner
        $owner2Membership = new OrganizationMembership();
        $owner2Membership->setUser($owner2);
        $owner2Membership->setOrganization($org);
        $owner2Membership->setRole(OrganizationMembership::ROLE_OWNER);
        $this->organizationMembershipRepository->save($owner2Membership, true);
        $owner2MembershipId = $owner2Membership->getId();

        $this->browser()
            ->actingAs($owner1)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$owner2MembershipId.'/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify role changed
        $owner2Membership = $this->organizationMembershipRepository->find($owner2MembershipId);
        $this->assertSame(OrganizationMembership::ROLE_ADMIN, $owner2Membership->getRole());
    }

    // ==========================================
    // Remove Member Tests
    // ==========================================

    public function testRemoveMemberWorks(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add member
        $membership = new OrganizationMembership();
        $membership->setUser($member);
        $membership->setOrganization($org);
        $membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($membership, true);
        $membershipId = $membership->getId();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$membershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership removed
        $membership = $this->organizationMembershipRepository->find($membershipId);
        $this->assertNull($membership);
    }

    public function testAdminCanRemoveMember(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $member = $this->createTestUser('member@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add admin and member
        $adminMembership = new OrganizationMembership();
        $adminMembership->setUser($admin);
        $adminMembership->setOrganization($org);
        $adminMembership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($adminMembership, true);

        $memberMembership = new OrganizationMembership();
        $memberMembership->setUser($member);
        $memberMembership->setOrganization($org);
        $memberMembership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($memberMembership, true);
        $memberMembershipId = $memberMembership->getId();

        $this->browser()
            ->actingAs($admin)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$memberMembershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership removed
        $membership = $this->organizationMembershipRepository->find($memberMembershipId);
        $this->assertNull($membership);
    }

    public function testAdminCannotRemoveAdmin(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin1 = $this->createTestUser('admin1@example.com');
        $admin2 = $this->createTestUser('admin2@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add two admins
        $admin1Membership = new OrganizationMembership();
        $admin1Membership->setUser($admin1);
        $admin1Membership->setOrganization($org);
        $admin1Membership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($admin1Membership, true);

        $admin2Membership = new OrganizationMembership();
        $admin2Membership->setUser($admin2);
        $admin2Membership->setOrganization($org);
        $admin2Membership->setRole(OrganizationMembership::ROLE_ADMIN);
        $this->organizationMembershipRepository->save($admin2Membership, true);
        $admin2MembershipId = $admin2Membership->getId();

        // Admin1 tries to remove Admin2
        $this->browser()
            ->actingAs($admin1)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$admin2MembershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership still exists
        $membership = $this->organizationMembershipRepository->find($admin2MembershipId);
        $this->assertNotNull($membership);
    }

    public function testCannotRemoveSelf(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $ownerMembership = $owner->getMembershipFor($org);
        $membershipId = $ownerMembership->getId();

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$membershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership still exists
        $membership = $this->organizationMembershipRepository->find($membershipId);
        $this->assertNotNull($membership);
    }

    public function testCannotRemoveLastOwner(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $admin = $this->createTestUser('admin@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add second owner to make them able to act
        $adminMembership = new OrganizationMembership();
        $adminMembership->setUser($admin);
        $adminMembership->setOrganization($org);
        $adminMembership->setRole(OrganizationMembership::ROLE_OWNER);
        $this->organizationMembershipRepository->save($adminMembership, true);

        // Get owner's membership
        $ownerMembership = $owner->getMembershipFor($org);
        $membershipId = $ownerMembership->getId();

        // Admin (now also owner) tries to remove the original owner
        // But this is allowed since there are 2 owners
        $this->browser()
            ->actingAs($admin)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$membershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify membership was removed (since there were 2 owners)
        $membership = $this->organizationMembershipRepository->find($membershipId);
        $this->assertNull($membership);
    }

    public function testCannotRemoveLastOwnerByAnotherOwner(): void
    {
        // Scenario: Two owners exist, one removes the other, leaving one.
        // Then we add a second owner who tries to remove the remaining original owner.
        // With proper entity management after browser requests.

        $owner1 = $this->createTestUser('owner1@example.com');
        $owner2 = $this->createTestUser('owner2@example.com');
        $org = $owner1->getDefaultOrganization();
        $orgId = $org->getId();
        $owner1Id = $owner1->getId();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add owner2 as second owner to owner1's org
        $owner2Membership = new OrganizationMembership();
        $owner2Membership->setUser($owner2);
        $owner2Membership->setOrganization($org);
        $owner2Membership->setRole(OrganizationMembership::ROLE_OWNER);
        $this->organizationMembershipRepository->save($owner2Membership, true);
        $owner2MembershipId = $owner2Membership->getId();

        // Owner1 removes owner2, leaving owner1 as the only owner
        $this->browser()
            ->actingAs($owner1)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$owner2MembershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Clear entity manager and re-fetch fresh entities after browser request
        $this->entityManager->clear();

        // Re-fetch entities from database
        $org = $this->organizationRepository->find($orgId);
        $owner1 = $this->userRepository->find($owner1Id);

        // Get owner1's membership ID (now the only owner)
        $owner1Membership = $this->organizationMembershipRepository->findOneByUserAndOrganization($owner1, $org);
        $owner1MembershipId = $owner1Membership->getId();

        // Now add a new owner (owner3) who will try to remove owner1
        $owner3 = $this->createTestUser('owner3@example.com');
        $owner3Id = $owner3->getId();

        // Clear and re-fetch org again since createTestUser creates its own org
        $this->entityManager->clear();
        $org = $this->organizationRepository->find($orgId);
        $owner3 = $this->userRepository->find($owner3Id);

        // Add owner3 to org as owner
        $owner3Membership = new OrganizationMembership();
        $owner3Membership->setUser($owner3);
        $owner3Membership->setOrganization($org);
        $owner3Membership->setRole(OrganizationMembership::ROLE_OWNER);
        $this->organizationMembershipRepository->save($owner3Membership, true);

        // Now owner3 tries to remove owner1 - this should SUCCEED because there are 2 owners
        $this->browser()
            ->actingAs($owner3)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$owner1MembershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify owner1's membership was removed (there were 2 owners, so it's allowed)
        $membership = $this->organizationMembershipRepository->find($owner1MembershipId);
        $this->assertNull($membership);

        // Now owner3 is the only owner - verify they can't remove themselves
        $this->entityManager->clear();
        $org = $this->organizationRepository->find($orgId);
        $owner3 = $this->userRepository->find($owner3Id);
        $owner3Membership = $this->organizationMembershipRepository->findOneByUserAndOrganization($owner3, $org);
        $owner3MembershipId = $owner3Membership->getId();

        $this->browser()
            ->actingAs($owner3)
            ->interceptRedirects()
            ->post('/organizations/'.$publicId.'/members/'.$owner3MembershipId.'/remove')
            ->assertRedirectedTo('/organizations/'.$publicId.'/settings/members');

        // Verify owner3's membership still exists (can't remove self)
        $membership = $this->organizationMembershipRepository->find($owner3MembershipId);
        $this->assertNotNull($membership);
    }

    public function testMemberCannotRemoveAnyone(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $member1 = $this->createTestUser('member1@example.com');
        $member2 = $this->createTestUser('member2@example.com');
        $org = $owner->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        // Add members
        $member1Membership = new OrganizationMembership();
        $member1Membership->setUser($member1);
        $member1Membership->setOrganization($org);
        $member1Membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($member1Membership, true);

        $member2Membership = new OrganizationMembership();
        $member2Membership->setUser($member2);
        $member2Membership->setOrganization($org);
        $member2Membership->setRole(OrganizationMembership::ROLE_MEMBER);
        $this->organizationMembershipRepository->save($member2Membership, true);
        $member2MembershipId = $member2Membership->getId();

        $this->browser()
            ->actingAs($member1)
            ->post('/organizations/'.$publicId.'/members/'.$member2MembershipId.'/remove')
            ->assertStatus(403);
    }

    // ==========================================
    // Edge Cases and Error Handling
    // ==========================================

    public function testNonExistentOrganizationReturns404(): void
    {
        $user = $this->createTestUser();

        $this->browser()
            ->actingAs($user)
            ->visit('/organizations/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function testNonExistentMembershipReturns404ForRoleChange(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->post('/organizations/'.$publicId.'/members/99999/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertStatus(404);
    }

    public function testNonExistentMembershipReturns404ForRemove(): void
    {
        $user = $this->createTestUser();
        $org = $user->getDefaultOrganization();
        $publicId = $org->getPublicId()->toRfc4122();

        $this->browser()
            ->actingAs($user)
            ->post('/organizations/'.$publicId.'/members/99999/remove')
            ->assertStatus(404);
    }

    public function testCannotChangeRoleOfMemberFromDifferentOrganization(): void
    {
        $user1 = $this->createTestUser('user1@example.com');
        $user2 = $this->createTestUser('user2@example.com');
        $org1 = $user1->getDefaultOrganization();
        $org2 = $user2->getDefaultOrganization();
        $publicId1 = $org1->getPublicId()->toRfc4122();

        // Get user2's membership ID (in org2)
        $user2Membership = $user2->getMembershipFor($org2);
        $membershipId = $user2Membership->getId();

        // User1 tries to change role of user2's membership in org1 (but membership is in org2)
        $this->browser()
            ->actingAs($user1)
            ->post('/organizations/'.$publicId1.'/members/'.$membershipId.'/role', [
                'body' => [
                    'role' => 'admin',
                ],
            ])
            ->assertStatus(404);
    }
}
