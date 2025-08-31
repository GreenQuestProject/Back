<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250828075910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE push_subscription ADD endpoint_hash VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_562830F3867498CF ON push_subscription (endpoint_hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_562830F3867498CF ON push_subscription');
        $this->addSql('ALTER TABLE push_subscription DROP endpoint_hash');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_562830F3C4420F7B ON push_subscription (endpoint)');
    }
}
