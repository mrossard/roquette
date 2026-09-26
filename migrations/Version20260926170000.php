<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter la colonne ai_moderation_enabled sur la table channel.
 */
final class Version20260926170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la colonne ai_moderation_enabled sur la table channel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channel ADD ai_moderation_enabled BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "channel" DROP ai_moderation_enabled');
    }
}
