<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Lists the values APP_ENV may take; the kernel refuses to boot with any other environment.
     *
     * Called by KernelTrait, which PHPStan cannot see for a private method overriding a trait method.
     *
     * @return list<string>
     *
     * @phpstan-ignore method.unused
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
