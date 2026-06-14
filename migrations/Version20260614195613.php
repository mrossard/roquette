<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614195613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE doc_chunks');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('CREATE INDEX idx_message_channel_created_at ON message (channel_id, created_at)');
        $this->addSql('CREATE INDEX idx_user_last_active ON "user" (last_active_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE doc_chunks (id UUID NOT NULL, metadata JSONB DEFAULT NULL, embedding VARCHAR NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX doc_chunks_embedding_idx ON doc_chunks (embedding)');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('DROP INDEX idx_message_channel_created_at');
        $this->addSql('DROP INDEX idx_user_last_active');
    }
}
