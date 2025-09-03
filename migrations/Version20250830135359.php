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
        // 1) challenge : resserrage avec defaults
        $this->addSql("ALTER TABLE challenge
        CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL,
        CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0' NOT NULL,
        CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL");

        // 2) user.created_at : idempotent & compatible strict mode
        $this->addSql("SET @has_col := (
      SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'created_at'
    )");
        $this->addSql("SET @sql := IF(@has_col = 0,
      'ALTER TABLE `user` ADD `created_at` DATETIME NULL COMMENT ''(DC2Type:datetime_immutable)''',
      'DO 0')");
        $this->addSql("PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt");

        $this->addSql("UPDATE `user` SET `created_at` = NOW() WHERE `created_at` IS NULL");
        $this->addSql("ALTER TABLE `user` MODIFY `created_at` DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }


    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL');
        $this->addSql('ALTER TABLE user DROP created_at');
    }
}
