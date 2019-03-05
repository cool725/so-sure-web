<?php

namespace AppBundle\Service;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\QueueTrait;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Exception\Queue\QueueException;
use AppBundle\Exception\Queue\MalformedMessageException;
use AppBundle\Exception\Queue\UnknownMessageException;
use Doctrine\ODM\MongoDB\DocumentManager;
use CensusBundle\Service\SearchService;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use SevenShores\Hubspot\Factory as HubspotFactory;
use SevenShores\Hubspot\Resources\Contacts;
use SevenShores\Hubspot\Resources\DealPipelines;
use SevenShores\Hubspot\Exceptions\BadRequest;
use SevenShores\Hubspot\Http\Response;

/**
 * Provides the primary hubspot functionality.
 */
class HubspotService
{
    use QueueTrait;

    const QUEUE_UPDATE_USER = 'create-user';
    const QUEUE_DELETE_USER = 'delete-user';
    const QUEUE_UPDATE_POLICY = 'update-policy';
    const QUEUE_DELETE_POLICY = 'delete-policy';

    /** @var LoggerInterface */
    protected $logger;
    /** @var DocumentManager */
    private $dm;
    /** @var HubspotFactory */
    private $client;
    /** @var RedisClient */
    protected $redis;
    /** @var SearchService */
    private $searchService;
    /** @var string */
    private $dealPipelineKey;

    /**
     * Builds the service.
     * @param DocumentManager $dm             is the document manager.
     * @param LoggerInterface $logger         is the logger.
     * @param string          $hubspotKey     is the hubspot integration API key.
     * @param string          $hubspotDealKey is the key to the hubspot deal pipeline for policies.
     * @param RedisClient     $redis          is the client for redis.
     * @param SearchService   $searchService  is used to search.
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $hubspotKey,
        $hubspotDealKey,
        RedisClient $redis,
        SearchService $searchService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new HubspotFactory(["key" => $hubspotKey], null, ['http_errors' => false], false);
        $this->redis = $redis;
        $this->searchService = $searchService;
        $this->queueKey = 'queue:hubspot';
        $this->dealPipelineKey = $hubspotDealKey;
    }

    /**
     * Adds a user update message to the queue. The update user action will create new users, and update those that
     * already exist as contacts on hubspot.
     * @param User $user is the user to be updated on hubspot.
     */
    public function queueUpdateContact(User $user)
    {
        $this->queue(['action' => self::QUEUE_UPDATE_USER, 'userId' => $user->getId()]);
    }

    /**
     * Adds a user deletion message to the queue.
     * @param User $user is the user to be deleted on hubspot along with all their associated deals.
     */
    public function queueDeleteContact(User $user)
    {
        $this->queue(['action' => self::QUEUE_DELETE_USER, 'userId' => $user->getId()]);
    }

    /**
     * Adds a policy update message to the queue. If the policy does not now exist it will be created as a deal on
     * hubspot.
     * @param Policy $policy is the policy to be updated on hubspot.
     */
    public function queueUpdateDeal(Policy $policy)
    {
        $this->queue(['action' => self::QUEUE_UPDATE_POLICY, 'policyId' => $policy->getId()]);
    }

    /**
     * If the given user already exists as a contact then update them, otherwise create them.
     * @param User $user the user the update will be based off of.
     * @return string containing the hubspot id of the contact.
     */
    public function createOrUpdateContact(User $user)
    {
        $hubspotUserArray = $this->buildHubspotUserData($user);
        $response = $this->client->contacts()->createOrUpdate($user->getEmail(), $hubspotUserArray);
        $this->validateResponse($response, [200, 204]);
        if ($user->getHubspotId() !== $response["\$vid"]) {
            $user->setHubspotId($response["\$vid"]);
            $this->dm->flush();
        }
        return $user->getHubspotId();
    }

    /**
     * If a given policy already exists as a hubspot deal then update it's properties, otherwise create it.
     * @param Policy $policy is the policy that the deal represents.
     * @return string containing the hubspot id of the deal.
     */
    public function createOrUpdateDeal(Policy $policy)
    {
        if (!$policy->getUser()->getHubspotId()) {
            throw new \Exception("cannot create policy/deal before user/contact. Policy id: ".$policy->getId());
        }
        $hubspotPolicyArray = $this->buildHubspotPolicyData($policy);
        if (!$policy->getHubspotId()) {
            $response = $this->client->deals()->create($hubspotPolicyArray);
            $this->validateResponse($response, [200, 404]);
            if ($response->getResponseCode() != 404) {
                $policy->setHubspotId($response["\$dealId"]);
                $this->dm->flush();
                return $response["\$dealId"];
            }
        }
        $response = $this->client->deals()->update($policy->getHubspotId(), $hubspotPolicyArray);
        $this->validateResponse($response, 204);
        return $policy->getHubspotId();
    }

    /**
     * Deletes a contact off hubspot which represented the given user.
     * @param User $user is the user represented by the hubspot contact you want to delete.
     */
    public function deleteContact($user)
    {
        $response = $this->client->contacts()->delete($user->getHubspotId());
        $this->validateResponse($response, [204]);
    }

    /**
     * Deletes a deal off hubspot which represented the given policy.
     * @param Policy $policy is the policy represented by the hubspot deal you want to delete.
     */
    public function deleteDeal($policy)
    {
        $response = $this->client->deals()->delete($policy->getHubspotId());
        $this->validateResponse($response, [204]);
    }

    /**
     * Actions a queue message.
     * @param array $message is the message to action.
     * @throws UnknownMessageException if the message lacks an action parameter.
     */
    protected function action($message)
    {
        switch ($message["action"]) {
            case self::QUEUE_UPDATE_USER:
                $user = $this->userFromMessage($message);
                $this->createOrUpdateContact($user);
                foreach ($user->getPolicies() as $policy) {
                    $this->createOrUpdateDeal($policy);
                }
                break;
            case self::QUEUE_DELETE_USER:
                $user = $this->userFromMessage($message);
                foreach ($user->getPolicies() as $policy) {
                    $this->deleteDeal($policy);
                }
                $this->deleteContact($user);
                break;
            case self::QUEUE_UPDATE_POLICY:
                $policy = $this->policyFromMessage($message);
                $this->createOrUpdateDeal($policy);
                break;
            case self::QUEUE_DELETE_POLICY:
                $policy = $this->policyFromMessage($message);
                $this->deleteDeal($policy);
                break;
            default:
                throw new UnknownMessageException(sprintf('Unknown message in queue %s', json_encode($message)));
        }
    }

    /**
     * Loads a user record based on the userId property in an array.
     * @param array $data is the message array that we are getting the user id from.
     * @return User the user found.
     * @throws MalformedMessageException when there is not a user id or the id doesn't represent a user.
     */
    private function userFromMessage($data)
    {
        if (!isset($data["userId"])) {
            throw new MalformedMessageException(sprintf("message lacks userId %s", json_encode($data)));
        }
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($data["userId"]);
        if (!$user) {
            throw new MalformedMessageException(sprintf("userId not valid %s", json_encode($data)));
        }
        return $user;
    }

    /**
     * Loads a policy record based on the policyId property in a message array.
     * @param array $data is the content of the message from which we must get the policyId.
     * @throws MalformedMessageException when there is no policy id or it doesn't represent a policy.
     * @return Policy the policy found.
     */
    private function policyFromMessage($data)
    {
        if (!isset($data["policyId"])) {
            throw new MalformedMessageException(sprintf("message lacks policyId %s", json_encode($data)));
        }
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $policyRepo->find($data["policyId"]);
        if (!$policy) {
            throw new MalformedMessageException(sprintf("policyId not valid %s", json_encode($data)));
        }
        return $policy;
    }

    /**
     * Collates the full set of data needed to create or update a hubspot deal based on a policy.
     * @param Policy $policy is the policy whose data is being used.
     * @return array containing the data formatted for sending to the hubspot apis.
     */
    private function buildHubspotPolicyData(Policy $policy)
    {
        $stage = "pre-pending";
        $status = $policy->getStatus();
        if ($status == Policy::STATUS_PENDING) {
            $stage = "pending";
        } elseif ($status == Policy::STATUS_ACTIVE) {
            $stage = "active";
        } elseif ($status == Policy::STATUS_UNPAID) {
            $stage = "unpaid";
        } elseif ($status == Policy::STATUS_CANCELLED) {
            $stage = "cancelled";
        } elseif ($status == Policy::STATUS_EXPIRED) {
            $stage = "expired";
        }
        $phoneProperties = [];
        if ($policy instanceof PhonePolicy) {
            $phoneProperties = [
                $this->buildDealProperty("phone_model", $policy->getPhone()->getModel()),
                $this->buildDealProperty("phone_make", $policy->getPhone()->getMake()),
                $this->buildDealProperty("phone_memory", $policy->getPhone()->getMemory()),
                $this->buildDealProperty("imei", $policy->getImei()),
                $this->buildDealProperty("serial", $policy->getSerialNumber())

            ];
        }
        $properties = [
            $this->buildDealProperty("pipeline", $this->dealPipelineKey),
            $this->buildDealProperty("dealname", $policy->getPolicyNumber()),
            $this->buildDealProperty("dealstage", $stage),
            $this->buildDealProperty("start", $policy->getStart()),
            $this->buildDealProperty("end", $policy->getEnd()),
        ];
        return [
            "associations" => [
                "associatedVids" => [$policy->getUser()->getHubspotId()]
            ],
            "properties" => array_merge($properties, $phoneProperties)
        ];
    }

    /**
     * builds data array of all user properties for hubspot.
     * @param User $user is the user that the data will be based on.
     * @return array of the data.
     */
    private function buildHubspotUserData(User $user)
    {
        $data = array_merge(
            $this->buildHubspotUserDetailsData($user),
            $this->buildHubspotAddressData($user),
            $this->buildHubspotMiscData($user)
        );
        return $data;
    }

    /**
     * Builds array of user general data for hubspot.
     * @param User $user is the user we are building the data array on.
     * @return array containing the data we just collated.
     */
    private function buildHubspotUserDetailsData(User $user)
    {
        $data = [
            $this->buildProperty("firstname", $user->getFirstName()),
            $this->buildProperty("lastname", $user->getLastName()),
            $this->buildProperty("email", $user->getEmailCanonical()),
            $this->buildProperty("mobilephone", $user->getMobileNumber()),
            $this->buildProperty("gender", $user->getGender() ?: "no"),
        ];
        if ($user->getBirthday()) {
            $data[] = $this->buildProperty("date_of_birth", $user->getBirthday()->format("U") * 1000);
        }
        return $data;
    }

    /**
     * Builds array of data related to user's address.
     * @param User $user is the user that the data is on.
     * @return array containing the data.
     */
    private function buildHubspotAddressData(User $user)
    {
        $data = [];
        if ($user->getBillingAddress()) {
            $data[] = $this->buildProperty("billing_address", $user->getBillingAddress()->__toString());
            if ($census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->buildProperty("census_subgroup", $census->getSubGroup());
            }
            if ($income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->buildProperty("total_weekly_income", $income->getTotal()->getIncome());
            }
        }
        return $data;
    }

    /**
     * Builds array of miscellanious user data.
     * @param User $user is the user that we are building the data on.
     * @return array containing the collected data.
     */
    private function buildHubspotMiscData(User $user)
    {
        $data = [
            $this->buildProperty("attribution", $user->getAttribution()),
            $this->buildProperty("latestattribution", $user->getLatestAttribution()),
            $this->buildProperty("customer", true)
        ];
        $facebookId = $user->getFacebookId();
        if ($facebookId) {
            $data["hs_facebookid"] = $this->buildProperty("hs_facebookid", $facebookId);
        }
        return $data;
    }

    /**
     * Puts a single property into the format that hubspot wants to receive it as.
     * @param string $name  is the name of the property.
     * @param mixed  $value is the object that the property consists of.
     * @return array with the property.
     */
    private function buildProperty($name, $value)
    {
        return ["property" => $name, "value" => $value ?: ""];
    }

    /**
     * Puts a single property into the format that hubspot wants to receive it as for a deal.
     * @param string $name  is the name of the property.
     * @param mixed  $value is the object that the property consists of.
     * @return array with the property.
     */
    private function buildDealProperty($name, $value)
    {
        return ["name" => $name, "value" => $value ?: ""];
    }

    /**
     * Check if a response has the correct status code, and if it does not then it throws an exception. If the status
     * code returned denotes rate limiting then it will tell you of this.
     * @param Response  $response is the response that you are checking.
     * @param array|int $desired  is the response code or list containing that you want the response to have.
     * @throws \Exception when $response's status code is not $desired.
     */
    private function validateResponse(Response $response, $desired)
    {
        $code = $response->getStatusCode();
        if (!is_array($desired)) {
            $desired = [$desired];
        }
        if (in_array($code, $desired)) {
            return json_decode($response->getBody()->getContents());
        } elseif ($code === 429) {
            // TODO: next time I merge master there is an exception I made for this and use catch it up in the process
            //       function like it does in mixpanel service to requeue unconditionally.
            //       Must also put that in the queue trait.
            throw new \Exception("Hubspot rate limited.");
        } else {
            throw new \Exception("Hubspot request returned status {$code}. ".$response->getBody());
        }
    }
}
