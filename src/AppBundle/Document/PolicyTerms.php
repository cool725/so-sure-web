<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PolicyTermsRepository")
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

    // change of registered address; tightening terms
    const VERSION_11 = 'Version 11 January 2019';

    // clarify UK or EU for phone
    const VERSION_12 = 'Version 12 February 2019';

    // New Status Disclosure
    const VERSION_13 = 'Version 13 May 2019';

    // Immediate Cancellation
    const VERSION_14 = 'Version 14 October 2019';

    // Version 14 for Aggregators
    const VERSION_14_R = 'Version 14 November 2019 Aggregator';

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
        self::VERSION_11 => '11',
        self::VERSION_12 => '12',
        self::VERSION_13 => '13',
        self::VERSION_14_R => '14_R',
        self::VERSION_14 => '14'
    ];

    public static function getLowExcess()
    {
        $phoneExcess = new PhoneExcess();
        $phoneExcess->setDamage(50);
        $phoneExcess->setWarranty(50);
        $phoneExcess->setExtendedWarranty(50);
        $phoneExcess->setLoss(70);
        $phoneExcess->setTheft(70);

        return $phoneExcess;
    }

    public static function getHighExcess()
    {
        $phoneExcess = new PhoneExcess();
        $phoneExcess->setDamage(150);
        $phoneExcess->setWarranty(150);
        $phoneExcess->setExtendedWarranty(150);
        $phoneExcess->setLoss(150);
        $phoneExcess->setTheft(150);

        return $phoneExcess;
    }

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

    /**
     * For pic-sure required policies
     *
     * @return bool
     */
    public function isPicSureRequired()
    {
        // If terms version with _R appended
        return in_array($this->getVersion(), [
            self::VERSION_14_R,
        ]);
    }

    /**
     * Can we attempt to collect the cost of the phone?
     *
     * @return bool
     */
    public function isFullReImbursementEnabled()
    {
        // assuming that full re-imbursement will always be enabled going forward
        return !in_array($this->getVersion(), [
            self::VERSION_0,
            self::VERSION_1,
            self::VERSION_2,
            self::VERSION_3,
            self::VERSION_4,
            self::VERSION_5,
        ]);
    }

    /**
     * Can we send phone off to repair?
     * "repair or replace your phone within  24 to 72 hours"
     *
     * @return bool
     */
    public function isRepairEnabled()
    {
        // assuming that repair will always be enabled going forward
        return !in_array($this->getVersion(), [
            self::VERSION_0,
            self::VERSION_1,
            self::VERSION_2,
            self::VERSION_3,
            self::VERSION_4,
            self::VERSION_5,
            self::VERSION_6,
            self::VERSION_7,
            self::VERSION_8,
            self::VERSION_9,
        ]);
    }

    /**
     * Tells us whether we should use the global hard coded excesses for these policy terms.
     * @return boolean true if we should use hard coded excess and false if not.
     */
    public function isStaticExcessEnabled()
    {
        return $this->getVersionNumber() < static::getVersionNumberByVersion(self::VERSION_13);
    }

    /**
     * Tells you if users can request cancellation instantly at any time (but always with no refund).
     * @return boolean true if they can be cancelled instantly without refund, and false if you must wait.
     */
    public function isInstantUserCancellationEnabled()
    {
        return $this->getVersionNumber() >= static::getVersionNumberByVersion(self::VERSION_14);
    }

    public function getAllowedExcesses()
    {
        if ($this->isPicSureEnabled()) {
            return [
                static::getHighExcess()
            ];
        } elseif (!$this->isPicSureRequired()) {
            return [
                static::getLowExcess()
            ];
        } else {
            return [];
        }
    }

    public function getAllowedPicSureExcesses()
    {
        if ($this->isPicSureEnabled()) {
            return [
                static::getLowExcess()
            ];
        } else {
            return [];
        }
    }

    /**
     * @return PhoneExcess
     */
    public function getDefaultExcess()
    {
        if ($this->isPicSureEnabled() && !$this->isPicSureRequired()) {
            return static::getHighExcess();
        } else {
            return static::getLowExcess();
        }
    }

    public function getDefaultPicSureExcess()
    {
        if ($this->isPicSureEnabled()) {
            return static::getLowExcess();
        } else {
            return null;
        }
    }

    /**
     * Validates that the given excess is acceptable.
     * @param PhoneExcess|null $excess  is the excess to validate.
     * @param boolean          $picsure is whether to validate for picsure excess or normal excess.
     * @return boolean true if the excess is acceptable and false if not.
     */
    public function isAllowedExcess(PhoneExcess $excess = null, $picsure = false)
    {
        if ($this->isStaticExcessEnabled()) {
            return $this->checkExcess(
                $excess,
                $picsure ? $this->getAllowedPicSureExcesses() : $this->getAllowedExcesses()
            );
        }
        return $excess && $excess->getMin() > 0;
    }

    /**
     * Checks that a given excess is equivalent to one stored within a list of excesses.
     * @param PhoneExcess|null $excess          is the excess to check for.
     * @param array            $allowedExcesses is the list of excesses to check in.
     * @return boolean true if the excess is in the list, and false if not.
     */
    private function checkExcess(PhoneExcess $excess = null, $allowedExcesses = [])
    {
        foreach ($allowedExcesses as $allowed) {
            if ($allowed->equal($excess)) {
                return true;
            }
        }
        return false;
    }
}
