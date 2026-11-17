<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurable vocabularies and AI call pools';
    }

    /**
     * SQLite changes column types by rebuilding the table. Foreign keys stay off meanwhile, or dropping camera_event
     * would clear the event links of the Home Assistant detections; PRAGMA foreign_keys has no effect in a transaction.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_pool_usage (day DATE NOT NULL, pool VARCHAR(32) NOT NULL, calls INTEGER NOT NULL, PRIMARY KEY (day, pool))');

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __temp__camera_event AS SELECT id, ended_at, detection_types, detection_count, hints, status, attempts, next_attempt_at, frame_paths, frame_width, frame_height, frames_removed_at, verdict, false_alarm_cause, side, subject, summary, model, described_at, label, verdict_override, started_at, source, external_id, open, camera, unusual FROM camera_event');
        $this->addSql('DROP TABLE camera_event');
        $this->addSql('CREATE TABLE camera_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, ended_at DATETIME NOT NULL, detection_types CLOB NOT NULL, detection_count INTEGER NOT NULL, hints CLOB NOT NULL, status VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL, next_attempt_at DATETIME DEFAULT NULL, frame_paths CLOB NOT NULL, frame_width INTEGER DEFAULT NULL, frame_height INTEGER DEFAULT NULL, frames_removed_at DATETIME DEFAULT NULL, verdict VARCHAR(16) DEFAULT NULL, false_alarm_cause VARCHAR(32) DEFAULT NULL, side VARCHAR(16) DEFAULT NULL, subject VARCHAR(32) DEFAULT NULL, summary CLOB DEFAULT NULL, model VARCHAR(64) DEFAULT NULL, described_at DATETIME DEFAULT NULL, label VARCHAR(120) DEFAULT NULL, verdict_override VARCHAR(16) DEFAULT NULL, started_at DATETIME NOT NULL, source VARCHAR(32) NOT NULL, external_id VARCHAR(120) DEFAULT NULL, open BOOLEAN NOT NULL, camera VARCHAR(32) NOT NULL, unusual BOOLEAN DEFAULT 0 NOT NULL, CONSTRAINT FK_444EEC603B1CEE05 FOREIGN KEY (camera) REFERENCES camera (slug) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql("INSERT INTO camera_event (id, ended_at, detection_types, detection_count, hints, status, attempts, next_attempt_at, frame_paths, frame_width, frame_height, frames_removed_at, verdict, false_alarm_cause, side, subject, summary, model, described_at, label, verdict_override, started_at, source, external_id, open, camera, unusual) SELECT id, ended_at, detection_types, detection_count, hints, status, attempts, next_attempt_at, frame_paths, frame_width, frame_height, frames_removed_at, verdict, NULLIF(false_alarm_cause, 'none'), side, subject, summary, model, described_at, label, verdict_override, started_at, source, external_id, open, camera, unusual FROM __temp__camera_event");
        $this->addSql('DROP TABLE __temp__camera_event');
        $this->addSql('CREATE INDEX IDX_444EEC603B1CEE05 ON camera_event (camera)');
        $this->addSql('CREATE UNIQUE INDEX camera_event_external_id ON camera_event (source, external_id)');
        $this->addSql('CREATE INDEX camera_event_status_started ON camera_event (status, started_at)');
        $this->addSql('CREATE INDEX camera_event_camera_started ON camera_event (camera, started_at)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function postUp(Schema $schema): void
    {
        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
        $this->abortIf([] !== $violations, 'Foreign key violations after rebuilding camera_event.');
    }
}
