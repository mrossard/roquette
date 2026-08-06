<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806222000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add moderation fields to message table for AI content moderation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD moderation_status VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD moderation_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD original_content TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP moderation_status');
        $this->addSql('ALTER TABLE message DROP moderation_reason');
        $this->addSql('ALTER TABLE message DROP original_content');
    }
}
