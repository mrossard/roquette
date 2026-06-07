<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607153424 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'CREATE TABLE channel_administrator (channel_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (channel_id, user_id))',
        );
        $this->addSql('CREATE INDEX IDX_F942E5E572F5A1AA ON channel_administrator (channel_id)');
        $this->addSql('CREATE INDEX IDX_F942E5E5A76ED395 ON channel_administrator (user_id)');
        $this->addSql(
            'ALTER TABLE channel_administrator ADD CONSTRAINT FK_F942E5E572F5A1AA FOREIGN KEY (channel_id) REFERENCES "channel" (id) ON DELETE CASCADE',
        );
        $this->addSql(
            'ALTER TABLE channel_administrator ADD CONSTRAINT FK_F942E5E5A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE',
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channel_administrator DROP CONSTRAINT FK_F942E5E572F5A1AA');
        $this->addSql('ALTER TABLE channel_administrator DROP CONSTRAINT FK_F942E5E5A76ED395');
        $this->addSql('DROP TABLE channel_administrator');
    }
}
