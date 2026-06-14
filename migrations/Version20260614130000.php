<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute pgvector et crée la table doc_chunks pour le RAG';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE doc_chunks (id UUID PRIMARY KEY, metadata JSONB, embedding vector(768) NOT NULL)');
        $this->addSql('CREATE INDEX doc_chunks_embedding_idx ON doc_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS doc_chunks_embedding_idx');
        $this->addSql('DROP TABLE IF EXISTS doc_chunks');
    }
}
