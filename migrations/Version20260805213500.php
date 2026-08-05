<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805213500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reminder table for AI scheduled reminders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reminder (
            id SERIAL NOT NULL,
            user_id INT NOT NULL,
            channel_id INT NOT NULL,
            message TEXT NOT NULL,
            scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            status VARCHAR(20) NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE INDEX idx_reminder_scheduled_at ON reminder (scheduled_at)');
        $this->addSql('CREATE INDEX idx_reminder_status ON reminder (status)');
        $this->addSql('CREATE INDEX IDX_4B399CE6A76ED395 ON reminder (user_id)');
        $this->addSql('CREATE INDEX IDX_4B399CE672F5A1AA ON reminder (channel_id)');
        $this->addSql('COMMENT ON COLUMN reminder.scheduled_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN reminder.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_4B399CE6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_4B399CE672F5A1AA FOREIGN KEY (channel_id) REFERENCES channel (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reminder');
    }
}
