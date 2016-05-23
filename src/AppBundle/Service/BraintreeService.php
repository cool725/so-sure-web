<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;
use Braintree_Customer;

class BraintreeService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var PolicyService */
    protected $policyService;

    /** @var Braintree_Transaction */
    protected $transactionService;

    /**
     * @param DocumentManager       $dm
     * @param LoggerInterface       $logger
     * @param PolicyService         $policyService
     * @param Braintree_Transaction $transactionService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        Braintree_Transaction $transactionService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->transactionService = $transactionService;
    }

    /**
     * @param Policy $policy
     */
    public function add(Policy $policy)
    {
        $this->sale($policy);
        $this->policyService->create($policy, $policy->getUser());
    }

    /**
     * Only public for testing
     */
    public function sale(Policy $policy)
    {
        $user = $policy->getUser();
        if (!$user->hasValidDetails()) {
            throw new \InvalidArgumentException(sprintf(
                'User is missing details such as name or email address (User: %s)',
                $user->getId()
            ));
        }

        if (!$user->hasValidBillingDetails()) {
            throw new \InvalidArgumentException(sprintf(
                'User is missing details such as billing address (User: %s)',
                $user->getId()
            ));
        }

        $billing = $user->getBillingAddress();
        $data = [
            "email" => $user->getEmailCanonical(),
            "firstName" => $user->getFirstName(),
            "lastName" => $user->getLastName(),
            "address_line1" => $billing->getLine1(),
            "address_line2" => $billing->getLine2(),
            "address_line3" => $billing->getLine3(),
            "city" => $billing->getCity(),
            "postal_code" => $billing->getPostcode(),
            "country_code" => "GB",
            "metadata" => [
                "id" => $user->getId(),
            ]
        ];

        $results = $this->transactionService->sale($data);
    }
}
