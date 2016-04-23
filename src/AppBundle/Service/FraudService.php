<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use AppBundle\Document\Gocardless;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;

class FraudService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

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

    public function runChecks(Policy $policy)
    {
        return [
            'duplicate_postcode' => count($this->getDuplicatePostcode($policy)),  
        ];
    }

    public function getDuplicatePostcode(Policy $policy)
    {
        $user = $policy->getUser();
        $userRepo = $this->dm->getRepository(User::class);

        return $userRepo->findPostcode($user);
    }
}
