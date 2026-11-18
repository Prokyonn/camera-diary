<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Questions are kept with their answers, and the AI summarizes a day on request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asked_question (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, question CLOB NOT NULL, answer CLOB NOT NULL, event_ids CLOB NOT NULL, asked_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX asked_question_asked_at ON asked_question (asked_at)');
        $this->addSql('CREATE TABLE day_digest (day VARCHAR(10) NOT NULL, text CLOB NOT NULL, event_count INTEGER NOT NULL, model VARCHAR(64) NOT NULL, written_at DATETIME NOT NULL, PRIMARY KEY (day))');
    }
}
