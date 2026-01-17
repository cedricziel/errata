<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add Organization entities with multi-tenancy support.
 *
 * This migration:
 * 1. Creates the organizations table
 * 2. Creates the organization_memberships table
 * 3. Adds organization_id to projects table (nullable initially)
 * 4. Migrates existing data: creates personal organization for each user (done via postUp)
 * 5. Makes organization_id NOT NULL after data migration
 */
final class Version20260117144508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Organization entity with multi-tenancy filtering support';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Create organizations table
        $this->addSql('CREATE TABLE organizations (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id BLOB NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_427C1C7FB5B48B91 ON organizations (public_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_427C1C7F989D9B62 ON organizations (slug)');

        // Step 2: Create organization_memberships table
        $this->addSql('CREATE TABLE organization_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, role VARCHAR(20) NOT NULL, joined_at DATETIME NOT NULL, CONSTRAINT FK_B606E30DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_B606E30D32C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B606E30DA76ED395 ON organization_memberships (user_id)');
        $this->addSql('CREATE INDEX IDX_B606E30D32C8A3DE ON organization_memberships (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX unique_user_org ON organization_memberships (user_id, organization_id)');

        // Step 3: Add organization_id to projects (nullable initially for data migration)
        $this->addSql('CREATE TEMPORARY TABLE __temp__projects AS SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id FROM projects');
        $this->addSql('DROP TABLE projects');
        $this->addSql('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, owner_id INTEGER NOT NULL, organization_id INTEGER DEFAULT NULL, public_id BLOB NOT NULL, name VARCHAR(255) NOT NULL, bundle_identifier VARCHAR(255) DEFAULT NULL, platform VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5C93B3A432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO projects (id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id) SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id FROM __temp__projects');
        $this->addSql('DROP TABLE __temp__projects');
        $this->addSql('CREATE INDEX IDX_5C93B3A47E3C61F9 ON projects (owner_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A432C8A3DE ON projects (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C93B3A4B5B48B91 ON projects (public_id)');
    }

    public function postUp(Schema $schema): void
    {
        // Step 4: Data migration - create personal organizations for existing users
        $this->migrateExistingData();

        // Step 5: Make organization_id NOT NULL
        $this->connection->executeStatement('CREATE TEMPORARY TABLE __temp__projects AS SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id, organization_id FROM projects');
        $this->connection->executeStatement('DROP TABLE projects');
        $this->connection->executeStatement('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, owner_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, public_id BLOB NOT NULL, name VARCHAR(255) NOT NULL, bundle_identifier VARCHAR(255) DEFAULT NULL, platform VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5C93B3A432C8A3DE FOREIGN KEY (organization_id) REFERENCES organizations (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->connection->executeStatement('INSERT INTO projects (id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id, organization_id) SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id, organization_id FROM __temp__projects');
        $this->connection->executeStatement('DROP TABLE __temp__projects');
        $this->connection->executeStatement('CREATE INDEX IDX_5C93B3A47E3C61F9 ON projects (owner_id)');
        $this->connection->executeStatement('CREATE INDEX IDX_5C93B3A432C8A3DE ON projects (organization_id)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_5C93B3A4B5B48B91 ON projects (public_id)');
    }

    private function migrateExistingData(): void
    {
        $connection = $this->connection;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Get all existing users
        $users = $connection->fetchAllAssociative('SELECT id, name, email FROM users');

        foreach ($users as $user) {
            // Generate a random 16-byte binary for UUID (simplified, not proper v7)
            $publicId = random_bytes(16);
            $orgName = ($user['name'] ?? $user['email'])."'s Organization";
            $slug = $this->generateSlug($user['email']);

            // Create personal organization for each user
            $connection->executeStatement(
                'INSERT INTO organizations (public_id, name, slug, created_at) VALUES (?, ?, ?, ?)',
                [$publicId, $orgName, $slug, $now]
            );

            $organizationId = $connection->lastInsertId();

            // Create membership with owner role
            $connection->executeStatement(
                'INSERT INTO organization_memberships (user_id, organization_id, role, joined_at) VALUES (?, ?, ?, ?)',
                [$user['id'], $organizationId, 'owner', $now]
            );

            // Update all projects owned by this user to belong to their organization
            $connection->executeStatement(
                'UPDATE projects SET organization_id = ? WHERE owner_id = ?',
                [$organizationId, $user['id']]
            );
        }
    }

    private function generateSlug(string $email): string
    {
        // Extract username from email and make it URL-safe
        $username = explode('@', $email)[0];
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $username));

        return trim($slug, '-');
    }

    public function down(Schema $schema): void
    {
        // Remove organization_id from projects
        $this->addSql('CREATE TEMPORARY TABLE __temp__projects AS SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id FROM projects');
        $this->addSql('DROP TABLE projects');
        $this->addSql('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, owner_id INTEGER NOT NULL, public_id BLOB NOT NULL, name VARCHAR(255) NOT NULL, bundle_identifier VARCHAR(255) DEFAULT NULL, platform VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO projects (id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id) SELECT id, public_id, name, bundle_identifier, platform, created_at, updated_at, owner_id FROM __temp__projects');
        $this->addSql('DROP TABLE __temp__projects');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C93B3A4B5B48B91 ON projects (public_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A47E3C61F9 ON projects (owner_id)');

        // Drop tables
        $this->addSql('DROP TABLE organization_memberships');
        $this->addSql('DROP TABLE organizations');
    }
}
