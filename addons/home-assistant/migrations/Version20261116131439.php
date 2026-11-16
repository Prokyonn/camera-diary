<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116131439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pushes Home Assistant hands over for the AI verdict';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE home_assistant_push (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, updated_at DATETIME DEFAULT NULL, camera VARCHAR(32) NOT NULL, pushed_at DATETIME NOT NULL, with_mute_actions BOOLEAN NOT NULL, tag VARCHAR(120) NOT NULL, notification_group VARCHAR(120) NOT NULL, file_base VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, message VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE INDEX home_assistant_push_camera_pushed_at ON home_assistant_push (camera, pushed_at)');
        $this->addSql('CREATE UNIQUE INDEX home_assistant_push_tag ON home_assistant_push (tag)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE home_assistant_push');
    }
}
