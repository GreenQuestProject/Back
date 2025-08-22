<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250822191235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE user_notification_settings DROP FOREIGN KEY FK_7051D51EA76ED395');
        $this->addSql('ALTER TABLE challenge_reminder DROP FOREIGN KEY FK_DD09A8A098A21AC6');
        $this->addSql('ALTER TABLE challenge_reminder DROP FOREIGN KEY FK_DD09A8A0A76ED395');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE user_notification_settings');
        $this->addSql('DROP TABLE challenge_reminder');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON user (email)');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_identifier_username TO UNIQ_8D93D649F85E0677');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, payload JSON NOT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BF5476CAA76ED395 (user_id), INDEX IDX_BF5476CA8B8E8428 (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE user_notification_settings (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, receive_new_challenge_notifications TINYINT(1) DEFAULT 1 NOT NULL, timezone VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT \'Europe/Paris\' NOT NULL COLLATE `utf8mb4_unicode_ci`, preferred_reminder_channel VARCHAR(16) CHARACTER SET utf8mb4 DEFAULT \'email\' NOT NULL COLLATE `utf8mb4_unicode_ci`, UNIQUE INDEX UNIQ_7051D51EA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE challenge_reminder (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, challenge_id INT NOT NULL, remind_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', channel VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, status VARCHAR(16) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_DD09A8A0A76ED395 (user_id), INDEX IDX_DD09A8A098A21AC6 (challenge_id), INDEX IDX_DD09A8A0BBE50DA37B00651C (remind_at, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint VARCHAR(512) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, push_subscription_keys JSON NOT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_endpoint (endpoint), INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE user_notification_settings ADD CONSTRAINT FK_7051D51EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE challenge_reminder ADD CONSTRAINT FK_DD09A8A098A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE challenge_reminder ADD CONSTRAINT FK_DD09A8A0A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX UNIQ_IDENTIFIER_EMAIL ON user');
        $this->addSql('ALTER TABLE user RENAME INDEX uniq_8d93d649f85e0677 TO UNIQ_IDENTIFIER_USERNAME');
    }
}
