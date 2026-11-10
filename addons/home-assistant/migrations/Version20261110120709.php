<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Home Assistant detections, signals and import cursor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE home_assistant_detection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, camera VARCHAR(32) NOT NULL, type VARCHAR(16) NOT NULL, detected_at DATETIME NOT NULL, event_id INTEGER DEFAULT NULL, CONSTRAINT FK_7FF1A4D171F7E88B FOREIGN KEY (event_id) REFERENCES camera_event (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX home_assistant_detection_detected_at ON home_assistant_detection (detected_at)');
        $this->addSql('CREATE UNIQUE INDEX home_assistant_detection_unique ON home_assistant_detection (camera, type, detected_at)');
        $this->addSql('CREATE INDEX IDX_7FF1A4D171F7E88B ON home_assistant_detection (event_id)');
        $this->addSql('CREATE TABLE home_assistant_import_cursor (name VARCHAR(32) NOT NULL, imported_until DATETIME NOT NULL, PRIMARY KEY (name))');
        $this->addSql('CREATE TABLE home_assistant_signal (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, entity_id VARCHAR(255) NOT NULL, state VARCHAR(64) NOT NULL, changed_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX home_assistant_signal_changed_at ON home_assistant_signal (changed_at)');
        $this->addSql('CREATE UNIQUE INDEX home_assistant_signal_unique ON home_assistant_signal (entity_id, state, changed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE home_assistant_detection');
        $this->addSql('DROP TABLE home_assistant_import_cursor');
        $this->addSql('DROP TABLE home_assistant_signal');
    }
}
