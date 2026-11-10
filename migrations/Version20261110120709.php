<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cameras, camera events and the AI call budget';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (day DATE NOT NULL, calls INTEGER NOT NULL, PRIMARY KEY (day))');
        $this->addSql('CREATE TABLE camera (name VARCHAR(120) NOT NULL, scene CLOB DEFAULT NULL, slug VARCHAR(32) NOT NULL, PRIMARY KEY (slug))');
        $this->addSql('CREATE TABLE camera_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ended_at DATETIME NOT NULL, detection_types CLOB NOT NULL, detection_count INTEGER NOT NULL, hints CLOB NOT NULL, status VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL, next_attempt_at DATETIME DEFAULT NULL, frame_paths CLOB NOT NULL, frame_width INTEGER DEFAULT NULL, frame_height INTEGER DEFAULT NULL, frames_removed_at DATETIME DEFAULT NULL, verdict VARCHAR(16) DEFAULT NULL, false_alarm_cause VARCHAR(16) DEFAULT NULL, side VARCHAR(16) DEFAULT NULL, subject VARCHAR(16) DEFAULT NULL, summary CLOB DEFAULT NULL, model VARCHAR(64) DEFAULT NULL, described_at DATETIME DEFAULT NULL, label VARCHAR(120) DEFAULT NULL, verdict_override VARCHAR(16) DEFAULT NULL, started_at DATETIME NOT NULL, source VARCHAR(32) NOT NULL, external_id VARCHAR(120) DEFAULT NULL, open BOOLEAN NOT NULL, camera VARCHAR(32) NOT NULL, CONSTRAINT FK_444EEC603B1CEE05 FOREIGN KEY (camera) REFERENCES camera (slug) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX camera_event_camera_started ON camera_event (camera, started_at)');
        $this->addSql('CREATE INDEX camera_event_status_started ON camera_event (status, started_at)');
        $this->addSql('CREATE UNIQUE INDEX camera_event_external_id ON camera_event (source, external_id)');
        $this->addSql('CREATE INDEX IDX_444EEC603B1CEE05 ON camera_event (camera)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_usage');
        $this->addSql('DROP TABLE camera');
        $this->addSql('DROP TABLE camera_event');
    }
}
