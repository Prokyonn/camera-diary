<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A repeated false alarm is filed without an AI call and points to the event the AI checked';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN repeat_of INTEGER DEFAULT NULL');
    }
}
