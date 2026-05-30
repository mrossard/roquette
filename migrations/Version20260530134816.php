<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530134816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX UNIQ_INVITATION_CHANNEL_INVITEE ON invitation (channel_id, invitee_id)');
        $this->addSql('ALTER INDEX idx_b6bd307ff675f31b RENAME TO idx_message_author');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_OAUTH ON "user" (oauth_id, oauth_provider)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_INVITATION_CHANNEL_INVITEE');
        $this->addSql('ALTER INDEX idx_message_author RENAME TO idx_b6bd307ff675f31b');
        $this->addSql('DROP INDEX UNIQ_USER_OAUTH');
    }
}
