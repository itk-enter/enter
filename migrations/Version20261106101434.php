<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261106101434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added the tables of the import history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE feed (hash CHAR(64) NOT NULL, body LONGTEXT COMPRESSED NOT NULL, content_type VARCHAR(255) DEFAULT NULL, size INT NOT NULL, first_read_at DATETIME NOT NULL, PRIMARY KEY (hash)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE source_import (id INT AUTO_INCREMENT NOT NULL, source_id VARCHAR(255) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, entity_hash CHAR(64) DEFAULT NULL, upserted INT DEFAULT NULL, rejected INT DEFAULT NULL, deleted INT DEFAULT NULL, failure LONGTEXT DEFAULT NULL, feed_hash CHAR(64) DEFAULT NULL, INDEX IDX_2BB1EBFB953C1C61D46F4E3 (source_id, started_at), INDEX IDX_2BB1EBFB512F89EF (feed_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE source_import ADD CONSTRAINT FK_2BB1EBFB512F89EF FOREIGN KEY (feed_hash) REFERENCES feed (hash)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE source_import DROP FOREIGN KEY FK_2BB1EBFB512F89EF');
        $this->addSql('DROP TABLE feed');
        $this->addSql('DROP TABLE source_import');
    }
}
