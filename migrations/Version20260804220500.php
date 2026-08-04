<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804220500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make Message entity the owning side of Poll relation by adding poll_id column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD poll_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307F3C947C0F FOREIGN KEY (poll_id) REFERENCES "poll" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6BD307F3C947C0F ON message (poll_id)');
        $this->addSql('UPDATE message SET poll_id = p.id FROM poll p WHERE p.message_id = message.id');
        $this->addSql('ALTER TABLE poll DROP CONSTRAINT IF EXISTS fk_84bcfa17537a1329');
        $this->addSql('ALTER TABLE poll DROP COLUMN IF EXISTS message_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poll ADD message_id BIGINT DEFAULT NULL');
        $this->addSql('UPDATE poll SET message_id = m.id FROM message m WHERE m.poll_id = poll.id');
        $this->addSql('ALTER TABLE message DROP CONSTRAINT FK_B6BD307F3C947C0F');
        $this->addSql('DROP INDEX UNIQ_B6BD307F3C947C0F');
        $this->addSql('ALTER TABLE message DROP COLUMN poll_id');
    }
}
