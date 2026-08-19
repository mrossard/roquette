<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la relation target_message_id sur la table reminder pour lier un rappel à un message spécifique.
 */
final class Version20260819213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute target_message_id sur la table reminder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reminder ADD COLUMN IF NOT EXISTS target_message_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE reminder DROP CONSTRAINT IF EXISTS FK_reminder_target_message');
        $this->addSql(
            'ALTER TABLE reminder ADD CONSTRAINT FK_reminder_target_message FOREIGN KEY (target_message_id) REFERENCES "message" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_reminder_target_message ON reminder (target_message_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reminder DROP CONSTRAINT IF EXISTS FK_reminder_target_message');
        $this->addSql('DROP INDEX IF EXISTS idx_reminder_target_message');
        $this->addSql('ALTER TABLE reminder DROP COLUMN IF EXISTS target_message_id');
    }
}
