<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704152040 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_channel_workspace RENAME TO IDX_A2F98E4782D40A1F');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('ALTER INDEX idx_invitation_workspace RENAME TO IDX_F11D61A282D40A1F');
        $this->addSql('COMMENT ON COLUMN "user".email_verified_at IS \'\'');
        $this->addSql('ALTER TABLE workspace ADD avatar_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN workspace.created_at IS \'\'');
        $this->addSql('ALTER INDEX idx_workspace_creator RENAME TO IDX_8D94001961220EA6');
        $this->addSql('ALTER INDEX idx_wu_workspace RENAME TO IDX_C971A58B82D40A1F');
        $this->addSql('ALTER INDEX idx_wu_user RENAME TO IDX_C971A58BA76ED395');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX idx_a2f98e4782d40a1f RENAME TO idx_channel_workspace');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('ALTER INDEX idx_f11d61a282d40a1f RENAME TO idx_invitation_workspace');
        $this->addSql('COMMENT ON COLUMN "user".email_verified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "workspace" DROP avatar_path');
        $this->addSql('COMMENT ON COLUMN "workspace".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_8d94001961220ea6 RENAME TO idx_workspace_creator');
        $this->addSql('ALTER INDEX idx_c971a58b82d40a1f RENAME TO idx_wu_workspace');
        $this->addSql('ALTER INDEX idx_c971a58ba76ed395 RENAME TO idx_wu_user');
    }
}
