<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260117091412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, key_hash VARCHAR(64) NOT NULL, key_prefix VARCHAR(12) NOT NULL, scopes CLOB NOT NULL, environment VARCHAR(20) NOT NULL, label VARCHAR(255) DEFAULT NULL, is_active BOOLEAN NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_9579321F166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9579321F57BFB971 ON api_keys (key_hash)');
        $this->addSql('CREATE INDEX IDX_9579321F166D1F9C ON api_keys (project_id)');
        $this->addSql('CREATE INDEX idx_api_key_hash ON api_keys (key_hash)');
        $this->addSql('CREATE TABLE issues (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id BLOB NOT NULL, fingerprint VARCHAR(64) NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, culprit CLOB DEFAULT NULL, severity VARCHAR(20) DEFAULT NULL, occurrence_count INTEGER NOT NULL, affected_users INTEGER NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, metadata CLOB DEFAULT NULL, project_id INTEGER NOT NULL, CONSTRAINT FK_DA7D7F83166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DA7D7F83B5B48B91 ON issues (public_id)');
        $this->addSql('CREATE INDEX IDX_DA7D7F83166D1F9C ON issues (project_id)');
        $this->addSql('CREATE INDEX idx_issue_fingerprint ON issues (fingerprint)');
        $this->addSql('CREATE INDEX idx_issue_project_status ON issues (project_id, status)');
        $this->addSql('CREATE INDEX idx_issue_last_seen ON issues (last_seen_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_fingerprint ON issues (project_id, fingerprint)');
        $this->addSql('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, public_id BLOB NOT NULL, name VARCHAR(255) NOT NULL, bundle_identifier VARCHAR(255) DEFAULT NULL, platform VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, owner_id INTEGER NOT NULL, CONSTRAINT FK_5C93B3A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5C93B3A4B5B48B91 ON projects (public_id)');
        $this->addSql('CREATE INDEX IDX_5C93B3A47E3C61F9 ON projects (owner_id)');
        $this->addSql('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE api_keys');
        $this->addSql('DROP TABLE issues');
        $this->addSql('DROP TABLE projects');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
