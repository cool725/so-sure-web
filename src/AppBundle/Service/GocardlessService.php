<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use GoCardlessPro\Environment;
use AppBundle\Document\User;
use AppBundle\Document\Gocardless;
use AppBundle\Document\Policy;
use AppBundle\Service\SequenceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;

class GocardlessService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var Client */
    protected $client;

    /** @var PolicyService */
    protected $policyService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param PolicyService   $policyService
     * @param string          $accessToken
     * @param boolean         $prod
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        $accessToken,
        $prod
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new Client([
            'access_token' => $accessToken,
            'environment' => $prod ? Environment::LIVE : Environment::SANDBOX
        ]);
        $this->policyService = $policyService;
    }

    /**
     * @param Policy $policy
     * @param string $accountFirstName
     * @param string $accountLastName
     * @param string $sortCode
     * @param string $accountNumber
     */
    public function add(Policy $policy, $accountFirstName, $accountLastName, $sortCode, $accountNumber)
    {
        $this->createCustomer($policy->getUser(), $accountFirstName, $accountLastName);
        $this->addBankAccount($policy->getUser(), $sortCode, $accountNumber);
        $this->createMandate($policy);
        $this->subscribe($policy);
        $this->policyService->create($policy);
    }

    /**
     * Only public for testing
     */
    public function createCustomer(User $user, $accountFirstName, $accountLastName, $idempotent = true)
    {
        if (!$user->hasValidGocardlessDetails()) {
            throw new \InvalidArgumentException(sprintf(
                'User is missing details such as name or billing address (User: %s)',
                $user->getId()
            ));
        }

        if ($user->hasGocardless() && $user->getGocardless()->getCustomerId()) {
            return;
        }

        $billing = $user->getBillingAddress();
        $data = [
            "email" => $user->getEmailCanonical(),
            "given_name" => $accountFirstName,
            "family_name" => $accountLastName,
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
        $headers = [];
        if ($idempotent) {
            $headers['Idempotency-Key'] = sprintf('create-%s', $user->getId());
        }

        $customer = $this->client->customers()->create([
            'params' => $data,
            'headers' => $headers,
        ]);

        // TODO: If $idempotent and 409 idempotent_creation_conflict occurs, query customer

        if (!$user->hasGocardless()) {
            $gocardless = new Gocardless();
            $user->setGocardless($gocardless);
            $this->dm->persist($gocardless);
        }
        $user->getGocardless()->setCustomerId($customer->id);
        $this->dm->flush();
        /*
        try {
        } catch (\GoCardlessPro\Core\Exception\ApiException $e) {
          // Api request failed / record couldn't be created.
        } catch (\GoCardlessPro\Core\Exception\MalformedResponseException $e) {
          // Unexpected non-JSON response
        } catch (\GoCardlessPro\Core\Exception\ApiConnectionException $e) {
          // Network error
        }
        */
    }

    /**
     * Only public for testing
     */
    public function addBankAccount(User $user, $sortCode, $accountNumber, $idempotent = true)
    {
        if (!$user->hasGocardless() || !$user->getGocardless()->getCustomerId()) {
            throw new \InvalidArgumentException(sprintf(
                'User requires a gocardless customer account (User: %s)',
                $user->getId()
            ));
        }

        // For now, only allow 1 account
        if ($user->getGocardless()->hasPrimaryAccount()) {
            return;
        }

        $data = [
            "account_number" => $accountNumber,
            "branch_code" => $sortCode, // no need to remove -
            "account_holder_name" => $user->getName(),
            "country_code" => "GB",
            "links" => [
                "customer" => $user->getGocardless()->getCustomerId(),
            ],
        ];
        $headers = [];
        if ($idempotent) {
            $headers['Idempotency-Key'] = sprintf('account-%s', $user->getId());
        }

        $bankAccount = $this->client->customerBankAccounts()->create([
            'params' => $data,
            'headers' => $headers,
        ]);

        // TODO: If $idempotent and 409 idempotent_creation_conflict occurs, query customer bank accounts

        $user->getGocardless()->addAccount($bankAccount->id, json_encode([
            'id' => $bankAccount->id,
            'account_holder_name' => $bankAccount->account_holder_name,
            'account_number_ending' => $bankAccount->account_number_ending,
            'bank_name' => $bankAccount->bank_name,
            'currency' => $bankAccount->currency,
            'account_hash' => sha1(sprintf("%s:%s", str_replace("-", "", $sortCode), $accountNumber)),
        ]));
        $this->dm->flush();
    }

    /**
     * Only public for testing
     */
    public function createMandate(Policy $policy, $idempotent = true)
    {
        $user = $policy->getUser();
        if (!$user->hasGocardless() || !$user->getGocardless()->hasPrimaryAccount()) {
            throw new \InvalidArgumentException(sprintf(
                'Mandate requires a gocardless customer bank account (Policy: %s)',
                $policy->getId()
            ));
        }

        // For now, only allow 1 mandate
        if ($user->getGocardless()->hasMandates()) {
            return;
        }

        $account = $user->getGocardless()->getPrimaryAccount();
        $data = [
            "scheme" => "bacs",
            "links" => [
                "customer_bank_account" => $account->id,
            ],
            "metadata" => [
                "policy" => $policy->getId(),
            ]
        ];
        $headers = [];
        if ($idempotent) {
            $headers['Idempotency-Key'] = sprintf('mandate-%s', $policy->getId());
        }

        $mandate = $this->client->mandates()->create([
            'params' => $data,
            'headers' => $headers,
        ]);

        // TODO: If $idempotent and 409 idempotent_creation_conflict occurs, query customer

        $user->getGocardless()->addMandate($mandate->id, json_encode([
            'id' => $mandate->id,
            'customer_bank_account' => $mandate->links->customer_bank_account,
            'policy' => $policy->getId(),
        ]));
        $policy->setGocardlessMandate($mandate->id);

        $this->dm->flush();
    }

    /**
     */
    public function subscribe(Policy $policy, $idempotent = true)
    {
        $user = $policy->getUser();
        if (!$policy->getGocardlessMandate()) {
            throw new \InvalidArgumentException(sprintf(
                'Subscription requires a gocardless mandate (Policy: %s)',
                $policy->getId()
            ));
        }

        // For now, only allow 1 subscription
        if ($user->getGocardless()->hasSubscription()) {
            return;
        }

        $mandate = $policy->getGocardlessMandate();
        // gocardless wants in pence
        $priceInPence = $policy->getPremium()->getMonthlyPremiumPrice() * 100;
        $data = [
            "amount" => $priceInPence,
            "count" => 12,
            "currency" => "GBP",
            "interval_unit" => "monthly",
            "links" => [
                "mandate" => $mandate,
            ],
            "metadata" => [
                "policy" => $policy->getId(),
            ]
        ];
        $headers = [];
        if ($idempotent) {
            $headers['Idempotency-Key'] = sprintf('subscription-%s', $policy->getId());
        }

        $subscription = $this->client->subscriptions()->create([
            'params' => $data,
            'headers' => $headers,
        ]);

        // TODO: If $idempotent and 409 idempotent_creation_conflict occurs, query customer

        $user->getGocardless()->addSubscription($subscription->id, json_encode([
            'id' => $subscription->id,
            'mandate' => $subscription->links->mandate,
            'policy' => $policy->getId(),
        ]));
        $policy->setGocardlessSubscription($subscription->id);

        $this->dm->flush();
    }
}
