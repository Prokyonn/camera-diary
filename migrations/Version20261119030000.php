<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An event also lists the other known individuals in its frames, e.g. the household cat next to a person';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE camera_event ADD COLUMN also_seen CLOB DEFAULT '[]' NOT NULL");
    }
}
