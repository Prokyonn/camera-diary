<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The cause of a false alarm can be corrected by hand';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN false_alarm_cause_override VARCHAR(32) DEFAULT NULL');
    }
}
