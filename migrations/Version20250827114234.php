<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250827114234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F40AF229C18');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F40AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id) ON DELETE CASCADE');
        $this->addSql('CREATE UNIQUE INDEX uniq_progression_active ON reminder (progression_id, active)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F40AF229C18');
        $this->addSql('DROP INDEX uniq_progression_active ON reminder');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F40AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id)');
    }
}
