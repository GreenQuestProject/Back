<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250827075958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F4098A21AC6');
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F40A76ED395');
        $this->addSql('DROP INDEX IDX_40374F4098A21AC6 ON reminder');
        $this->addSql('DROP INDEX IDX_40374F40A76ED395 ON reminder');
        $this->addSql('ALTER TABLE reminder ADD progression_id INT NOT NULL, DROP user_id, DROP challenge_id');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F40AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id)');
        $this->addSql('CREATE INDEX IDX_40374F40AF229C18 ON reminder (progression_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F40AF229C18');
        $this->addSql('DROP INDEX IDX_40374F40AF229C18 ON reminder');
        $this->addSql('ALTER TABLE reminder ADD challenge_id INT NOT NULL, CHANGE progression_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F4098A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id)');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F40A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_40374F4098A21AC6 ON reminder (challenge_id)');
        $this->addSql('CREATE INDEX IDX_40374F40A76ED395 ON reminder (user_id)');
    }
}
