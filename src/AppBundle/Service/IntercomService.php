<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;
use Intercom\IntercomClient;

class IntercomService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var IntercomClient */
    protected $client;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $token
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $token
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new IntercomClient($token, null);
    }
    
    public function update(User $user)
    {
        if ($user->hasValidPolicy()) {
            $resp = $this->updateUser($user);
        } else {
            $resp = $this->updateLead($user);
        }
        
        return $resp;
    }
    
    private function updateUser(User $user)
    {
        $data = array(
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'signed_up_at' => $user->getCreated()->getTimestamp(),
        );

        $policyValue = 0;
        $pot = 0;
        $connections = 0;
        foreach ($user->getValidPolicies() as $policy) {
            $policyValue += $policy->getPremium()->getYearlyPremiumPrice();
            $pot += $policy->getPotValue();
            $connections += count($policy->getConnections());
        }

        $data['custom_attributes']['premium'] = $policyValue;
        $data['custom_attributes']['pot'] = $pot;
        $data['custom_attributes']['connections'] = $connections;
        $data['custom_attributes']['promo_code'] = $user->isPreLaunch() ? 'launch' : '';

        // optout

        $resp = $this->client->users->create($data);

        return $resp;
    }
    
    private function updateLead(User $user)
    {
        $resp = $this->client->leads->create(array(
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
        ));

        return $resp;
    }
}
