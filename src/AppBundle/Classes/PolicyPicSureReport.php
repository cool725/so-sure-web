<?php

namespace AppBundle\Classes;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use DateTimeZone;
use CensusBundle\Service\SearchService;
use Doctrine\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Generates policy report showing picsure stuff.
 */
class PolicyPicSureReport extends PolicyReport
{
    /**
     * Creates the policy picsure report.
     * @param SearchService   $searchService provides geographical information about users.
     * @param DocumentManager $dm            for the report to use.
     * @param DateTimeZone    $tz            is the time zone to report in.
     */
    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        SearchService $searchService,
        DocumentManager $dm,
        DateTimeZone $tz,
        LoggerInterface $logger
    ) {
        parent::__construct($searchService, $dm, $tz, $logger);
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

    public function processBatch(array $policy)
    {
        // TODO: Implement processBatch() method.
    }
}
