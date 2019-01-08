<?php

namespace AppBundle\Service;

use App\Exceptions\Queues\QueueException;
use App\Exceptions\Queues\UnknownMessage;
use App\Exceptions\Queues\UnknownUserId;
use App\Exceptions\Queues\UserNotFound;
use App\Hubspot\HubspotData;
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
    const QUEUE_USER = 'user';
    const QUEUE_EVENT_USER_PAYMENT_FAILED = 'userpayment-failed';

    const KEY_HUBSPOT_QUEUE = 'queue:hubspot';
    const KEY_HUBSPOT_RATELIMIT = 'hubspot:ratelimit';

    /** @var LoggerInterface */
    protected $logger;
    /** @var DocumentManager */
    protected $dm;
    /** @var HubspotFactory */
    private $client;
    /** @var RedisClient */
    protected $redis;
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
    public function getAllContacts(array $params = [])
    {
        $params = array_merge(["count" => 100], $params);
        $contactCount = 0;
        do {
            $contacts = $this->client->contacts()->all($params)->getData()->contacts;
            foreach ($contacts as $contact) {
                ++ $contactCount;
                yield $contact;
            }
            $params["vidOffset"] = $response["vid-offset"];
        } while ($response["has-more"]);
    }

    /**
     * If the given user already exists as a contact then update them, otherwise create them.
     * @param User $user the user the update will be based off of.
     * @param bool $allowSoSure whether to proceed if $user has a so-sure email address. defaults no.
     * @return \stdClass
     * @throws \Exception
     */
    public function createOrUpdateContact(User $user, $allowSoSure = false): \stdClass
    {
        $returnObj = new \stdClass();

        if (!$allowSoSure && $user->hasSoSureEmail()) {
            $returnObj->action = 'skipped';
            $returnObj->message = 'User has SoSure email';

            return $returnObj;
        }

        if (!$user->hasEmail()) {
            $returnObj->action = 'skipped';
            $returnObj->message = 'User has no email';

            return $returnObj;
        }

        /** @var array $hubspotUserArray Data we plan to send to Hubspot about a user */
        $hubspotUserArray = $this->hubspotData->getHubspotUserArray($user);

        if (empty($user->getHubspotId())) {
            return $this->createNewHubspotContact($user, $hubspotUserArray);
        }

        if ($this->hubspotData->isChanged($user, $hubspotUserArray)) {
            return $this->updateHubspotContact($user, $hubspotUserArray);
        }

        $returnObj->action = 'unchanged';
        $returnObj->vid = $user->getHubspotId();

        return $returnObj;
    }

    /**
     * Add user to the queue to be
     * @param User $user
     * @param int  $retryAttempts
     */
    public function queue(User $user, $retryAttempts = 0)
    {
        $this->queueUser($user, self::QUEUE_USER, null, $retryAttempts);
    }

    /**
     * Adds a user to the queue
     *
     * @param User   $user
     * @param string $event
     * @param mixed  $additional
     * @param int    $retryAttempts
     */
    public function queueUser(User $user, $event, $additional = null, $retryAttempts = 0)
    {
        $data = [
            'action' => $event,
            'userId' => $user->getId(),
            'retryAttempts' => $retryAttempts,
            'additional' => $additional,
        ];
        $this->redis->rpush(self::KEY_HUBSPOT_QUEUE, serialize($data));
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
     * Returns all data in the queue up to a maximum length.
     * @param int $max is the maximum number of items to return.
     * @return array containing the queue items.
     */
    public function getQueueData(int $max): array
    {
        return $this->redis->lrange(self::KEY_HUBSPOT_QUEUE, 0, $max);
    }

    /**
     * Processes elements in the queue.
     * @param int $max is the maximum number of queue items to process.
     * @return \stdClass with processed and requeued properties containing counts of actions performed.
     */
    public function process($max)
    {
        $requeued = 0;
        $processed = 0;
        $this->logger->debug('Hubspot| start queue process', ['maxQty'=>$max]);
        while (($processed + $requeued) < $max) {
            $user = null;
            $data = null;
            try {
                // TODO: make this so that the queue item is not popped until we are done with it so nothing bad occurs.
                $queueItem = $this->redis->lpop(self::KEY_HUBSPOT_QUEUE);
                if (!$queueItem) {
                    $this->logger->debug('Hubspot| queue empty');
                    break;
                }
                $data = unserialize($queueItem);
                if ($data) {
                    $actionCount = $this->processOneItem($data);
                    $processed += $actionCount->processed;
                    $requeued += $actionCount->requeued;
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->error(
                    'Hubspot| Error processing Hubspot queue messages' . $e->getMessage(),
                    [ 'msg' => json_encode($data), 'exception' => $e->getMessage() ]
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Hubspot| some sort of exception, requeue' . $e->getMessage(),
                    ['data'=>$data, 'exception'=>$e]
                );
                $this->requeue($data, $e);
            }
        }
        $actionCounts = new \stdClass();
        $actionCounts->processed = $processed;
        $actionCounts->requeued = $requeued;
        return $actionCounts;
    }

    /**
     * Adds a user to the queue
     * @param array $data
     * @param \Exception $e
     */
    private function requeue($data, \Exception $e)
    {
        if (isset($data['retryAttempts']) && $data['retryAttempts'] < 2) {
            $data['retryAttempts'] ++;
            $this->redis->rpush(self::KEY_HUBSPOT_QUEUE, serialize($data));

            return;
        }

        $this->logger->error(
            'Hubspot| Error (retry exceeded) sending message to Hubspot',
            ['data'=>$data, $e->getMessage()]
        );
    }

    /**
     * Helper function to find a user
     */
    private function getUserById($id): User
    {
        if (!$id) {
            throw new UnknownUserId('Missing userId');
        }
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->find($id);
        if (!$user) {
            throw new UserNotFound(sprintf('Unable to find userId: %s', $id));
        }

        return $user;
    }

    /**
     * @param Response $response
     * @param string   $introMessage
     * @throws \Exception
     */
    public function throwException($response, $introMessage = '')
    {
        $responseMessage = json_encode($response, JSON_PRETTY_PRINT);
        if (!empty($introMessage)) {
            $responseMessage = $introMessage . ' : ' . $responseMessage;
        }
        throw new QueueException($responseMessage);
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getProperties(): array
    {
        $response = $this->client->contactProperties()->all();

        if (200 !== $response->getStatusCode()) {
            $this->throwException($response, 'Could not get properties from Hubspot');
        }
        $this->assertHubspotNotRateLimited($response);

        return $response->getData();
    }

    /**
     * @param array $data
     * @return \stdClass ->requeued or ->processed
     *
     * @throws \InvalidArgumentException
     */
    public function processOneItem(array $data): \stdClass
    {
        $actionCount = new \stdClass();
        $actionCount->requeued = 0;
        $actionCount->processed = 0;

        $action = $this->assertKnownAction($data);

        if ($result = $this->isProcessLater($data, $actionCount)) {
            return $result;
        }

        if ($action !== self::QUEUE_USER) {
            $this->logger->notice('Hubspot| unknown message in queue, is not action:QUEUE_USER', ['data' => $data]);
            throw new UnknownMessage(sprintf('Unknown message in queue %s', json_encode($data)));
        }

        if (!isset($data['userId'])) {
            $this->logger->notice('Hubspot| unknown message, not userId', ['data' => $data]);
            throw new UnknownUserId(sprintf('Unknown message in queue %s', json_encode($data)));
        }

        $user = $this->getUserById($data['userId']);
        $this->logger->debug('Hubspot| createOrUpdateContact', ['user' => $user]);
        $this->createOrUpdateContact($user);

        $this->logger->debug('Hubspot| processed message');
        $actionCount->processed = 1;

        return $actionCount;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function assertHubspotNotRateLimited(Response $response)
    {
        if (429 === $response->getStatusCode()) {
            throw new RateLimitException('Rate limits exceeded' . json_encode($response));
        }
    }

    private function assertKnownAction(array $data): string
    {
        if (isset($data['action'])) {
            return $data['action'];
        }

        // legacy before action was used.  can be removed soon after
        $this->logger->notice('Hubspot| legacy action, make it QUEUE_USER', ['unknown action'=>$data]);

        return self::QUEUE_USER;
    }

    private function isProcessLater(array $data, $actionCount) // : ?\stdClass
    {
        // Requeue anything not yet ready to process
        $now = new \DateTime();
        if (isset($data['processTime']) && ($data['processTime'] > $now->format('U'))) {
            $this->redis->rpush(self::KEY_HUBSPOT_QUEUE, serialize($data));
            $this->logger->notice('Hubspot| processTime not reached, re-queue');

            $actionCount->requeued = 1;
            return $actionCount;
        }

        return null;
    }

    private function createNewHubspotContact(User $user, $hubspotUserArray): \stdClass
    {
        // always allow the create if we don't know the hubspotId
        $response = $this->client->contacts()->createOrUpdate($user->getEmail(), $hubspotUserArray);
        $this->hubspotData->update($user, $hubspotUserArray);

        if (200 !== $response->getStatusCode()) {
            $this->throwException($response, 'Contact not created on Hubspot');
        }
        $this->assertHubspotNotRateLimited($response);

        if ($response->getData()->isNew) {
            $user->setHubspotId($response->getData()->vid);
            $this->dm->persist($user); // Todo: not sure if this is needed.
            $this->dm->flush();
        }

        $returnObj = new \stdClass();
        $returnObj->action = 'created';
        $returnObj->vid = $response->getData()->vid;

        return $returnObj;
    }

    private function updateHubspotContact(User $user, $hubspotUserArray): \stdClass
    {
        // if hubspotId exists, assume that the user exists in Hubspot too
        $response = $this->client->contacts()->update($user->getHubspotId(), $hubspotUserArray);
        $this->hubspotData->update($user, $hubspotUserArray);
        $this->dm->persist($user);

        if (204 !== $response->getStatusCode()) {
            $this->throwException($response, 'Contact not updated on Hubspot');
        }
        $this->assertHubspotNotRateLimited($response);

        $returnObj = new \stdClass();
        $returnObj->action = 'updated';
        $returnObj->vid = $user->getHubspotId();

        return $returnObj;
    }
}
