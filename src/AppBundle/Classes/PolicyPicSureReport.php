<?php

namespace AppBundle\Classes;

use AppBundle\Helpers\CsvHelper;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Generates policy report showing picsure stuff.
 */
class PolicyPicSureReport extends PolicyReport
{
    /**
     * Creates the policy picsure report.
     * @param DocumentManager $dm to get repositories.
     * @param DateTimeZone    $tz the timezone for the report to be in.
     */
    public function __construct(DocumentManager $dm, DateTimeZone $tz)
    {
        parent::__construct($dm, $tz);
    }

    /**
     * @inheritDoc
     */
    public function getFile()
    {
        return 'picsure.csv';
    }

    /**
     * @inheritDoc
     */
    public function getType()
    {
        return PolicyReport::TYPE_PICSURE;
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return ['Policy Number', 'Start', 'Make/Model/Memory', 'First Login in App', 'PicSure Status'];
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        $user = $policy->getUser();
        if ($policy instanceof PhonePolicy && $user) {
            $first = $policy->getUser()->getFirstLoginInApp();
            return CsvHelper::line(
                $policy->getPolicyNumber(),
                $policy->getStart()->format('Ymd H:i'),
                $policy->getPhone()->__toString(),
                $first ? $first->format('Ymd H:i') : 'N/A',
                $policy->getPicSureStatus()
            );
        }
        return null;
    }
}
