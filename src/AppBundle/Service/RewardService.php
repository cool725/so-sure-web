<?php


namespace AppBundle\Service;

use AppBundle\Document\Reward;
use AppBundle\Repository\RewardRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class RewardService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function getSignUpBonus()
    {
        /** @var RewardRepository $rewardRepo */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        return $rewardRepo->getSignUpBonus();
    }
}
