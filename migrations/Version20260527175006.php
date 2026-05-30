<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527175006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE channel_user (channel_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (channel_id, user_id))');
        $this->addSql('CREATE INDEX IDX_11C7753772F5A1AA ON channel_user (channel_id)');
        $this->addSql('CREATE INDEX IDX_11C77537A76ED395 ON channel_user (user_id)');
        $this->addSql('ALTER TABLE channel_user ADD CONSTRAINT FK_11C7753772F5A1AA FOREIGN KEY (channel_id) REFERENCES "channel" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channel_user ADD CONSTRAINT FK_11C77537A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channel ADD is_private BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE channel ADD creator_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE channel ADD CONSTRAINT FK_A2F98E4761220EA6 FOREIGN KEY (creator_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A2F98E4761220EA6 ON channel (creator_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channel_user DROP CONSTRAINT FK_11C7753772F5A1AA');
        $this->addSql('ALTER TABLE channel_user DROP CONSTRAINT FK_11C77537A76ED395');
        $this->addSql('DROP TABLE channel_user');
        $this->addSql('ALTER TABLE "channel" DROP CONSTRAINT FK_A2F98E4761220EA6');
        $this->addSql('DROP INDEX IDX_A2F98E4761220EA6');
        $this->addSql('ALTER TABLE "channel" DROP is_private');
        $this->addSql('ALTER TABLE "channel" DROP creator_id');
    }
}
