<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;

use AppBundle\Document\User;
use AppBundle\Document\Sns;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Exception\ValidationException;

use MongoRegex;
use Gedmo\Loggable\Document\LogEntry;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;

abstract class BaseController extends Controller
{
    use PhoneTrait;

    public function isDataStringPresent($data, $field)
    {
        return strlen($this->getDataString($data, $field)) > 0;
    }

    protected function getDataBool($data, $field)
    {
        return filter_var($this->getDataString($data, $field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function getDataString($data, $field)
    {
        if (!isset($data[$field])) {
            return null;
        }

        $value = $data[$field];

        // Cast to string to avoid possible array injections which could lead to nosql injections
        if (is_array($value)) {
            throw new ValidationException(sprintf('Expected string, not array (%s)', json_encode($value)));
        }
        return trim((string) $value);
    }

    public function getRequestString($request, $field)
    {
        // Cast to string to avoid possible array injections which could lead to nosql injections
        if (is_array($request->get($field))) {
            throw new ValidationException(sprintf(
                'Expected string, not array (%s)',
                json_encode($request->get($field))
            ));
        }
        return trim((string) $request->get($field));
    }

    protected function getRequestBool($request, $field)
    {
        return filter_var($this->getRequestString($request, $field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function getManager()
    {
        return $this->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getCognitoIdentityId(Request $request)
    {
        $auth = $this->get('app.user.cognitoidentity.authenticator');

        return $auth->getCognitoIdentityId($request->getContent());
    }

    protected function getCognitoIdentityIp(Request $request)
    {
        $auth = $this->get('app.user.cognitoidentity.authenticator');

        return $auth->getCognitoIdentityIp($request->getContent());
    }

    protected function getCognitoIdToken(User $user, Request $request)
    {
        $cognitoIdentity = $this->get('app.cognito.identity');

        return $cognitoIdentity->getCognitoIdToken($user, $this->getCognitoIdentityId($request));
    }
    
    protected function getPhonesArray()
    {
        $makes = [];
        $phones = [];
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $items = $repo->findActive()->getQuery()->execute();
        foreach ($items as $phone) {
            if (!in_array($phone->getMake(), $makes)) {
                $makes[] = $phone->getMake();
            }
            $phones[$phone->getMake()][$phone->getId()] = $phone->getModelMemory();
        }

        return $phones;
    }

    protected function getQuotes($make, $device, $returnAllIfNone = true)
    {
        // TODO: We should probably be checking make as well.  However, we need to analyize the data
        // See Phone::isSameMake()
        \AppBundle\Classes\NoOp::noOp([$make]);

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $device, 'active' => true]);
        if ($returnAllIfNone &&
            (count($phones) == 0 || $device == "")) {
            $phones = $repo->findBy(['make' => 'ALL']);
        }

        return $phones;
    }

    /**
     * Get the best matching phone.
     * Assuming that memory will be a bit less than actual advertised size, but find the closest matching
     *
     * @param string $make
     * @param string $device see googe play device list (or apple phone list)
     * @param float  $memory in gb
     *
     * @return Phone|null
     */
    protected function getPhone($make, $device, $memory)
    {
        $phones = $this->getQuotes($make, $device, false);
        if (count($phones) == 0) {
            return null;
        }

        // sort low to high
        usort($phones, function ($a, $b) {
            return $a->getMemory() > $b->getMemory();
        });

        // 3 cases to consider
        // low - phone memory is less than smallest
        // standard - phone memory is somewhere in the middle
        // high - phone exceeds all cases (new device with more memory?)
        foreach ($phones as $phone) {
            if ($memory <= $phone->getMemory()) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Page results
     *
     * @param Request $request
     * @param         $qb
     * @param integer $maxPerPage
     *
     * @return Pagerfanta
     */
    protected function pager(Request $request, $qb, $maxPerPage = 50)
    {
        $adapter = new DoctrineODMMongoDBAdapter($qb);
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($maxPerPage);
        $pagerfanta->setCurrentPage($request->get('page') ? $request->get('page') : 1);

        return $pagerfanta;
    }

    /**
     * Validate that body fields are present
     *
     * @param array $data
     * @param array $fields
     *
     * @return boolean true if all fields are present
     */
    protected function validateFields($data, $fields)
    {
        foreach ($fields as $field) {
            if (!isset($data[$field])) {
                return false;
            }
            if (is_bool($data[$field])) {
                return true;
            }
            if (is_array($data[$field]) || strlen(trim($data[$field])) == 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate that request query fields are present
     *
     * @param Request $request
     * @param array   $fields
     *
     * @return boolean true if all fields are present
     */
    protected function validateQueryFields(Request $request, $fields)
    {
        foreach ($fields as $field) {
            if (strlen($this->getRequestString($request, $field)) == 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return a standard json error message
     *
     * @param string  $errorCode
     * @param string  $description
     * @param integer $httpCode
     *
     * @return JsonResponse
     */
    protected function getErrorJsonResponse($errorCode, $description, $httpCode = 422)
    {
        $request = $this->get('request_stack')->getCurrentRequest();
        $this->get('logger')->info(sprintf(
            '%s return %d (%d: %s)',
            $request->getPathInfo(),
            $httpCode,
            $errorCode,
            $description
        ));

        return new JsonResponse(['code' => $errorCode, 'description' => $description], $httpCode);
    }

    protected function mobileToMongoSearch($form, $qb, $formField, $mongoField, $run = false)
    {
        $data = (string) $form->get($formField)->getData();

        return $this->dataToMongoSearch($qb, $this->normalizeUkMobile($data), $mongoField, $run);
    }

    protected function formToMongoSearch($form, $qb, $formField, $mongoField, $run = false)
    {
        $data = (string) $form->get($formField)->getData();

        return $this->dataToMongoSearch($qb, $data, $mongoField, $run);
    }

    protected function dataToMongoSearch($qb, $data, $mongoField, $run = false)
    {
        if (strlen($data) == 0) {
            return null;
        }

        // Escape special chars
        $data = preg_quote($data, '/');
        $qb = $qb->field($mongoField)->equals(new MongoRegex(sprintf("/.*%s.*/i", $data)));
        if ($run) {
            return $qb->getQuery()->execute();
        }

        return null;
    }

    /**
     * @param array $data
     *
     * @return \DateTime|Response|null
     */
    protected function validateBirthday($data)
    {
        if (!isset($data['birthday'])) {
            return null;
        }
        $birthday = \DateTime::createFromFormat(\DateTime::ATOM, $this->getDataString($data, 'birthday'));
        if (!$birthday) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                'Unable to parse birthday',
                422
            );
        }
        $now = new \DateTime();
        $diff = $now->diff($birthday);
        if ($diff->y > 150) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVALD_DATA_FORMAT,
                '150 years old not possible for quite some time',
                422
            );
        }
        if ($diff->y < 18) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_USER_TOO_YOUNG,
                'Must be over 18',
                422
            );
        }

        return $birthday;
    }

    protected function getReferer()
    {
        $request = $this->getRequest();

        //look for the referer route
        $referer = $request->headers->get('referer');
        if (strlen($referer) > 0) {
            return $referer;
        }

        return null;
    }

    private function getAdditionalIdentityLogKey($cognitoId)
    {
        return sprintf('device:%s', $cognitoId);
    }

    protected function getAdditionalIdentityLog($cognitoId)
    {
        $key = $this->getAdditionalIdentityLogKey($cognitoId);
        $data = $this->get('snc_redis.default')->get($key);
        if ($data) {
            return json_decode($data, true);
        }

        return null;
    }

    protected function setAdditionalIdentityLog($cognitoId, $additional)
    {
        $key = $this->getAdditionalIdentityLogKey($cognitoId);
        $this->get('snc_redis.default')->setex($key, 3900, json_encode($additional));
    }

    protected function getIdentityLog(Request $request)
    {
        // NOTE: not completely secure, but as we're only using for an indication, it's good enough
        // http://docs.aws.amazon.com/apigateway/latest/developerguide/api-gateway-mapping-template-reference.html
        // https://forums.aws.amazon.com/thread.jspa?messageID=673393
        $clientIp = $this->getCognitoIdentityIp($request);

        $geoip = $this->get('app.geoip');
        $cognitoIdentityId = $this->getCognitoIdentityId($request);
        $identityLog = $geoip->getIdentityLog($clientIp, $cognitoIdentityId);
        $additional = $this->getAdditionalIdentityLog($cognitoIdentityId);
        if ($additional) {
            $identityLog->setPlatform(isset($additional['platform']) ? $additional['platform'] : null);
            $identityLog->setVersion(isset($additional['version']) ? $additional['version'] : null);
            $identityLog->setUuid(isset($additional['uuid']) ? $additional['uuid'] : null);
            if (isset($additional['device']) && isset($additional['memory'])) {
                $identityLog->setPhone($this->getPhone(null, $additional['device'], $additional['memory']));
            }
        }

        return $identityLog;
    }

    protected function getIdentityLogWeb(Request $request)
    {
        $identityLog = new IdentityLog();
        $identityLog->setIp($request->getClientIp());

        return $identityLog;
    }

    protected function getUserHistory($userId)
    {
        $dm = $this->getManager();
        $logRepo = $dm->getRepository(LogEntry::class);
        $userHistory = $logRepo->findBy(['objectClass' => User::class, 'objectId' => $userId]);
        foreach ($userHistory as $item) {
            $data = $item->getData();
            if (isset($data['token'])) {
                $data['token'] = "**Redacted**";
                $item->setData($data);
            }
        }

        return $userHistory;
    }

    protected function getSalvaPhonePolicyHistory($policyId)
    {
        $dm = $this->getManager();
        $logRepo = $dm->getRepository(LogEntry::class);
        $policyHistory = $logRepo->findBy(['objectClass' => SalvaPhonePolicy::class, 'objectId' => $policyId]);

        return $policyHistory;
    }

    protected function getDefaultPhone()
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['model' => 'iPhone 6S', 'memory' => 16]);

        return $phone;
    }
    
    protected function validateObject($object)
    {
        $validator = $this->get('validator');
        $errors = $validator->validate($object);
        if (count($errors) > 0) {
            throw new ValidationException((string) $errors);
        }
    }

    protected function getFullTopicName($topic)
    {
        $environment = $this->getParameter('kernel.environment');
        $topic = str_replace('.', '-', $topic);

        return sprintf('App_%s_%s', ucfirst($environment), ucfirst($topic));
    }

    protected function getArnForTopic($topic)
    {
        $baseArn = $this->getParameter('sns_base_arn');

        return sprintf('%s:%s', $baseArn, $this->getFullTopicName($topic));
    }

    protected function createTopic($topic)
    {
        $topicName = $this->getFullTopicName($topic);
        $client = $this->get('aws.sns');
        $result = $client->createTopic(array(
            'Name' => $topicName,
        ));
    }

    protected function snsClientSubscribe($topicArn, $endpoint)
    {
        $client = $this->get('aws.sns');
        $result = $client->subscribe(array(
            // TopicArn is required
            'TopicArn' => $topicArn,
            // Protocol is required
            'Protocol' => 'application',
            'Endpoint' => $endpoint,
        ));

        return $result['SubscriptionArn'];
    }

    protected function snsSubscribe($topic, $endpoint)
    {
        try {
            $topicArn = $this->getArnForTopic($topic);
            if (!$topicArn) {
                return;
            }

            try {
                $subscriptionArn = $this->snsClientSubscribe($topicArn, $endpoint);
            } catch (\Exception $e) {
                $this->createTopic($topic);
                $subscriptionArn = $this->snsClientSubscribe($topicArn, $endpoint);
            }

            $dm = $this->getManager();
            $snsRepo = $dm->getRepository(Sns::class);
            $sns = $snsRepo->findOneBy(['endpoint' => $endpoint]);
            if (!$sns) {
                $sns = new Sns();
                $sns->setEndpoint($endpoint);
                $dm->persist($sns);
            }
            switch ($topic) {
                case 'all':
                    $sns->setAll($subscriptionArn);
                    break;
                case 'registered':
                    $sns->setRegistered($subscriptionArn);
                    break;
                case 'unregistered':
                    $sns->setUnregistered($subscriptionArn);
                    break;
                default:
                    $sns->addOthers($topic, $subscriptionArn);
            }

            $dm->flush();
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Failed sns sub to topic: %s Ex: %s', $topic, $e->getMessage()));
        }
    }

    protected function snsUnsubscribe($topic, $endpoint)
    {
        $dm = $this->getManager();
        $snsRepo = $dm->getRepository(Sns::class);
        $sns = $snsRepo->findOneBy(['endpoint' => $endpoint]);
        $subscriptionArn = null;
        switch ($topic) {
            case 'all':
                $subscriptionArn = $sns->getAll();
                $sns->setAll(null);
                break;
            case 'registered':
                $subscriptionArn = $sns->getRegistered();
                $sns->setRegistered(null);
                break;
            case 'unregistered':
                $subscriptionArn = $sns->getUnregistered();
                $sns->getUnregistered(null);
                break;
        }

        if (!$subscriptionArn) {
            return;
        }

        $client = $this->get('aws.sns');
        $result = $client->unsubscribe(array(
            'SubscriptionArn' => $subscriptionArn,
        ));
        $dm->flush();
    }

    protected function conformAlphanumericSpaceDot($value, $length)
    {
        $validator = new AlphanumericSpaceDotValidator();

        return $validator->conform(substr($value, 0, $length));
    }

    protected function conformAlphanumeric($value, $length)
    {
        $validator = new AlphanumericValidator();

        return $validator->conform(substr($value, 0, $length));
    }

    protected function getSessionQuotePhone(Request $request)
    {
        $session = $request->getSession();
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);

        $phone = null;
        if ($session->get('quote')) {
            $phone = $phoneRepo->find($session->get('quote'));
        }

        return $phone;
    }
}
