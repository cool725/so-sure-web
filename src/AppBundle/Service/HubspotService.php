<?php

namespace AppBundle\Service;

use App\Exceptions\Queue\QueueException;
use App\Exceptions\Queue\UnknownMessageException;
use App\Exceptions\Queue\UnknownUserIdException;
use App\Exceptions\Queue\UserNotFoundException;
use App\Hubspot\HubspotData;
use App\Hubspot\Api;
use AppBundle\Document\User;
use AppBundle\Exception\RateLimitException;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Exception\ClientException;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use SevenShores\Hubspot\Exceptions\BadRequest;
use SevenShores\Hubspot\Factory as HubspotFactory;
use SevenShores\Hubspot\Http\Response;
use SevenShores\Hubspot\Resources\Contacts;

/**
 * Provides the primary hubspot functionality.
 */
class HubspotService
{
    const HUBSPOT_SOSURE_PROPERTY_NAME = "sosure";
    const HUBSPOT_SOSURE_PROPERTY_DESC = "Custom properties used by SoSure";

    const QUEUE_CONTACT = 'contact';

    const QUEUE_EVENT_USER_PAYMENT_FAILED = 'userpayment-failed';

    const KEY_HUBSPOT_QUEUE = 'queue:hubspot';
    const KEY_HUBSPOT_RATELIMIT = 'hubspot:ratelimit';

    private const RETRY_LIMIT = 3;

    /** @var LoggerInterface */
    private $logger;
    /** @var DocumentManager */
    private $dm;
    /** @var HubspotFactory */
    private $client;
    /** @var RedisClient */
    private $redis;
    /** @var \App\Hubspot\HubspotData */
    private $hubspotData;

    /**
     * Builds the service.
     * @param DocumentManager $dm          is the document manager.
     * @param LoggerInterface $logger      is the logger.
     * @param string          $hubspotKey  is the hubspot integration API key.
     * @param RedisClient     $redis       is the client for redis.
     * @param HubspotData     $hubspotData is the hubspot data thingo. TODO: what is that?
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $hubspotKey,
        RedisClient $redis,
        HubspotData $hubspotData
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->client = HubspotFactory::create($hubspotKey);
        $this->redis = $redis;
        $this->hubspotData = $hubspotData;
    }

    /**
     * Sets the hubspot service's logger so it can be output somewhere else.
     * @param LoggerInterface $logger is the new logger to use.
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Adds a contact sync message to the queue.
     * @param User $user is the user to be synched as a contact.
     */
    public function queueContact(User $user)
    {
        $data = ['action' => self::QUEUE_CONTACT, 'userId' => $user->getId()];
        $this->queue($data);
    }

    /**
     * Processes queued messages.
     * @param int $max is the maximum number of messages to process.
     * @return array containing "processed", "requeued", and "dropped" counts.
     */
    public function process($max)
    {
        $requeued = 0;
        $processed = 0;
        $dropped = 0;
        $this->logger->debug('Hubspot| start queue process', ['maxQty'=>$max]);
        while (($processed + $requeued) < $max) {
            $data = null;
            try {
                $message = $this->redis->lpop(self::KEY_HUBSPOT_QUEUE);
                if (!$message) {
                    $this->logger->debug('Hubspot| queue empty');
                    break;
                }
                $data = unserialize($message);
                if (!$data || !isset($data["action"])) {
                    $this->logger->notice("Message in queue has no action.", ["data" => $data]);
                    throw new UnknownMessage(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                if (!$this->ready($data)) {
                    $requeued++;
                    $this->queue($data);
                    continue;
                }
                // Only one kind of action right now but it will handle others the same way.
                switch($data["action"]) {
                    case self::QUEUE_CONTACT:
                        $this->processContact($data);
                        break;
                    default:
                        $this->logger->notice("Message in queue has invalid action.", ["data" => $data]);
                        throw new UnknownMessage(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $processed++;
            } catch (QueueException $e) {
                $this->logger->error(
                    "Hubspot| Invalid Hubspot message" . $e->getMessage(),
                    ["msg" => json_encode($data), "exception" => $e->getMessage()]
                );
                $dropped++;
            } catch (\Exception $e) {
                $this->logger->warning(
                    "Hubspot| Exception during message processing, requeueing. " . $e->getMessage(),
                    ["data" => $data, "exception" => $e]
                );
                $requeued++;
                $this->queue($data, true);
            }
        }
        $this->logger->debug('Hubspot| finish queue process', ["processed" => $processed, "requeued" => $requeued, "dropped" => $dropped]);
        return ["processed" => $processed, "requeued" => $requeued, "dropped" => $dropped];
    }

    /**
     * Tells the length of the queue.
     * @return int the length of the queue.
     */
    public function countQueue()
    {
        return $this->redis->llen(self::KEY_HUBSPOT_QUEUE);
    }

    /**
     * Deletes everything in the queue.
     */
    public function clearQueue()
    {
        $this->redis->del([self::KEY_HUBSPOT_QUEUE]);
    }

    /**
     * Returns all messages in the queue up to a maximum length.
     * @param int $max is the maximum number of messages to return.
     * @return array containing the messages.
     */
    public function getQueueData(int $max): array
    {
        return $this->redis->lrange(self::KEY_HUBSPOT_QUEUE, 0, $max);
    }

    /**
     * Checks the Hubspot API for the properties that we need and creates any that it cannot find.
     * @return array Containing messages with all the actions taken.
     */
    public function syncProperties()
    {
        $actions = [];
        $soSureProperties = $this->allPropertiesWithGroup();
        foreach ($soSureProperties as $propertyData) {
            $propertyName = $propertyData["name"];
            try {
                $this->client->contactProperties()->get($propertyName);
                $actions[] = "<info>$propertyName</info> was found on Hubspot.";
            } catch (\Exception $e) {
                $actions[] = "<comment>$propertyName</comment> was not found on Hubspot.";
                $actions[] = "<info>$propertyName</info> trying to create Hubspot.";
                array_push($actions, $this->createHubspotProperty($propertyData, $propertyName));
            }
        }
        return $actions;
    }

    /**
     * Synchronises a property group with hubspot.
     * NOTE: I massively changed this because it seemed to be completely broken, but there is also the possibility that
     *       it was right and I had no idea what I was doing. Keep in mind.
     * @param string $groupName is the name of the group to run for.
     * @param string $displayName is the desired display name of the group.
     * @return string containing messages detailing what it did.
     */
    public function syncPropertyGroup(
        $groupName = self::HUBSPOT_SOSURE_PROPERTY_NAME,
        $displayName = self::HUBSPOT_SOSURE_PROPERTY_DESC
    ) {
        $groups = $this->client->contactProperties()->getGroups();
        foreach ($groups->getData() as $group) {
            if ($group->name === $groupName) {
                return "Group named '$groupName' already exists.";
            }
        }
        try {
            $create = $this->client->contactProperties()->createGroup([
                "name" => $groupName,
                "displayName" => $displayName
            ]);
            if ($create->getStatusCode() !== 200) {
                // TODO: use throwException
                return "Could not create group on Hubspot: ".json_encode($create);
            }
            return "Group named '$groupName' created successfully.";
        } catch (BadRequest $exception) {
            return "Group named '$groupName' creation failed. ".$exception->getMessage();
        } catch (ClientException $exception) {
            return "Group named '$groupName' creation failed. ".$exception->getMessage();
        }
    }

    /**
     * Attempts to create a property on hubspot.
     * @param object $propertyData is the content of the property.
     * @param string $propertyName is the name of the property on hubspot.
     * @return string with a message detailing the success of the function.
     */
    private function createHubspotProperty($propertyData, $propertyName)
    {
        try {
            $this->client->contactProperties()->create($propertyData);
            return "<info>$propertyName</info> created on Hubspot.";
        } catch (\Exception $e) {
            return "property: <error>$propertyName</error> could not be created on Hubspot." . $e->getMessage();
        }
    }

    /**
     * Downloads all current contact properties from hubspot.
     * @return array containing all of the properties.
     * @throws \Exception when the properties can not be got.
     */
    public function getProperties()
    {
        $response = $this->client->contactProperties()->all();
        if ($response->getStatusCode() !== 200) {
            $this->throwException($response, 'Could not get properties from Hubspot');
        }
        return $response->getData();
    }

    /**
     * If the given user already exists as a contact then update them, otherwise create them.
     * @param User $user the user the update will be based off of.
     * @param bool $allowSoSure whether to proceed if $user has a so-sure email address. defaults no.
     * @return \stdClass
     * @throws \Exception
     */
    public function createOrUpdateContact(User $user, $allowSoSure = false)
    {
        if (!$allowSoSure && $user->hasSoSureEmail() || !$user->hasEmail()) {
            return;
        }
        $hubspotUserArray = $this->hubspotData->getHubspotUserArray($user);
        if (empty($user->getHubspotId())) {
            $this->createNewHubspotContact($user, $hubspotUserArray);
        } else {
            $this->updateHubspotContact($user, $hubspotUserArray);
        }
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
     * @param array params is an optional list of parameters to add to the request.
     * @return Generator yielding all contacts in hubspot.
     */
    public function getAllContacts($params = [])
    {
        $params = array_merge(["count" => 100], $params);
        do {
            $response = $this->client->contacts()->all($params);
            $contacts = $response->getData()->contacts;
            foreach ($contacts as $contact) {
                yield $contact;
            }
            $params["vidOffset"] = $response["vid-offset"];
        } while ($response["has-more"]);
    }

    public function assertHubspotNotRateLimited(Response $response)
    {
        // TODO: I reckon this should be removed or at least reworked.
        // probably better to make a function that takes in the desired response code and then can either fail normally
        // or throw that rate limit exception. eyeeeaaah.
        if (429 === $response->getStatusCode()) {
            throw new RateLimitException('Rate limits exceeded' . json_encode($response));
        }
    }

    /**
     * Processes a contact message.
     * @param array $data is the content of the message which just needs to contain "userId".
     * @throws \Exception when it does not work out.
     */
    private function processContact($data)
    {
        if (!isset($data["userId"])) {
            $this->logger->notice('Hubspot| unknown message in queue, is not action:QUEUE_CONTACT', ['data' => $data]);
            throw new MalformedMessageException(sprintf('malformed contact message %s', json_encode($data)));
        }
        $user = $this->getUserById($data["userId"]);
        $this->logger->debug("Hubspot| createOrUpdateContact", ["user" => $user]);
        $this->createOrUpdateContact($user);
    }

    /**
     * Adds a message to the queue to be processed later.
     * @param array $data is the content of the message being queued.
     * @param boolean $retry is whether the "attempts" property should be incremented and checked for too many attempts.
     */
    private function queue($data, $retry = false)
    {
        if (isset($data["attempts"])) {
            if ($retry) {
                $data["attempts"]++;
            }
            if ($data["attempts"] >= self::RETRY_LIMIT) {
                $this->logger->error(
                    'Hubspot| Error (retry exceeded) sending message to Hubspot',
                    ['data'=>$data, $e->getMessage()]
                );
                return;
            }
        } else {
            $data["attempts"] = 0;
        }
        $this->redis->rpush(self::KEY_HUBSPOT_QUEUE, serialize($data));
    }

    /**
     * Tells you if the message is ready to be processed based on the optional processTime value.
     * @param array $data is the message we are checking on.
     * @return boolean which tells you whether or not they are ready.
     */
    private function ready(array $data)
    {
        $now = new \DateTime();
        if (isset($data["processTime"]) && ($data["processTime"] > $now->format("U"))) {
            return false;
        }
        return true;
    }

    /**
     * Creates a new contact on hubspot.
     * @param User  $user             is the user that we are basing the new contact on.
     * @param array $hubspotUserArray contains hubspot users I guess.
     * @return int containing the hubspot vid returned from the request.
     * @throws \Exception if the request does not work out.
     */
    private function createNewHubspotContact(User $user, $hubspotUserArray)
    {
        $response = $this->client->contacts()->createOrUpdate($user->getEmail(), $hubspotUserArray);
        if ($response->getStatusCode() !== 200) {
            $this->throwException($response, 'Contact not created on Hubspot');
        }
        if ($response->getData()->isNew) {
            $user->setHubspotId($response->getData()->vid);
            $this->dm->persist($user); // TODO: not sure if this is needed.
            $this->dm->flush();
        }
        return $response->getData()->vid;
    }

    /**
     * Updates a contact record on hubspot.
     * @param User  $user             is the user that we are updating.
     * @param array $hubspotUserArray is the array of data that we are sending to hubspot.
     * @return int the vid of the new user.
     */
    private function updateHubspotContact(User $user, $hubspotUserArray)
    {
        $response = $this->client->contacts()->update($user->getHubspotId(), $hubspotUserArray);
        $this->hubspotData->update($user, $hubspotUserArray);
        $this->dm->persist($user);
        if ($response->getStatusCode() !== 204) {
            $this->throwException($response, 'Contact not updated on Hubspot');
        }
        $this->assertHubspotNotRateLimited($response);
        return $user->getHubspotId();
    }

    /**
     * Helper function to find a user.
     * @param string $id is the id of the user to find.
     * @return User|null the user who was found or null if nothing is found.
     */
    private function getUserById($id)
    {
        $repo = $this->dm->getRepository(User::class);
        return $repo->find($id);
    }

    /**
     * Throws an exception about a bad http request.
     * @param Response $response is the http response we are throwing an exception over.
     * @param string   $message  is a message explaining the problem.
     * @throws \Exception every time.
     */
    private function throwException($response, $message = "")
    {
        $responseMessage = json_encode($response, JSON_PRETTY_PRINT);
        if (!empty($introMessage)) {
            $responseMessage = $introMessage . ' : ' . $responseMessage;
        }
        throw new \Exception($responseMessage);
    }

    /**
     * Fields that, if they do not exist, will be created as properties in the 'sosure' group
     * @return array containing property data formatted for hubspot.
     */
    private function allPropertiesWithGroup()
    {
        return [
            [
                "name" => "gender",
                "label" => "gender",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "radio",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "male", "value" => "male"],
                    ["label" => "female", "value" => "female"],
                    ["label" => "x/not-known", "value" => "x"]
                ]
            ],
            [
                "name" => "date_of_birth",
                "label" => "Date of birth",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "date",
                "fieldType" => "date",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "facebook",
                "label" => "Facebook?",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "checkbox",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "yes", "value" => "yes"],
                    ["label" => "no", "value" => "no"]
                ],
            ],
            [
                "name" => "billing_address",
                "label" => "Billing address",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "textarea",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "census_subgroup",
                "label" => "Estimated census_subgroup",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "total_weekly_income",
                "label" => "Estimated total_weekly_income",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "attribution",
                "label" => "attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "latestattribution",
                "label" => "Latest attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "sosure_lifecycle_stage",
                "label" => "SO-SURE lifecycle stage",
                "description" => "Current stage in purchase-flow",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "select",
                "formField" => true,
                "displayOrder" => -1,
                "options" => [
                    ["label" => Api::QUOTE, "value" => Api::QUOTE],
                    ["label" => Api::READY_FOR_PURCHASE, "value" => Api::READY_FOR_PURCHASE],
                    ["label" => Api::PURCHASED, "value" => Api::PURCHASED],
                    ["label" => Api::RENEWED, "value" => Api::RENEWED],
                    ["label" => Api::CANCELLED, "value" => Api::CANCELLED],
                    ["label" => Api::EXPIRED, "value" => Api::EXPIRED]
                ]
            ]
        ];
    }
}
