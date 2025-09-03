<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250830092749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE badge (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, icon VARCHAR(255) DEFAULT NULL, rarity VARCHAR(20) DEFAULT NULL, UNIQUE INDEX UNIQ_FEF0481D77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE badge_unlock (id INT AUTO_INCREMENT NOT NULL, badge_id INT NOT NULL, user_id INT NOT NULL, unlocked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetimetz_immutable)\', INDEX IDX_585813AAF7A2C2FC (badge_id), INDEX IDX_585813AAA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE progression_event (id INT AUTO_INCREMENT NOT NULL, progression_id INT NOT NULL, event_type VARCHAR(12) NOT NULL, meta JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetimetz_immutable)\', INDEX IDX_380A2755AF229C18 (progression_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE xp_ledger (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, delta INT NOT NULL, reason VARCHAR(160) NOT NULL, occurred_at DATETIME NOT NULL COMMENT \'(DC2Type:datetimetz_immutable)\', INDEX IDX_A50678B2A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE badge_unlock ADD CONSTRAINT FK_585813AAF7A2C2FC FOREIGN KEY (badge_id) REFERENCES badge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE badge_unlock ADD CONSTRAINT FK_585813AAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression_event ADD CONSTRAINT FK_380A2755AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE xp_ledger ADD CONSTRAINT FK_A50678B2A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE challenge ADD difficulty SMALLINT DEFAULT 1 NOT NULL, ADD base_points INT DEFAULT 50 NOT NULL, ADD co2_estimate_kg NUMERIC(10, 3) DEFAULT \'0\' NOT NULL, ADD water_estimate_l NUMERIC(10, 3) DEFAULT \'0\' NOT NULL, ADD waste_estimate_kg NUMERIC(10, 3) DEFAULT \'0\' NOT NULL, ADD is_repeatable TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE progression ADD points_awarded INT DEFAULT 0 NOT NULL, ADD repetition_index INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE badge_unlock DROP FOREIGN KEY FK_585813AAF7A2C2FC');
        $this->addSql('ALTER TABLE badge_unlock DROP FOREIGN KEY FK_585813AAA76ED395');
        $this->addSql('ALTER TABLE progression_event DROP FOREIGN KEY FK_380A2755AF229C18');
        $this->addSql('ALTER TABLE xp_ledger DROP FOREIGN KEY FK_A50678B2A76ED395');
        $this->addSql('DROP TABLE badge');
        $this->addSql('DROP TABLE badge_unlock');
        $this->addSql('DROP TABLE progression_event');
        $this->addSql('DROP TABLE xp_ledger');
        $this->addSql('ALTER TABLE progression DROP points_awarded, DROP repetition_index');
        $this->addSql('ALTER TABLE challenge DROP difficulty, DROP base_points, DROP co2_estimate_kg, DROP water_estimate_l, DROP waste_estimate_kg, DROP is_repeatable');
    }
}
