<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use GoCardlessPro\Environment;
use AppBundle\Document\User;
use AppBundle\Document\Gocardless;
use AppBundle\Document\Policy;
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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $accessToken
     * @param boolean         $prod
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger, $accessToken, $prod)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new Client([
            'access_token' => $accessToken,
            'environment' => $prod ? Environment::LIVE : Environment::SANDBOX
        ]);
    }

    /**
     * @param Policy $policy
     * @param string $sortcode
     * @param string $account
     */
    public function add(Policy $policy, $sortcode, $account)
    {
        $this->createCustomer($policy->getUser());
        $this->addBankAccount($policy->getUser(), $sortcode, $account);
        $this->createMandate($policy);
    }

    /**
     * Only public for testing
     */
    public function createCustomer(User $user, $idempotent = true)
    {
        if (!$user->hasValidGocardlessDetails()) {
            throw new \InvalidArgumentException('User is missing details such as name or billing address');
        }

        if ($user->hasGocardless() && $user->getGocardless()->getCustomerId()) {
            return;
        }

        $billing = $user->getBillingAddress();
        $data = [
            "email" => $user->getEmailCanonical(),
            "given_name" => $user->getFirstName(),
            "family_name" => $user->getLastName(),
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
    public function addBankAccount(User $user, $sortcode, $account, $idempotent = true)
    {
        if (!$user->hasGocardless() || !$user->getGocardless()->getCustomerId()) {
            throw new \InvalidArgumentException('User requires a gocardless customer account');
        }

        // For now, only allow 1 account
        if ($user->getGocardless()->hasPrimaryAccount()) {
            return;
        }

        $data = [
            "account_number" => $account,
            "branch_code" => $sortcode,
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
            throw new \InvalidArgumentException('Mandate requires a gocardless customer bank account');
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
            throw new \InvalidArgumentException('Subscription requires a gocardless mandate');
        }

        // For now, only allow 1 subscription
        // TODO: Check for subscription

        $mandate = $policy->getGocardlessMandate();
        $data = [
            "amount" => $policy->getPhone()->getTotalPrice(),
            "count" => 12,
            "current" => "GBP",
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
            $headers['Idempotency-Key'] = sprintf('mandate-%s', $policy->getId());
        }

        $mandate = $this->client->subscriptions()->create([
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
}
