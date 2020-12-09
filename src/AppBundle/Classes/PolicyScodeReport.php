<?php

namespace AppBundle\Classes;

use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Helpers\CsvHelper;
use Doctrine\ODM\MongoDB\DocumentManager;
use DateTimeZone;

/**
 * Generates policy report showing scodes.
 */
class PolicyScodeReport extends PolicyReport
{
    /**
     * @var ConnectionRepository $connectionRepo
     */
    private $connectionRepo;

    /**
     * Creates the policy picsure report.
     * @param DocumentManager $dm to get repositories.
     * @param DateTimeZone    $tz the timezone for the report to be in.
     */
    public function __construct(DocumentManager $dm, DateTimeZone $tz)
    {
        parent::__construct($dm, $tz);
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
    public function getType()
    {
        return PolicyReport::TYPE_SCODE;
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return ['Scode', 'Scode Type', 'User Id', 'Policy Number', 'Date'];
    }

    /**
     * @inheritDoc
     */
    public function process(Policy $policy)
    {
        $content = '';
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
                $content .= CsvHelper::line(
                    $scode->getCode(),
                    $scode->getType(),
                    $policy->getUser()->getId(),
                    $policy->getPolicyNumber(),
                    $connection->getDate()->format('Y-m-d H:i')
                ) . '\n';
            }
        }
        if ($content == '') {
            return null;
        }
        return $content;
    }
}
