<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_MANUFACTURER_PAGES_USPS, ['current', 'same-as-homepage'],
        SixpackService::EXPERIMENT_SCODE_CONTENT, ['current', 'reordered'],
        SixpackService::EXPERIMENT_LANDING_PAGES, ['current', 'new-design'],
    ];

    private static $authExperiments = [
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
