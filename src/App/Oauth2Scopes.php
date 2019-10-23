<?php
namespace App;

/*
 * All scopes must be listed in config.yml: fos_oauth_server.service.options.supported_scopes:
 */
final class Oauth2Scopes
{
    /*public */const USER_STARLING_SUMMARY = 'user.starling.summary';
    /*public */const USER_STARLING_BUSINESS_SUMMARY = 'user.starling_business.summary';
    private static $descriptions = [
        self::USER_STARLING_SUMMARY => [
            'intro' => 'Starling Bank would like access to your policy details:',
            'points' => [
                'Policy number & end-dates',
                'Make & model of phone',
                'Current value of your reward pot'
            ],
        ],
        self::USER_STARLING_BUSINESS_SUMMARY => [
            'intro' => 'Starling Bank would like access to your policy details:',
            'points' => [
                'Policy number & end-dates',
                'Make & model of phone',
                'Current value of your reward pot'
            ],
        ],

    ];

    /**
     * Return an array of [intro => '....', points => ['...', '...'] ]
     */
    public static function scopeToDescription(string $scopeName): array
    {
        return self::$descriptions[$scopeName] ?? [];
    }
}
