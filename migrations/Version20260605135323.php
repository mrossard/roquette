<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605135323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" ADD slug VARCHAR(180) DEFAULT NULL');
        $this->addSql(
            "UPDATE \"user\" SET slug = TRIM(BOTH '-' FROM LOWER(REGEXP_REPLACE(username, '[^a-zA-Z0-9]+', '-', 'g')))",
        );
        $this->addSql('ALTER TABLE "user" ALTER slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_SLUG ON "user" (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_USER_SLUG');
        $this->addSql('ALTER TABLE "user" DROP slug');
    }
}
