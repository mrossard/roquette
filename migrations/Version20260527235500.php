<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add is_dm column to channel table
 */
final class Version20260527235500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_dm column to channel table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel ADD is_dm BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel DROP is_dm');
    }
}
