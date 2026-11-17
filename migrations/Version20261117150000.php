<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The AI names known individuals, e.g. the household cat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN individual VARCHAR(32) DEFAULT NULL');
    }
}
