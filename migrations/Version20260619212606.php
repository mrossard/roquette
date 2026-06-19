<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619212606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE sessions');
        $this->addSql('CREATE INDEX idx_channel_is_private ON channel (is_private)');
        $this->addSql('CREATE INDEX idx_channel_is_dm ON channel (is_dm)');
        $this->addSql('CREATE INDEX idx_channel_export_created_at ON channel_export (created_at)');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('ALTER INDEX idx_ed568ebea7c41d6f RENAME TO idx_poll_vote_option_id');
        $this->addSql('ALTER INDEX idx_a4d707f7537a1329 RENAME TO idx_reaction_message_id');
        $this->addSql('ALTER INDEX idx_8a74175672f5a1aa RENAME TO idx_webhook_channel_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY (sess_id))');
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
        $this->addSql('DROP INDEX idx_channel_is_private');
        $this->addSql('DROP INDEX idx_channel_is_dm');
        $this->addSql('DROP INDEX idx_channel_export_created_at');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('ALTER INDEX idx_poll_vote_option_id RENAME TO idx_ed568ebea7c41d6f');
        $this->addSql('ALTER INDEX idx_reaction_message_id RENAME TO idx_a4d707f7537a1329');
        $this->addSql('ALTER INDEX idx_webhook_channel_id RENAME TO idx_8a74175672f5a1aa');
    }
}
