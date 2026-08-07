<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recrée la table doc_chunks du store vectoriel RAG, supprimée par Version20260614195613.
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recrée la table doc_chunks (store vectoriel RAG)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE IF NOT EXISTS doc_chunks (id UUID PRIMARY KEY, metadata JSONB, embedding vector(768) NOT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS doc_chunks_embedding_idx ON doc_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS doc_chunks_embedding_idx');
        $this->addSql('DROP TABLE IF EXISTS doc_chunks');
    }
}
