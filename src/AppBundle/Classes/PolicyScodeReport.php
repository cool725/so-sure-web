<?php

namespace AppBundle\Classes;

use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use CensusBundle\Service\SearchService;
use AppBundle\Repository\ConnectionRepository;
use DateTimeZone;
use Doctrine\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Generates policy report showing scodes.
 */
class PolicyScodeReport extends PolicyReport
{
    /**
     * @var ConnectionRepository $connectionRepo
     */
    private $connectionRepo;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * Creates the policy picsure report.
     * @param SearchService   $searchService provides geographical information about users.
     * @param DocumentManager $dm            for the report to use.
     * @param DateTimeZone    $tz            is the time zone to report in.
     */
    public function __construct(
        SearchService $searchService,
        DocumentManager $dm,
        DateTimeZone $tz,
        LoggerInterface $logger
    ) {
        parent::__construct($searchService, $dm, $tz, $logger);
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $dm->getRepository(Connection::class);
        $this->connectionRepo = $connectionRepo;
    }

    /**
     * @inheritDoc
     */
    public function getFile()
    {
        return 'scodes.csv';
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return ["Scode", "Scode Type", "User Id", "Policy Number", "Date"];
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        foreach ($policy->getSCodes() as $scode) {
            $type = $scode->getType();
            $connection = null;
            if ($type == Scode::TYPE_REWARD) {
                $rewardUser = $scode->getReward()->getUser();
                $connection = $this->connectionRepo->findByUser($rewardUser, $policy);
            } elseif ($type == Scode::TYPE_STANDARD) {
                $other = $scode->getPolicy();
                $connection = $this->connectionRepo->connectedByPolicy($policy, $other);
            }
            if ($connection) {
                $this->add(
                    $scode->getCode(),
                    $scode->getType(),
                    $policy->getUser()->getId(),
                    $policy->getPolicyNumber(),
                    $connection->getDate()->format("Y-m-d H:i")
                );
            }
        }
    }

    public function processBatch(array $policy)
    {
        // TODO: Implement processBatch() method.
    }
}
