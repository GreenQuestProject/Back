<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902170947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unicité d’un seul reminder ACTIF par progression via colonne générée + index unique';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL");

        $idxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active'
        ");
        if ($idxExists > 0) {
            $this->addSql('DROP INDEX uniq_progression_active ON reminder');
        }

        $colExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND COLUMN_NAME = 'active_progression_id'
        ");

        if ($colExists === 0) {
            $progressionType = (string) $this->connection->fetchOne("
                SELECT COLUMN_TYPE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'reminder'
                  AND COLUMN_NAME = 'progression_id'
            ");
            $isUnsigned = stripos($progressionType, 'unsigned') !== false;
            $colType = $isUnsigned ? 'INT UNSIGNED' : 'INT';

            $this->addSql(sprintf("
                ALTER TABLE reminder
                  ADD COLUMN active_progression_id %s GENERATED ALWAYS AS (
                    CASE WHEN active = 1 THEN progression_id ELSE NULL END
                  ) STORED
            ", $colType));
        }

        $newIdxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active_true'
        ");
        if ($newIdxExists === 0) {
            $this->addSql('CREATE UNIQUE INDEX uniq_progression_active_true ON reminder (active_progression_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0.000' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0.000' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0.000' NOT NULL");

        $newIdxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active_true'
        ");
        if ($newIdxExists > 0) {
            $this->addSql('DROP INDEX uniq_progression_active_true ON reminder');
        }

        $colExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND COLUMN_NAME = 'active_progression_id'
        ");
        if ($colExists > 0) {
            $this->addSql('ALTER TABLE reminder DROP COLUMN active_progression_id');
        }

        $idxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active'
        ");
        if ($idxExists === 0) {
            $this->addSql('CREATE UNIQUE INDEX uniq_progression_active ON reminder (progression_id, active)');
        }
    }
}
