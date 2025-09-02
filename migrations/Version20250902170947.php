<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902170947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unicité d’un seul reminder ACTIF par progression en MySQL via colonne générée + index unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL");
        $this->addSql('DROP INDEX IF EXISTS uniq_progression_active ON reminder');
        $this->addSql("
            ALTER TABLE reminder
              ADD COLUMN active_progression_id INT GENERATED ALWAYS AS (
                CASE WHEN active = 1 THEN progression_id ELSE NULL END
              ) STORED
        ");
        $this->addSql('CREATE UNIQUE INDEX uniq_progression_active_true ON reminder (active_progression_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0.000' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0.000' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0.000' NOT NULL");
        $this->addSql('DROP INDEX IF EXISTS uniq_progression_active_true ON reminder');
        $this->addSql('ALTER TABLE reminder DROP COLUMN active_progression_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_progression_active ON reminder (progression_id, active)');
    }
}
