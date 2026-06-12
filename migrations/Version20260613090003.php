<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613090003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_group_administrator (user_group_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (user_group_id, user_id))');
        $this->addSql('CREATE INDEX IDX_2DF5A7611ED93D47 ON user_group_administrator (user_group_id)');
        $this->addSql('CREATE INDEX IDX_2DF5A761A76ED395 ON user_group_administrator (user_id)');
        $this->addSql('ALTER TABLE user_group_administrator ADD CONSTRAINT FK_2DF5A7611ED93D47 FOREIGN KEY (user_group_id) REFERENCES "user_group" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_group_administrator ADD CONSTRAINT FK_2DF5A761A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON group_subscription (group_identifier) WHERE is_group_channel = true');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_group_administrator DROP CONSTRAINT FK_2DF5A7611ED93D47');
        $this->addSql('ALTER TABLE user_group_administrator DROP CONSTRAINT FK_2DF5A761A76ED395');
        $this->addSql('DROP TABLE user_group_administrator');
        $this->addSql('DROP INDEX uniq_group_official_channel');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_official_channel ON "group_subscription" (group_identifier) WHERE (is_group_channel = true)');
    }
}
