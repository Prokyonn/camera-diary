<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116130832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The AI flags unusual events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN unusual BOOLEAN DEFAULT 0 NOT NULL');
    }
}
