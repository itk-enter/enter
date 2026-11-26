<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the tables feed and source_import of the import history.
 *
 * The migration uses the Schema API of Doctrine, so Doctrine writes the SQL.
 * The body of a feed is a compressed column, which only MariaDB has. Thus that
 * column keeps its own SQL definition.
 */
final class Version20261110142641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added the tables feed and source_import';
    }

    public function up(Schema $schema): void
    {
        $feed = $schema->createTable('feed');
        $feed->addColumn('hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $feed->addColumn('size', Types::INTEGER);
        $feed->addColumn('body', Types::TEXT, ['columnDefinition' => "LONGTEXT COMPRESSED NOT NULL DEFAULT ''"]);
        $feed->addColumn('content_type', Types::STRING, ['length' => 255, 'notnull' => false]);
        $feed->addColumn('first_read_at', Types::DATETIME_IMMUTABLE);
        $feed->setPrimaryKey(['hash']);
        $feed->addOption('charset', 'utf8mb4');

        $sourceImport = $schema->createTable('source_import');
        $sourceImport->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $sourceImport->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $sourceImport->addColumn('status', Types::STRING, ['length' => 16]);
        $sourceImport->addColumn('payload_hash', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $sourceImport->addColumn('upserted', Types::INTEGER, ['notnull' => false]);
        $sourceImport->addColumn('rejected', Types::INTEGER, ['notnull' => false]);
        $sourceImport->addColumn('deleted', Types::INTEGER, ['notnull' => false]);
        $sourceImport->addColumn('failure', Types::TEXT, ['notnull' => false]);
        $sourceImport->addColumn('source_id', Types::STRING, ['length' => 255]);
        $sourceImport->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $sourceImport->addColumn('feed_hash', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $sourceImport->setPrimaryKey(['id']);
        $sourceImport->addIndex(['source_id', 'started_at']);
        $sourceImport->addIndex(['feed_hash']);
        $sourceImport->addForeignKeyConstraint('feed', ['feed_hash'], ['hash']);
        $sourceImport->addOption('charset', 'utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('source_import');
        $schema->dropTable('feed');
    }
}
