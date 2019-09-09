<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_OLD_VS_NEW_FUNNEL, ['regular-funnel', 'new-funnel'],
    ];

    private static $authExperiments = [];

    /**
     * Examine the groups of experiments available, and return the alternatives available.
     */
    public static function optionsAvailable(string $name): array
    {
        if (isset(self::$unauthExperiments[$name])) {
            return self::$unauthExperiments[$name];
        }
        if (isset(self::$authExperiments[$name])) {
            return self::$authExperiments[$name];
        }

        throw new \UnexpectedValueException("No [default,...alternatives] set for experiment: {$name}");
    }
}
