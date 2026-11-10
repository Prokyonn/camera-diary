<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * The web server and the worker share one SQLite file: WAL lets readers work during writes,
 * the busy timeout makes a writer wait instead of failing.
 */
#[AsMiddleware]
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqlitePragmaDriver($driver);
    }
}
