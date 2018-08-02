<?php
namespace App;

final class Oauth2Scopes
{
    /*public */const USER_STARLING_SUMMARY = 'user.starling.summary';
    private static $descriptions = [
        self::USER_STARLING_SUMMARY => [
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
