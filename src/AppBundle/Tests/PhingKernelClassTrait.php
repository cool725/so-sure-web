<?php

namespace AppBundle\Tests;

trait PhingKernelClassTrait
{
    /**
     * Avoid parsing command line args to find where app kernel is located
     * Rather assuming that namespace is setup in folders
     */
    public static function getKernelClass()
    {
        $count = count(explode("\\", PhingKernelClassTrait::class));
        $prev = str_repeat("/..", $count);
        $INCLUDE = sprintf('%s%s/app/AppKernel.php', __DIR__, $prev);
        require_once $INCLUDE;

        return "\AppKernel";
    }
}
