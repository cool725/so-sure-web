<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\MonitorException;
use AppBundle\Document\Policy;
use AppBundle\Document\MultiPay;
use AppBundle\Document\Claim;

class MonitorService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $redis
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
    }

    public function run($name)
    {
        return call_user_func([$this, $name]);
    }

    public function multipay()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy(['status' => Policy::STATUS_MULTIPAY_REQUESTED]);
        foreach ($policies as $policy) {
            $multipays = $policy->getUser()->getMultiPays();
            foreach ($multipays as $multipay) {
                if ($multipay->getPolicy()->getId() == $policy->getId()
                    && $multipay->getStatus() == MultiPay::STATUS_ACCEPTED) {
                    throw new MonitorException(sprintf(
                        'Policy %s has multipay requested, yet multipay status is approved',
                        $policy->getPolicyNumber()
                    ));
                }
            }
        }

        return sprintf('All multipay requested policies have correct status');
    }

    public function claimsReplacementPhone()
    {
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findMissingReceivedDate();
        $now = new \DateTime();
        foreach ($claims as $claim) {
            $replacementDate = $claim->getPolicy()->getImeiReplacementDate();
            if (!$replacementDate ||
                $now->getTimestamp() - $replacementDate->getTimestamp() > 3600) {
                throw new \Exception(sprintf(
                    'Claim %s Policy %s is missing replacement phone',
                    $claim->getNumber(),
                    $claim->getPolicy()->getPolicyNumber()
                ));
            }
        }
    }
}
