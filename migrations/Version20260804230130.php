<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804230130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('ALTER TABLE message ALTER poll_id TYPE INT');
        $this->addSql('CREATE INDEX idx_message_channel_id_desc ON message (channel_id, id)');
        $this->addSql("CREATE INDEX idx_message_pending_virus_scan ON message (id) WHERE virus_scan_status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_message_pending_virus_scan');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('DROP INDEX idx_message_channel_id_desc');
        $this->addSql('ALTER TABLE "message" ALTER poll_id TYPE BIGINT');
    }
}
