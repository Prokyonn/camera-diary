<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The AI says how sure it is, and the owner reviews unsure events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE camera_event ADD COLUMN confidence VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE camera_event ADD COLUMN reviewed_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE camera_event SET reviewed_at = described_at WHERE label IS NOT NULL OR verdict_override IS NOT NULL OR individual_override IS NOT NULL');
    }
}
