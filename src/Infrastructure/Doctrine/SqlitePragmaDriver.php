<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

final class SqlitePragmaDriver extends AbstractDriverMiddleware
{
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $connection = parent::connect($params);
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }
}
