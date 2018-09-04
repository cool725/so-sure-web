<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_EBAY_LANDING => ['homepage', 'ebay-landing'],
        SixpackService::EXPERIMENT_EBAY_LANDING_1 => ['homepage', 'ebay-landing-1'],
        SixpackService::EXPERIMENT_EBAY_LANDING_2 => ['homepage', 'ebay-landing-2'],
    ];

    private static $authExperiments = [];

    /**
     * Examine the groups of experiments available, and return the alternatives available.
     */
    public static function optionsAvailable(string $name): array
    {
        return self::$unauthExperiments[$name]
            ?? self::$authExperiments[$name]
            ?? ['homepage', 'unknown-default'];
    }
}
