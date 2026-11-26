<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the table entity_change of the import history.
 *
 * The migration uses the Schema API of Doctrine, so Doctrine writes the SQL.
 */
final class Version20261110143329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Added the table entity_change';
    }

    public function up(Schema $schema): void
    {
        $entityChange = $schema->createTable('entity_change');
        $entityChange->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entityChange->addColumn('entity_id', Types::STRING, ['length' => 255]);
        $entityChange->addColumn('type', Types::STRING, ['length' => 16]);
        $entityChange->addColumn('source_import_id', Types::INTEGER);
        $entityChange->setPrimaryKey(['id']);
        $entityChange->addIndex(['entity_id']);
        $entityChange->addIndex(['source_import_id']);
        $entityChange->addForeignKeyConstraint('source_import', ['source_import_id'], ['id']);
        $entityChange->addOption('charset', 'utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('entity_change');
    }
}
