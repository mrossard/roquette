<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707185209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
        $this->addSql('COMMENT ON COLUMN kanban_column.created_at IS \'\'');
        $this->addSql('ALTER INDEX idx_70db6a0872f5a1aa RENAME TO IDX_157CF28672F5A1AA');
        $this->addSql('ALTER TABLE user_group DROP CONSTRAINT fk_8f02bf9d72f5a1aa');
        $this->addSql('DROP INDEX uniq_8f02bf9d72f5a1aa');
        $this->addSql('ALTER TABLE user_group RENAME COLUMN channel_id TO workspace_id');
        $this->addSql('ALTER TABLE user_group ADD CONSTRAINT FK_8F02BF9D82D40A1F FOREIGN KEY (workspace_id) REFERENCES "workspace" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F02BF9D82D40A1F ON user_group (workspace_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
        $this->addSql('COMMENT ON COLUMN "kanban_column".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER INDEX idx_157cf28672f5a1aa RENAME TO idx_70db6a0872f5a1aa');
        $this->addSql('ALTER TABLE "user_group" DROP CONSTRAINT FK_8F02BF9D82D40A1F');
        $this->addSql('DROP INDEX UNIQ_8F02BF9D82D40A1F');
        $this->addSql('ALTER TABLE "user_group" RENAME COLUMN workspace_id TO channel_id');
        $this->addSql('ALTER TABLE "user_group" ADD CONSTRAINT fk_8f02bf9d72f5a1aa FOREIGN KEY (channel_id) REFERENCES channel (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX uniq_8f02bf9d72f5a1aa ON "user_group" (channel_id)');
    }
}
