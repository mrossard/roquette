<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour la recherche hybride :
 * 1. Ajoute la colonne générée search_vector (tsvector) et son index GIN sur la table message.
 * 2. Active l'extension vector si nécessaire.
 * 3. Crée la table message_embedding avec index HNSW pour la recherche vectorielle (RAG).
 */
final class Version20260821123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le support de la recherche hybride (FTS tsvector + message_embedding pgvector)';
    }

    public function up(Schema $schema): void
    {
        // 1. PostgreSQL vector extension
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');

        // 2. Full-Text Search tsvector column and GIN index on message table
        $this->addSql('ALTER TABLE "message" ADD COLUMN IF NOT EXISTS search_vector tsvector GENERATED ALWAYS AS (to_tsvector(\'french\', coalesce(content, \'\'))) STORED');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_search_vector ON "message" USING gin(search_vector)');

        // 3. Message embedding table for vector search
        $this->addSql('CREATE TABLE IF NOT EXISTS message_embedding (
            message_id BIGINT PRIMARY KEY,
            channel_id BIGINT NOT NULL,
            embedding vector(768) NOT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            CONSTRAINT fk_message_embedding_message FOREIGN KEY (message_id) REFERENCES "message" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT fk_message_embedding_channel FOREIGN KEY (channel_id) REFERENCES "channel" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_embedding_vector ON message_embedding USING hnsw (embedding vector_cosine_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_embedding_channel ON message_embedding (channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS message_embedding');
        $this->addSql('DROP INDEX IF EXISTS idx_message_search_vector');
        $this->addSql('ALTER TABLE "message" DROP COLUMN IF EXISTS search_vector');
    }
}
