<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour l'indexation sémantique et FTS des documents joints aux messages.
 */
final class Version20260822122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la table message_document_chunk pour l\'indexation et la recherche des documents joints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS message_document_chunk (
            id BIGSERIAL PRIMARY KEY,
            message_id BIGINT NOT NULL,
            channel_id BIGINT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            chunk_index INT NOT NULL,
            content TEXT NOT NULL,
            search_vector tsvector GENERATED ALWAYS AS (to_tsvector(\'french\', coalesce(content, \'\'))) STORED,
            embedding vector(768) DEFAULT NULL,
            created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL,
            CONSTRAINT fk_message_doc_chunk_message FOREIGN KEY (message_id) REFERENCES "message" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT fk_message_doc_chunk_channel FOREIGN KEY (channel_id) REFERENCES "channel" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_doc_chunk_vector ON message_document_chunk USING hnsw (embedding vector_cosine_ops)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_doc_chunk_search_vector ON message_document_chunk USING gin(search_vector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_doc_chunk_message ON message_document_chunk (message_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_message_doc_chunk_channel ON message_document_chunk (channel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS message_document_chunk');
    }
}
