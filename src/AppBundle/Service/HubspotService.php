<?php

namespace AppBundle\Service;

use App\Exceptions\Queue\QueueException;
use App\Exceptions\Queue\UnknownMessageException;
use App\Exceptions\Queue\MalformedMessageException;
use App\Hubspot\HubspotData;
use App\Hubspot\Api;
use AppBundle\Document\User;
use AppBundle\Exception\RateLimitException;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use SevenShores\Hubspot\Exceptions\BadRequest;
use SevenShores\Hubspot\Factory as HubspotFactory;
use SevenShores\Hubspot\Resources\Contacts;
use GuzzleHttp\Psr7\Response;

/**
 * Provides the primary hubspot functionality.
 */
class HubspotService
{
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
     * @param HubspotData     $hubspotData is the hubspot data formatter.
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
        $this->client = new HubspotFactory(["key" => $hubspotKey], null, ['http_errors' => false], false);
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
                    throw new UnknownMessage(sprintf('Actionless message in queue %s', json_encode($data)));
                }
                if (!$this->ready($data)) {
                    $requeued++;
                    $this->queue($data);
                    continue;
                }
                // Only one kind of action right now but it will handle others the same way.
                switch ($data["action"]) {
                    case self::QUEUE_CONTACT:
                        $this->processContact($data);
                        break;
                    default:
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
        $this->logger->debug(
            'Hubspot| finish queue process',
            ["processed" => $processed, "requeued" => $requeued, "dropped" => $dropped]
        );
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
     * Synchronises a property group with hubspot.
     * @param string $groupName   is the name of the group to run for.
     * @param string $displayName is the desired display name of the group.
     */
    public function syncPropertyGroup($groupName, $displayName)
    {
        $response = $this->client->contactProperties()->createGroup(
            ["name" => $groupName, "displayName" => $displayName]
        );
        $this->validateResponse($response, 200);
    }

    /**
     * Updates a property on hubspot or creates it if it does not yet exist.
     * @param array $data is the content of the property to be created.
     * @return array|null the property if newly created, or nothing if it already existed.
     */
    public function syncProperty($data)
    {
        try {
            $this->client->contactProperties()->get($data["name"]);
            return null;
        } catch (\Exception $e) {
            return $this->client->contactProperties()->create($data);
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
        $this->validateResponse($response, 200);
        return $response->getData();
    }

    /**
     * If the given user already exists as a contact then update them, otherwise create them.
     * @param User $user        the user the update will be based off of.
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

    /**
     * Processes a contact message.
     * @param array $data is the content of the message which just needs to contain "userId".
     * @throws \Exception when it does not work out.
     */
    private function processContact($data)
    {
        if (!isset($data["userId"])) {
            throw new MalformedMessageException(sprintf('malformed contact message %s', json_encode($data)));
        }
        $user = $this->getUserById($data["userId"]);
        $this->logger->debug("Hubspot| createOrUpdateContact", ["user" => $user]);
        $this->createOrUpdateContact($user);
    }

    /**
     * Adds a message to the queue to be processed later.
     * @param array   $data  is the content of the message being queued.
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
        $this->validateResponse($response, 200);
        if ($response->getData()->isNew) {
            $user->setHubspotId($response->getData()->vid);
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
        $this->validateResponse($response, 204);
        $this->dm->persist($user);
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
     * Check if a response has the correct status code, and if it does not then it throws an exception. If the status
     * code returned denotes rate limiting then it will tell you of this.
     * @param Response $response is the response that you are checking.
     * @param int      $desired  is the response code that you want the response to have.
     * @throws \Exception when $response's status code is not $desired.
     */
    private function validateResponse(Response $response, $desired)
    {
        $code = $response->getStatusCode();
        if ($code === $desired) {
            return;
        } elseif ($code === 429) {
            throw new \Exception("Hubspot rate limited.");
        } else {
            throw new \Exception(
                "Hubspot request returned status {$code} instead of {$desired}. ".$response->getBody()
            );
        }
    }
}
