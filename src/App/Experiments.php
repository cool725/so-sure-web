<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_MANUFACTURER_PAGES_USPS, ['current', 'same-as-homepage'],
        SixpackService::EXPERIMENT_MARKETING_HOMEPAGE,
        ['control', 'new-design-new-copy'],
        SixpackService::EXPERIMENT_HOMEPAGE_DESIGN_V3_ON_HOME,
        ['control', 'curent-new-copy', 'new-design-old-copy', 'new-design-new-copy'],
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
