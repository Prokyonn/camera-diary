<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Stored times are UTC: Doctrine's datetime types keep the clock time without a zone and read it back in PHP's
     * default zone, so that must not depend on the php.ini. The home's own zone is APP_TIMEZONE (HomeTimeZone).
     */
    public function boot(): void
    {
        date_default_timezone_set('UTC');

        parent::boot();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
