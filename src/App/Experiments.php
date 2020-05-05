<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_EMAIL_OPTIONAL, ['email-optional', 'email'],
    ];

    private static $authExperiments = [
        SixpackService::EXPERIMENT_PERSONALISED_QUOTE_PAGE_V2, ['name', 'time'],
    ];

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
