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

    // Minor tweaks for pic-sure, repair, & non-payment full recovery costs
    const VERSION_6 = 'Version 6 May 2018';

    // Minor tweaks for pic-sure
    const VERSION_7 = 'Version 7 July 2018';

    // Minor tweaks for lawyers
    const VERSION_8 = 'Version 8 July 2018';

    // New claims address
    const VERSION_9 = 'Version 9 July 2018';

    // 24-72 hour repair
    const VERSION_10 = 'Version 10 August 2018';

    // ensure that lastest version is last in the array
    public static $allVersions = [
        self::VERSION_0 => '1',
        self::VERSION_1 => '1',
        self::VERSION_2 => '2',
        self::VERSION_3 => '3',
        self::VERSION_4 => '4',
        self::VERSION_5 => '5',
        self::VERSION_6 => '6',
        self::VERSION_7 => '7',
        self::VERSION_8 => '8',
        self::VERSION_9 => '9',
        self::VERSION_10 => '10',
    ];

    public function getVersionNumber()
    {
        return self::getVersionNumberByVersion($this->version);
    }

    public static function getVersionNumberByVersion($version)
    {
        if (in_array($version, array_keys(self::$allVersions))) {
            return self::$allVersions[$version];
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
