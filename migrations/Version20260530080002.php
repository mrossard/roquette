<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530080002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_message_channel_parent ON message (channel_id, parent_id)');
        $this->addSql('CREATE INDEX idx_message_created_at ON message (created_at)');
        $this->addSql('ALTER INDEX idx_b6bd307f727aca70 RENAME TO idx_message_parent');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_message_channel_parent');
        $this->addSql('DROP INDEX idx_message_created_at');
        $this->addSql('ALTER INDEX idx_message_parent RENAME TO idx_b6bd307f727aca70');
    }
}
