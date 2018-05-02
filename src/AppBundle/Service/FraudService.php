<?php
namespace AppBundle\Service;

use AppBundle\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
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
        $data = [
            'duplicate_postcode' => $this->getDuplicatePostcode($policy),
            'duplicate_bank_accounts' => $this->getDuplicateBankAccounts($policy),
            'network_cancellations' => count($policy->getNetworkCancellations()),
        ];

        $user = $policy->getUser();
        if (!$user->getIdentityLog()) {
            $data['duplicate_ip'] = 'Unknown signup ip';
            $data['signup_country'] = 'Unknown signup ip';
        } else {
            $data['duplicate_ip'] = $this->getDuplicateIp($policy);
            $data['signup_country'] = $user->getIdentityLog()->getCountry();
        }

        return $data;
    }

    public function getDuplicatePostcode(Policy $policy)
    {
        $user = $policy->getUser();
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);

        return $userRepo->getDuplicatePostcodeCount($user);
    }

    public function getDuplicateBankAccounts(Policy $policy)
    {
        try {
            $user = $policy->getUser();
            /** @var UserRepository $userRepo */
            $userRepo = $this->dm->getRepository(User::class);

            return count($userRepo->findBankAccount($user));
        } catch (\Exception $e) {
            return 'N/A (Unknown payment type)';
        }
    }

    public function getDuplicateIp(Policy $policy)
    {
        $user = $policy->getUser();
        if (!$user->getIdentityLog()) {
            return null;
        }
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $users = $userRepo->findIp($user);

        return count($users);
    }
}
