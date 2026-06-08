<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608135426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channel ADD parent_message_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE channel ADD CONSTRAINT FK_A2F98E4714399779 FOREIGN KEY (parent_message_id) REFERENCES "message" (id) ON DELETE SET NULL NOT DEFERRABLE',
        );
        $this->addSql('CREATE INDEX IDX_A2F98E4714399779 ON channel (parent_message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "channel" DROP CONSTRAINT FK_A2F98E4714399779');
        $this->addSql('DROP INDEX IDX_A2F98E4714399779');
        $this->addSql('ALTER TABLE "channel" DROP parent_message_id');
    }
}
