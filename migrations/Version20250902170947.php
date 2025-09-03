<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250902170947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MySQL: garantir au plus 1 reminder ACTIF par progression (index fonctionnel si MySQL>=8.0.13, sinon colonne générée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge CHANGE co2_estimate_kg co2_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE water_estimate_l water_estimate_l NUMERIC(10, 3) DEFAULT '0' NOT NULL, CHANGE waste_estimate_kg waste_estimate_kg NUMERIC(10, 3) DEFAULT '0' NOT NULL");

        $oldIdxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active'
        ");
        if ($oldIdxExists > 0) {
            $this->addSql('DROP INDEX uniq_progression_active ON reminder');
        }

        $version = (string) $this->connection->fetchOne('SELECT VERSION()');

        $num = preg_replace('~[^0-9\.].*$~', '', $version);
        $hasFunctionalIndex = version_compare($num, '8.0.13', '>=');

        if ($hasFunctionalIndex) {
            $newIdxExists = (int) $this->connection->fetchOne("
                SELECT COUNT(1)
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'reminder'
                  AND INDEX_NAME = 'uniq_progression_active_true'
            ");
            if ($newIdxExists === 0) {
                $this->addSql("
                    CREATE UNIQUE INDEX uniq_progression_active_true
                    ON reminder ((CASE WHEN active = 1 THEN progression_id END))
                ");
            }
        } else {
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
                $colType = stripos($progressionType, 'unsigned') !== false ? 'INT UNSIGNED' : 'INT';

                $this->addSql('SET FOREIGN_KEY_CHECKS=0');
                $this->addSql(sprintf("
                    ALTER TABLE reminder
                      ADD COLUMN active_progression_id %s GENERATED ALWAYS AS (
                        CASE WHEN active = 1 THEN progression_id ELSE NULL END
                      ) STORED
                ", $colType));
                $this->addSql('SET FOREIGN_KEY_CHECKS=1');
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
            $this->addSql('SET FOREIGN_KEY_CHECKS=0');
            $this->addSql('ALTER TABLE reminder DROP COLUMN active_progression_id');
            $this->addSql('SET FOREIGN_KEY_CHECKS=1');
        }

        $oldIdxExists = (int) $this->connection->fetchOne("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'reminder'
              AND INDEX_NAME = 'uniq_progression_active'
        ");
        if ($oldIdxExists === 0) {
            $this->addSql('CREATE UNIQUE INDEX uniq_progression_active ON reminder (progression_id, active)');
        }
    }
}
