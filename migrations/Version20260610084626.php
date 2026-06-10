<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610084626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE message ADD parent_message_id INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE message ADD CONSTRAINT FK_B6BD307F14399779 FOREIGN KEY (parent_message_id) REFERENCES "message" (id) ON DELETE SET NULL NOT DEFERRABLE',
        );
        $this->addSql('CREATE INDEX IDX_B6BD307F14399779 ON message (parent_message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "message" DROP CONSTRAINT FK_B6BD307F14399779');
        $this->addSql('DROP INDEX IDX_B6BD307F14399779');
        $this->addSql('ALTER TABLE "message" DROP parent_message_id');
    }
}
