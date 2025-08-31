<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250826101115 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification_preference (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, new_challenge TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_A61B1571A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint LONGTEXT NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, encoding VARCHAR(32) NOT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reminder (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, challenge_id INT NOT NULL, scheduled_at_utc DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', recurrence VARCHAR(16) NOT NULL, timezone VARCHAR(64) NOT NULL, active TINYINT(1) NOT NULL, INDEX IDX_40374F40A76ED395 (user_id), INDEX IDX_40374F4098A21AC6 (challenge_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F40A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE reminder ADD CONSTRAINT FK_40374F4098A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_preference DROP FOREIGN KEY FK_A61B1571A76ED395');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F40A76ED395');
        $this->addSql('ALTER TABLE reminder DROP FOREIGN KEY FK_40374F4098A21AC6');
        $this->addSql('DROP TABLE notification_preference');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP TABLE reminder');
    }
}
