<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250830135359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT \'0\' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT \'0\' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE user ADD created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql("UPDATE user SET created_at = NOW() WHERE created_at IS NULL OR created_at = '0000-00-00 00:00:00'");
        $this->addSql("ALTER TABLE user MODIFY created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }


    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL');
        $this->addSql('ALTER TABLE user DROP created_at');
    }
}
