<?php

namespace App\Hubspot;

/**
 * Contains a bunch of stuff for some reason.
 * TODO: come back to this and see if I can delete it or move it somewhere better.
 */
final class Api
{
    // sosure_lifecycle_stage options
    const QUOTE = 'quote';
    const READY_FOR_PURCHASE = 'ready for purchase';
    const PURCHASED = 'purchased';
    const RENEWED = 'renewed';
    const CANCELLED = 'cancelled';
    const EXPIRED = 'expired';
    // keep this ordering
    static private $sosureLifecycleStage = [
        self::QUOTE              => 1,
        self::READY_FOR_PURCHASE => 2,
        self::PURCHASED          => 3,
        self::RENEWED            => 4,
        self::CANCELLED          => 5,
        self::EXPIRED            => 6,
    ];

    /**
     * Tells you if a given string is one of the So-Sure lifecycle stages.
     * @param string $option is the string to be checked.
     * @return boolean true iff the given string was a valid licecycle stage.
     */
    public function isValidSosureLifecycleStage(string $option): bool
    {
        return isset(static::$sosureLifecycleStage[$option]);
    }
}
