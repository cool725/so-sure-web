<?php

namespace AppBundle\Classes;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use CensusBundle\Service\SearchService;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Generates policy report showing picsure stuff.
 */
class PolicyPicSureReport extends PolicyReport
{
    /**
     * Creates the policy picsure report.
     * @param SearchService   $searchService to find user location info.
     * @param DocumentManager $dm            to get repositories.
     * @param DateTimeZone    $tz            the timezone for the report to be in.
     */
    public function __construct(SearchService $searchService, DocumentManager $dm, DateTimeZone $tz)
    {
        parent::__construct($searchService, $dm, $tz);
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
    public function getHeaders()
    {
        return ['Policy Number', 'Start', 'Make/Model/Memory', 'First Login in App', 'PicSure Status'];
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        if ($policy instanceof PhonePolicy) {
            $first = $policy->getUser()->getFirstLoginInApp();
            $this->add(
                $policy->getPolicyNumber(),
                $policy->getStart()->format('Ymd H:i'),
                $policy->getPhone()->__toString(),
                $first ? $first->format('Ymd H:i') : 'N/A',
                $policy->getPicSureStatus()
            );
        }
    }
}
