<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The known individual of an event can be corrected by hand';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN individual_override VARCHAR(32) DEFAULT NULL');
    }
}
