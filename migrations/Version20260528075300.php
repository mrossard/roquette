<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add file upload fields to message table
 */
final class Version20260528075300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add file upload fields to message table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD file_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD file_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD file_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD mime_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ALTER content DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP file_name');
        $this->addSql('ALTER TABLE message DROP file_path');
        $this->addSql('ALTER TABLE message DROP file_size');
        $this->addSql('ALTER TABLE message DROP mime_type');
        $this->addSql('ALTER TABLE message ALTER content SET NOT NULL');
    }
}
