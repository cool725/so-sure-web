<?php

namespace App;

use AppBundle\Service\SixpackService;

final class Experiments
{
    private static $unauthExperiments = [
        SixpackService::EXPERIMENT_SOCIAL_AD_LANDING => ['ad-landing-quotepage-homepage', 'ad-landing-quotepage'],
        SixpackService::EXPERIMENT_SCODE_LANDING_TEXT => ['scode-landing-text-a', 'scode-landing-text-b'],
        SixpackService::EXPERIMENT_EMAIL_LANDING_TEXT => ['email-landing-text-a', 'email-landing-text-b'],
        SixpackService::EXPERIMENT_HOMEPAGE_USPS => ['homepage', 'homepage-usps'],
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
