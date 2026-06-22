<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621215916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channel ALTER pinned_message_id TYPE BIGINT');
        $this->addSql('ALTER TABLE channel ALTER parent_message_id TYPE BIGINT');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('ALTER TABLE message ALTER id TYPE BIGINT');
        $this->addSql('ALTER TABLE message ALTER parent_message_id TYPE BIGINT');
        $this->addSql('ALTER TABLE poll ALTER message_id TYPE BIGINT');
        $this->addSql('ALTER TABLE reaction ALTER message_id TYPE BIGINT');
        $this->addSql('ALTER TABLE user_saved_messages ALTER message_id TYPE BIGINT');
        $this->addSql('ALTER TABLE user_channel_read ALTER last_read_message_id TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "channel" ALTER pinned_message_id TYPE INT');
        $this->addSql('ALTER TABLE "channel" ALTER parent_message_id TYPE INT');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('ALTER TABLE "message" ALTER id TYPE INT');
        $this->addSql('ALTER TABLE "message" ALTER parent_message_id TYPE INT');
        $this->addSql('ALTER TABLE "poll" ALTER message_id TYPE INT');
        $this->addSql('ALTER TABLE "reaction" ALTER message_id TYPE INT');
        $this->addSql('ALTER TABLE "user_channel_read" ALTER last_read_message_id TYPE INT');
        $this->addSql('ALTER TABLE user_saved_messages ALTER message_id TYPE INT');
    }
}
