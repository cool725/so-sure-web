<?php

namespace AppBundle\Service;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\QueueTrait;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Exception\QueueException;
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

    const DEAL_STAGE_CACHE_KEY = "hubspot:dealstages";

    const QUEUE_UPDATE_USER = 'update-user';
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
        $this->client = new HubspotFactory(["key" => $hubspotKey], null, [], true);
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
     * Gives you the hubspot id of every user contact on hubspot.
     * @return \Generator to iterate over them all as you cannot get them in one go due to hubspot paging it.
     */
    public function getAllContacts()
    {
        //$condition = $customer ? ["customer" => true] : [];
        $offset = 0;
        while (true) {
            $response = $this->client->contacts()->all([
                "count" => 100,
                "property" => "customer",
                "offset" => $offset
            ]);
            foreach ($response["contacts"] as $contact) {
                if (array_key_exists("customer", $contact["properties"]) && $contact["properties"]["customer"]) {
                    yield $contact["vid"];
                }
            }
            if (!$response["has-more"]) {
                return;
            }
            $offset = $response["vid-offset"];
        }
    }

    /**
     * Gives you the hubspot id of each customer deal on hubspot.
     * @return \Generator to iterate over them all as you cannot get them in one go due to hubspot paging it.
     */
    public function getAllDeals()
    {
        $offset = 0;
        while (true) {
            $response = $this->client->deals()->getAll([
                "count" => 100,
                "offset" => $offset,
                "properties" => "pipeline"
            ]);
            foreach ($response["deals"] as $deal) {
                if (array_key_exists("pipeline", $deal["properties"]) &&
                    $deal["properties"]["pipeline"]["value"] == $this->dealPipelineKey) {
                    yield $deal["dealId"];
                }
            }
            if (!$response["hasMore"]) {
                return;
            }
            $offset = $response["offset"];
        }
    }

    /**
     * Gets the id code of a desired deal stage. This information has to be downloaded from hubspot but can then be
     * cached, so this just downloads and caches it when necessary.
     * @param string $name is the name of the desired stage.
     * @return string the id code of the desired deal stage so long as it's a valid deal stage.
     * @throws \Exception if you try to get a deal stage that does not exist.
     */
    public function getDealStageId($name)
    {
        $value = $this->redis->hget(self::DEAL_STAGE_CACHE_KEY, $name);
        if ($value) {
            return $value;
        }
        $response = $this->client->dealPipelines()->getPipeline($this->dealPipelineKey);
        foreach ($response["stages"] as $stage) {
            $this->redis->hset(self::DEAL_STAGE_CACHE_KEY, $stage["label"], $stage["stageId"]);
        }
        $value = $this->redis->hget(self::DEAL_STAGE_CACHE_KEY, $name);
        if ($value) {
            return $value;
        }
        throw new \Exception("{$name} is not a deal stage in the sosure deal pipeline");
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
        if ($user->getHubspotId() !== $response["vid"]) {
            $user->setHubspotId($response["vid"]);
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
            $policy->setHubspotId($response["dealId"]);
            $this->dm->flush();
            return $response["dealId"];
        }
        $this->client->deals()->update($policy->getHubspotId(), $hubspotPolicyArray);
        return $policy->getHubspotId();
    }

    /**
     * Deletes a contact off hubspot which represented the given user.
     * @param User $user is the user represented by the hubspot contact you want to delete.
     */
    public function deleteUser($user)
    {
        $this->deleteContact($user->getHubspotId());
        $user->setHubspotId(null);
    }

    /**
     * Deletes a deal off hubspot which represented the given policy.
     * @param Policy $policy is the policy represented by the hubspot deal you want to delete.
     */
    public function deletePolicy($policy)
    {
        $this->deleteDeal($policy->getHubspotId());
        $policy->setHubspotId(null);
    }

    /**
     * Deletes a given contact from hubspot by their hubspot id.
     * @param string $hubspotId is the id of the hubspot contact to delete.
     */
    public function deleteContact($hubspotId)
    {
        $response = $this->client->contacts()->delete((int) $hubspotId);
    }

    /**
     * Deletes a given deal from hubspot by their hubspot id.
     * @param string $hubspotId is the id of the hubspot deal to delete.
     */
    public function deleteDeal($hubspotId)
    {
        $response = $this->client->deals()->delete((int) $hubspotId);
    }

    /**
     * Actions a queue message.
     * @param array $message is the message to action.
     * @throws QueueException if the message lacks an action parameter.
     */
    protected function action($message)
    {
        switch ($message["action"]) {
            case self::QUEUE_UPDATE_USER:
                $user = $this->userFromMessage($message);
                $this->createOrUpdateContact($user);
                // If policy has not been synced to hubspot before we should join policy and user sync so that user is
                // certain to be synced before policy is.
                foreach ($user->getPolicies() as $policy) {
                    if (!$policy->getHubspotId()) {
                        $this->createOrUpdateDeal($policy);
                    }
                }
                break;
            case self::QUEUE_DELETE_USER:
                $user = $this->userFromMessage($message);
                foreach ($user->getPolicies() as $policy) {
                    $this->deletePolicy($policy);
                }
                $this->deleteUser($user);
                break;
            case self::QUEUE_UPDATE_POLICY:
                $policy = $this->policyFromMessage($message);
                $this->createOrUpdateDeal($policy);
                break;
            case self::QUEUE_DELETE_POLICY:
                $policy = $this->policyFromMessage($message);
                $this->deletePolicy($policy);
                break;
            default:
                throw new QueueException(sprintf('Unknown message in queue %s', json_encode($message)));
        }
    }

    /**
     * Loads a user record based on the userId property in an array.
     * @param array $data is the message array that we are getting the user id from.
     * @return User the user found.
     * @throws QueueException when there is not a user id or the id doesn't represent a user.
     */
    private function userFromMessage($data)
    {
        if (!isset($data["userId"])) {
            throw new QueueException(sprintf("message lacks userId %s", json_encode($data)));
        }
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($data["userId"]);
        if (!$user) {
            throw new QueueException(sprintf("userId not valid %s", json_encode($data)));
        }
        return $user;
    }

    /**
     * Loads a policy record based on the policyId property in a message array.
     * @param array $data is the content of the message from which we must get the policyId.
     * @throws QueueException when there is no policy id or it doesn't represent a policy.
     * @return Policy the policy found.
     */
    private function policyFromMessage($data)
    {
        if (!isset($data["policyId"])) {
            throw new QueueException(sprintf("message lacks policyId %s", json_encode($data)));
        }
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $policyRepo->find($data["policyId"]);
        if (!$policy) {
            throw new QueueException(sprintf("policyId not valid %s", json_encode($data)));
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
        $data = [];
        $this->addProperty("pipeline", $this->dealPipelineKey, $data, true);
        $this->addProperty("dealname", $policy->getPolicyNumber(), $data, true);
        $this->addProperty("dealstage", $this->getDealStageId($stage), $data, true);
        if ($policy->getStart()) {
            $data[] = $this->buildProperty("start", $policy->getStart()->format("d-m-Y H:i"), true);
        }
        if ($policy->getEnd()) {
            $data[] = $this->buildProperty("end", $policy->getEnd()->format("d-m-Y H:i"), true);
        }
        if ($policy instanceof PhonePolicy) {
            $this->addProperty("phone_model", $policy->getPhone()->getModel(), $data, true);
            $this->addProperty("phone_make", $policy->getPhone()->getMake(), $data, true);
            $this->addProperty("phone_memory", $policy->getPhone()->getMemory(), $data, true);
            $this->addProperty("imei", $policy->getImei(), $data, true);
            $this->addProperty("serial", $policy->getSerialNumber(), $data, true);
        }
        return [
            "associations" => [
                "associatedVids" => [$policy->getUser()->getHubspotId()]
            ],
            "properties" => $data
        ];
    }

    /**
     * builds data array of all user properties for hubspot.
     * @param User $user is the user that the data will be based on.
     * @return array of the data.
     */
    private function buildHubspotUserData(User $user)
    {
        $data = [];
        $this->addProperty("firstname", $user->getFirstName(), $data);
        $this->addProperty("lastname", $user->getLastName(), $data);
        $this->addProperty("email", $user->getEmailCanonical(), $data);
        $this->addProperty("mobilephone", $user->getMobileNumber(), $data);
        $this->addProperty("gender", $user->getGender(), $data);
        $this->addProperty("customer", true, $data);
        $this->addProperty("hs_facebookid", $user->getFacebookId(), $data);
        if ($user->getBirthday()) {
            $data[] = $this->buildProperty("date_of_birth", $user->getBirthday()->format("U") * 1000);
        }
        if ($user->getBillingAddress()) {
            $data[] = $this->buildProperty("billing_address", $user->getBillingAddress()->__toString());
            if ($census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->buildProperty("census_subgroup", $census->getSubGroup());
            }
            if ($income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->buildProperty("total_weekly_income", $income->getTotal()->getIncome());
            }
        }
        // attribution data.
        $this->addUserAttributionProperties($user, false, $data);
        $this->addUserAttributionProperties($user, true, $data);
        return $data;
    }

    /**
     * Adds a user's attribution data to a hubspot property array.
     * @param User    $user   is the user to get the data out of.
     * @param boolean $latest is whether to use the latest attribution or original attribution. true means latest.
     * @param array   $array  is the data array to add the property to. NB: it's edited directly.
     */
    private function addUserAttributionProperties($user, $latest, &$array)
    {
        $attribution = $latest ? $user->getAttribution() : $user->getLatestAttribution();
        if (!$attribution) {
            return;
        }
        $name = $latest ? "attribution" : "latest_attribution";
        $array[] = $this->buildProperty("{$name}_campaign_name", $attribution->getCampaignName());
        $array[] = $this->buildProperty("{$name}_campaign_source", $attribution->getCampaignSource());
        $array[] = $this->buildProperty("{$name}_campaign_medium", $attribution->getCampaignMedium());
        $array[] = $this->buildProperty("{$name}_device_category", $attribution->getDeviceCategory());
        $array[] = $this->buildProperty("{$name}_device_os", $attribution->getDeviceOs());
    }

    /**
     * Add a hubspot property to a list of properties if the value is not null.
     * @param string  $name  is the name of the property.
     * @param mixed   $value is the value of the property.
     * @param array   $array is the array to add it to.
     * @param boolean $deal  is whether the property is a deal or customer property.
     */
    private function addProperty($name, $value, &$array, $deal = false)
    {
        if ($value) {
            $array[] = $this->buildProperty($name, $value, $deal);
        }
    }

    /**
     * Builds the prototype of a property needed to be sent in to describe an object to hubspot.
     * @param string  $name  is the name of the property.
     * @param mixed   $value is the value of the property.
     * @param boolean $deal  is whether it is a deal property or a contact property is there is a slight difference.
     * @return array containing the property prototype.
     */
    private function buildProperty($name, $value, $deal = false)
    {
        return [($deal ? "name" : "property") => $name, "value" => $value];
    }
}