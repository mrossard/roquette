<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generated content_tsvector column and GIN index for full-text search on messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "message" ADD COLUMN content_tsvector tsvector
            GENERATED ALWAYS AS (
                to_tsvector(\'french\', COALESCE(content, \'\') || \' \' || COALESCE(file_name, \'\'))
            ) STORED');
        $this->addSql('CREATE INDEX idx_message_content_fts ON "message" USING gin(content_tsvector)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_message_content_fts');
        $this->addSql('ALTER TABLE "message" DROP COLUMN IF EXISTS content_tsvector');
    }
}
