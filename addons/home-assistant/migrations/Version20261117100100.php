<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Detection types are configurable names of up to 32 characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__home_assistant_detection AS SELECT id, camera, type, detected_at, event_id FROM home_assistant_detection');
        $this->addSql('DROP TABLE home_assistant_detection');
        $this->addSql('CREATE TABLE home_assistant_detection (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, camera VARCHAR(32) NOT NULL, type VARCHAR(32) NOT NULL, detected_at DATETIME NOT NULL, event_id INTEGER DEFAULT NULL, CONSTRAINT FK_7FF1A4D171F7E88B FOREIGN KEY (event_id) REFERENCES camera_event (id) ON UPDATE NO ACTION ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO home_assistant_detection (id, camera, type, detected_at, event_id) SELECT id, camera, type, detected_at, event_id FROM __temp__home_assistant_detection');
        $this->addSql('DROP TABLE __temp__home_assistant_detection');
        $this->addSql('CREATE INDEX IDX_7FF1A4D171F7E88B ON home_assistant_detection (event_id)');
        $this->addSql('CREATE UNIQUE INDEX home_assistant_detection_unique ON home_assistant_detection (camera, type, detected_at)');
        $this->addSql('CREATE INDEX home_assistant_detection_detected_at ON home_assistant_detection (detected_at)');
    }
}
