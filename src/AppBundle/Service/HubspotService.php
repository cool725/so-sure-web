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
use GuzzleHttp\Psr7\Response;

/**
 * Provides the primary hubspot functionality.
 */
class HubspotService
{
    use QueueTrait;

    const QUEUE_UPDATE_USER = 'create-user';
    const QUEUE_DELETE_USER = 'delete-user';
    const QUEUE_UPDATE_DEAL = 'update-deal';

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

    /**
     * Builds the service.
     * @param DocumentManager $dm            is the document manager.
     * @param LoggerInterface $logger        is the logger.
     * @param string          $hubspotKey    is the hubspot integration API key.
     * @param RedisClient     $redis         is the client for redis.
     * @param SearchService   $searchService is used to search.
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $hubspotKey,
        RedisClient $redis,
        SearchService $searchService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = new HubspotFactory(["key" => $hubspotKey], null, ['http_errors' => false], false);
        $this->redis = $redis;
        $this->searchService = $searchService;
        $this->queueKey = 'queue:hubspot';
    }

    /**
     * Adds a user update message to the queue.
     * @param User $user is the user to be updated on hubspot.
     */
    public function queueUpdateUser(User $user)
    {
        $this->queue(['action' => self::QUEUE_UPDATE_USER, 'userId' => $user->getId()]);
    }

    /**
     * Adds a user deletion message to the queue.
     * @param User $user is the user to be deleted on hubspot along with all their associated deals.
     */
    public function queueDeleteUser(User $user)
    {
        $this->queue(['action' => self::QUEUE_DELETE_USER, 'userId' => $user->getId()]);
    }

    /**
     * Adds a policy update message to the queue.
     * @param Policy $policy is the policy to be updated on hubspot.
     */
    public function queueUpdatePolicy(Policy $policy)
    {
        $this->queue(['action' => self::QUEUE_UPDATE_POLICY, 'policyId' => $policy->getId()]);
    }

    /**
     * Creates a contact list on hubspot if it does not already exist.
     * @param string $name is the name of the list that it will create.
     */
    public function syncList($name)
    {
        $this->client->contactLists()->create(["name" => $name, "dynamic" => false]);
        $this->validateResponse($response, [200, 409]);
    }

    /**
     * Synchronises a contact property with hubspot.
     * @param string $name is the name of the property to create.
     * @param string $displayName is the name that will be shown to users of hubspot for this property.
     * TODO: this is wrong I think
     */
    public function syncProperty($name, $displayName)
    {
        $this->client->contactProperties()->create(
            ["name" => $name, "displayName" => $displayName]
        );
        $this->validateResponse($response, [200, 409]);
    }

    /**
     * Synchronises a property group with hubspot.
     * @param string $groupName   is the name of the group to run for.
     * @param string $displayName is the desired display name of the group.
     */
    public function syncPropertyGroup($groupName, $displayName)
    {
        $response = $this->client->contactProperties()->createGroup(
            ["name" => $groupName, "displayName" => $displayName]
        );
        $this->validateResponse($response, [200, 409]);
    }

    /**
     * Adds a pipeline onto hubspot.
     * @param string $name is the name that the pipeline will be given.
     * @param array  $stages is a list of each pipeline stage which must be formatted as hubspot requires.
     */
    public function syncPipeline($name, $stages)
    {
        $response = $this->client->contactProperties()->createGroup(
            ["label" => $name, "stages" => $stages]
        );
        $this->validateResponse($response, [200, 409]);
    }

    /**
     * If the given user already exists as a contact then update them, otherwise create them.
     * @param User $user        the user the update will be based off of.
     * @param bool $allowSoSure whether to proceed if $user has a so-sure email address. defaults no.
     * @throws \Exception
     */
    public function createOrUpdateContact(User $user, $allowSoSure = false)
    {
        if (!$user->hasEmail() || $user->hasSoSureEmail()) {
            return;
        }
        $hubspotUserArray = $this->buildHubspotUserDetailsData($user);
        if (!$user->getHubspotId()) {
            $this->createNewHubspotContact($user, $hubspotUserArray);
        } else {
            $this->updateHubspotContact($user, $hubspotUserArray);
        }
    }

    /**
     * Updates a contact record on hubspot.
     * @param User  $user             is the user that we are updating.
     * @param array $hubspotUserArray is the array of data that we are sending to hubspot.
     * @return int the vid of the new user.
     */
    public function updateHubspotContact(User $user, $hubspotUserArray)
    {
        $response = $this->client->contacts()->update($user->getHubspotId(), $hubspotUserArray);
        $this->validateResponse($response, 204);
        $this->dm->persist($user);
        return $user->getHubspotId();
    }

    /**
     * Creates a new contact on hubspot.
     * @param User  $user             is the user that we are basing the new contact on.
     * @param array $hubspotUserArray contains hubspot users I guess.
     * @return int containing the hubspot vid returned from the request.
     * @throws \Exception if the request does not work out.
     */
    public function createNewHubspotContact(User $user, $hubspotUserArray)
    {
        $response = $this->client->contacts()->createOrUpdate($user->getEmail(), $hubspotUserArray);
        $response = $this->validateResponse($response, 200);
        $user->setHubspotId($response->vid);
        $this->dm->flush();
        return $response->vid;
    }

    /**
     * Requests a contact from the hubspot api via their email.
     * @param string $email is the email address of the contact being looked for.
     * @return Response containing hubspot's reply.
     */
    public function getContactByEmail(string $email): Response
    {
        return $this->client->contacts()->getByEmail($email);
    }

    /**
     * Gets list of all the contacts from Hubspot via generator, since only a limited number can be gotten at a time.
     * @param array $params is an optional list of parameters to add to the request.
     * @return \Generator yielding all contacts in hubspot.
     */
    public function getAllContacts($params = [])
    {
        $params = array_merge(["count" => 100], $params);
        do {
            $response = $this->client->contacts()->all($params);
            $contacts = $response->getBody()->contacts;
            foreach ($contacts as $contact) {
                yield $contact;
            }
            $params["vidOffset"] = $response["vid-offset"];
        } while ($response["has-more"]);
    }

    /**
     * Puts a single property into the format that hubspot wants to receive it as.
     * @param string $name  is the name of the property.
     * @param mixed  $value is the object that the property consists of.
     * @return array with the property.
     */
    public function buildProperty($name, $value)
    {
        return ['property' => $name, 'value' => $value];
    }

    /**
     * Builds the form that a new property description must take in order to be synced to hubspot.
     * @param string $name      is the name of the property internally.
     * @param string $label     is the name to show to users on hubspot.
     * @param string $type      is the type of this property.
     * @param string $fieldType is the type of editing it would use on mixpanel.
     * @param bool   $formField is TODO: I dunno.
     * @param array  $options   is the list of choosable options that this property has if it is of a type that has
     *                          those.
     * @return array containing the new property data.
     */
    public function buildPropertyPrototype(
        $name,
        $label,
        $type = "string",
        $fieldType = "text",
        $formField = false,
        $options = null
    ) {
        $data = [
            "name" => $name,
            "type" => $type,
            "fieldType" => $fieldType,
            "formField" => $formField
        ];
        if ($options) {
            $data["options"] = $options;
        }
        return $data;
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
                $this->updateContact($user);
                break;
            case self::QUEUE_DELETE_USER:
                $user = $this->userFromMessage($message);
                $this->deleteContact($user);
                break;
            case self::QUEUE_UPDATE_DEAL:
                $user = $this->userFromMessage($message);
                $this->updateDeal($policy);
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
        /** @var UserRepository*/
        $userRepo = $this->dm->getRepository(User::class);
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
        /** @var PolicyRepository*/
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policy = $policyRepo->find($data["policyId"]);
        if (!$policy) {
            throw new MalformedMessageException(sprintf("policyId not valid %s", json_encode($data)));
        }
        return $policy;
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
        if (is_array($desired) && in_array($code, $desired) || $code === $desired) {
            return json_decode($response->getBody()->getContents());
        } elseif ($code === 429) {
            // TODO: next time I merge master there is an exception I made for this and use catch it up in the process
            //       function like it does in mixpanel service to requeue unconditionally.
            throw new \Exception("Hubspot rate limited.");
        } else {
            throw new \Exception(
                "Hubspot request returned status {$code} instead of {$desired}. ".$response->getBody()
            );
        }
    }

    /**
     * builds data array of all user properties for hubspot.
     * @param User $user is the user that the data will be based on.
     * @return array of the data.
     */
    private function getHubspotUserData(User $user)
    {
        $data = array_merge(
            $this->buildHubspotUserDetailsData($user),
            $this->buildHubspotAddressData($user),
            $this->buildHubspotMiscData($user),
            $this->buildHubspotLifecycleStageData($user)
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
            $this->buildProperty("gender", $user->getGender()),
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
            $data[] = $this->buildProperty("billing_address", $user->getBillingAddress());
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
        $hasFacebook = $user->getFacebookId() == true;
        $data = [
            $this->hubspotProperty("attribution", $user->getAttribution() ?: ''),
            $this->hubspotProperty("latestattribution", $user->getLatestAttribution() ?: ''),
            $this->hubspotProperty("facebook", $hasFacebook ? "yes" : "no"),
        ];
        if ($hasFacebook) {
            $data['hs_facebookid'] = $this->hubspotProperty("hs_facebookid", $user->getFacebookId());
        }
        return $data;
    }
}
