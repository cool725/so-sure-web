<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PolicyTerms extends PolicyDocument
{
    // Prelease initial version
    const VERSION_0 = 'Version 1 May 2016';

    // Initial release version
    const VERSION_1 = 'Version 1 June 2016';

    // Accidental release version that included picure - 3/8 - 16/8 2017
    const VERSION_2 = 'Version 2 Aug 2017';

    // Same as version 2, but reverted excess - what should have been July release
    const VERSION_3 = 'Version 3 Aug 2017';

    // Same as version 2, but released 2 Nov 2017
    const VERSION_4 = 'Version 4 Nov 2017';

    // Same as version 4, but update for GDPR
    const VERSION_5 = 'Version 5 May 2018';

    public static $allVersions = [
        self::VERSION_0,
        self::VERSION_1,
        self::VERSION_2,
        self::VERSION_3,
        self::VERSION_4,
        self::VERSION_5
    ];

    public function getVersionNumber()
    {
        return self::getVersionNumberByVersion($this->version);
    }

    public static function getVersionNumberByVersion($version)
    {
        if (in_array($version, [self::VERSION_0, self::VERSION_1])) {
            return '1';
        } elseif (in_array($version, [self::VERSION_2])) {
            return '2';
        } elseif (in_array($version, [self::VERSION_3])) {
            return '3';
        } elseif (in_array($version, [self::VERSION_4])) {
            return '4';
        } elseif (in_array($version, [self::VERSION_5])) {
            return '5';
        }
        return null;
    }

    public function isPicSureEnabled()
    {
        // assuming that picsure will always be enabled going forward
        return !in_array($this->getVersion(), [
            self::VERSION_0,
            self::VERSION_1,
            self::VERSION_3,
        ]);
    }
}
