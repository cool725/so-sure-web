<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use Pagerfanta\Adapter\ArrayAdapter;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Sns;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Reward;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Exception\ValidationException;
use AppBundle\Exception\RedirectException;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PolicySearchType;

use MongoRegex;
use Gedmo\Loggable\Document\LogEntry;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use AppBundle\Validator\Constraints\AgeValidator;

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

    protected function getFormBool($form, $field)
    {
        return filter_var($form->get($field)->getData(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function findRewardUser($email)
    {
        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $rewardRepo = $dm->getRepository(Reward::class);
        if ($rewardUser = $userRepo->findOneBy(['emailCanonical' => $email])) {
            return $rewardRepo->findOneBy(['user' => $rewardUser]);
        }

        return null;
    }

    protected function getManager()
    {
        return $this->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getCensusManager()
    {
        return $this->get('doctrine_mongodb.odm.census_document_manager');
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
            if ($phone->getAlternativeMake()) {
                if (!in_array($phone->getAlternativeMake(), $makes)) {
                    $makes[] = $phone->getAlternativeMake();
                }
                $phones[$phone->getAlternativeMake()][$phone->getId()] = $phone->getModelMemory();
            }
        }

        return $phones;
    }

    protected function getPhonesSearchArray($simple = true)
    {
        $makes = [];
        $phones = [];
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $items = $repo->findActive()->getQuery()->execute();
        foreach ($items as $phone) {
            if ($simple) {
                $phones[] = ['id' => $phone->getId(), 'name' => $phone->__toString()];
            } else {
                $phones[sprintf('%s %s', $phone->getMake(), $phone->getModel())
                ][] = ['id' => $phone->getId(), 'memory' => $phone->getMemory()];
            }
        }

        if (!$simple) {
            $transformedPhones = [];
            foreach ($phones as $name => $data) {
                $transformedPhones[] = ['id' => $data[0]['id'], 'name' => $name, 'sizes' => $data];
            }

            $phones = $transformedPhones;
        }

        return $phones;
    }

    protected function getQuotes($make, $device, $memory = null, $rooted = null, $ignoreMake = false)
    {
        // TODO: We should probably be checking make as well.  However, we need to analyize the data
        // See Phone::isSameMake()
        \AppBundle\Classes\NoOp::ignore([$make]);

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $device]);

        $anyActive = false;
        $anyRetired = false;
        $anyPricing = false;
        $memoryFound = null;

        if ($memory !== null) {
            $memoryFound = false;
        }
        foreach ($phones as $phone) {
            if ($phone->getActive()) {
                $anyActive = true;
            }
            if ($phone->shouldBeRetired()) {
                $anyRetired = true;
            }
            if ($phone->getCurrentPhonePrice() && $phone->getCurrentPhonePrice()->getYearlyGwp() > 0) {
                $anyPricing = true;
            }
            if ($memory !== null && $memory <= $phone->getMemory()) {
                $memoryFound = true;
            }
        }

        $deviceFound = count($phones) > 0 && $phones[0]->getMake() != "ALL";

        if (!$deviceFound || $memoryFound === false) {
            $this->unknownDevice($device, $memory);
        }

        if ($rooted) {
            $this->rootedDevice($device, $memory);
        }

        $differentMake = false;
        if ($deviceFound && !$phones[0]->isSameMake($make)) {
            $differentMake = true;
            if (!$ignoreMake) {
                $this->differentMake($phones[0]->getMake(), $make);
            }
        }

        return [
            'phones' => $phones,
            'deviceFound' => $deviceFound,
            'memoryFound' => $memoryFound,
            'differentMake' => $differentMake,
            'anyActive' => $anyActive,
            'anyRetired' => $anyRetired,
            'anyPricing' => $anyPricing,
        ];
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
    protected function getPhone($make, $device, $memory, $ignoreMake = false)
    {
        $quotes = $this->getQuotes($make, $device, null, null, $ignoreMake);
        $phones = $quotes['phones'];
        if (count($phones) == 0 || !$quotes['anyActive']) {
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
     * Page results
     *
     * @param Request $request
     * @param         $qb
     * @param integer $maxPerPage
     *
     * @return Pagerfanta
     */
    protected function arrayPager(Request $request, $array, $maxPerPage = 50)
    {
        $adapter = new ArrayAdapter($array);
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

    public function getSuccessJsonResponse($description)
    {
        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, $description, 200);
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

    protected function queryToMongoSearch($form, $qb, $formField, $mongoField, $callable)
    {
        if ($data = $this->formToMongoSearch($form, $qb, $formField, $mongoField, true)) {
            $ids = [];
            foreach ($data as $item) {
                $ids[] = call_user_func($callable, $item);
            }

            return $ids;
        }

        return null;
    }

    protected function formToMongoSearch($form, $qb, $formField, $mongoField, $run = false, $exact = false)
    {
        $data = (string) $form->get($formField)->getData();

        return $this->dataToMongoSearch($qb, $data, $mongoField, $run, $exact);
    }

    protected function dataToMongoSearch($qb, $data, $mongoField, $run = false, $exact = false)
    {
        if (strlen($data) == 0) {
            return null;
        }

        // Escape special chars
        $data = preg_quote($data, '/');
        if ($exact) {
            $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals($data));
        } else {
            $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals(new MongoRegex(sprintf("/.*%s.*/i", $data))));
        }
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
        if ($diff->y > AgeValidator::MAX_AGE) {
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

    protected function getReferer($request)
    {
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
                $identityLog->setPhone($this->getPhone(null, $additional['device'], $additional['memory'], true));
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
        if (!$sns) {
            return;
        }
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

    protected function getSessionSixpackTest(Request $request, $experiment, $alternatives)
    {
        $session = $request->getSession();

        $sessionName = sprintf('sixpack:%s', $experiment);
        $alternative = $session->get($sessionName);
        if (!$alternative) {
            $alternative = $this->get('app.sixpack')->participate($experiment, $alternatives, true);
            $session->set($sessionName, $alternative);
        }

        return $alternative;
    }

    protected function searchPolicies(Request $request, $includeInvalidPolicies = null)
    {
        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $policyRepo = $dm->getRepository(Policy::class);

        $policiesQb = $policyRepo->createQueryBuilder();

        $form = $this->createForm(PolicySearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        if ($includeInvalidPolicies === null) {
            $includeInvalidPolicies = $form->get('invalid')->getData();
        }
        $sosure = $form->get('sosure')->getData();
        if ($sosure) {
            $imeiService = $this->get('app.imei');
            if ($imeiService->isImei($sosure)) {
                throw new RedirectException($this->generateUrl(
                    'admin_policies',
                    ['imei' => $sosure, 'invalid' => $includeInvalidPolicies]
                ));
            } else {
                throw new RedirectException($this->generateUrl(
                    'admin_policies',
                    ['facebookId' => $sosure, 'invalid' => $includeInvalidPolicies]
                ));
            }
        }

        $status = (string) $form->get('status')->getData();
        // having a - in the name of the status requires a special case
        if ($status == 'current') {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'current-discounted') {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('policyDiscountPresent')->equals(true)
            );
        } elseif ($status == 'past-due') {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_CANCELLED])
            );
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('cancelledReason')->notIn([Policy::CANCELLED_UPGRADE])
            );
        } elseif ($status == Policy::STATUS_EXPIRED_CLAIMABLE) {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_CLAIMABLE])
            );
        } elseif ($status == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_WAIT_CLAIM])
            );
        } elseif ($status == Policy::STATUS_PENDING_RENEWAL) {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_PENDING_RENEWAL])
            );
        } else {
            $this->formToMongoSearch($form, $policiesQb, 'status', 'status', false, true);
        }

        $this->formToMongoSearch($form, $policiesQb, 'policy', 'policyNumber');
        $this->formToMongoSearch($form, $policiesQb, 'imei', 'imei');
        $this->formToMongoSearch($form, $policiesQb, 'id', '_id', false, true);
        $this->formToMongoSearch($form, $policiesQb, 'serial', 'serialNumber');
        if (!$includeInvalidPolicies) {
            $policy = new PhonePolicy();
            $search = sprintf('%s/', $policy->getPolicyNumberPrefix());
            $this->dataToMongoSearch($policiesQb, $search, 'policyNumber');
        }

        $userFormData = [
            'email' => 'emailCanonical',
            'lastname' => 'lastName',
            'mobile' => 'mobileNumber',
            'postcode' => 'billingAddress.postcode',
            'facebookId' => 'facebookId',
        ];
        foreach ($userFormData as $formField => $dataField) {
            $ids = $this->queryToMongoSearch(
                $form,
                $userRepo->createQueryBuilder(),
                $formField,
                $dataField,
                function ($data) {
                    return $data->getId();
                }
            );
            if ($ids !== null) {
                $policiesQb->addOr($policiesQb->expr()->field('user.id')->in($ids));
                $policiesQb->addOr($policiesQb->expr()->field('namedUser.id')->in($ids));
            }
        }

        if ($status == Policy::STATUS_UNPAID) {
            $policies = $policiesQb->getQuery()->execute()->toArray();
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
            $pager = $this->arrayPager($request, $policies);
        } elseif ($status == 'past-due') {
            $policies = $policiesQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                return $policy->isCancelledAndPaymentOwed();
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
            $pager = $this->arrayPager($request, $policies);
        } else {
            $policies = $policiesQb->getQuery()->execute()->toArray();
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getStart() > $b->getStart();
            });
            $pager = $this->arrayPager($request, $policies);
        }

        return [
            'policies' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'form' => $form->createView()
        ];
    }

    protected function searchUsers(Request $request)
    {
        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $usersQb = $userRepo->createQueryBuilder();

        $form = $this->createForm(UserSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $sosure = $form->get('sosure')->getData();
        if ($sosure) {
            throw new RedirectException($this->generateUrl(
                'admin_users',
                ['facebookId' => $sosure]
            ));
        }
        $userFormData = [
            'email' => 'emailCanonical',
            'lastname' => 'lastName',
            'mobile' => 'mobileNumber',
            'postcode' => 'billingAddress.postcode',
            'facebookId' => 'facebookId',
        ];
        foreach ($userFormData as $formField => $dataField) {
            $this->formToMongoSearch(
                $form,
                $usersQb,
                $formField,
                $dataField
            );
        }
        $allSanctions = $this->getFormBool($form, 'allSanctions');
        $waitingSanctions = $this->getFormBool($form, 'waitingSanctions');
        if ($allSanctions || $waitingSanctions) {
            $usersQb = $usersQb->addAnd(
                $usersQb->expr()->field('sanctionsMatches')->notEqual(null)
            );
            $usersQb = $usersQb->addAnd(
                $usersQb->expr()->field('sanctionsMatches')->not(['$size' => 0])
            );
        }
        if ($waitingSanctions) {
            $usersQb = $usersQb->addAnd(
                $usersQb->expr()->field('sanctionsMatches.0.manuallyVerified')->notEqual(true)
            );
        }

        $pager = $this->pager($request, $usersQb);

        return [
            'users' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'form' => $form->createView()
        ];
    }

    protected function isProduction()
    {
        return $this->getParameter('kernel.environment') == 'prod';
    }

    /**
     * @param string $device
     * @param float  $memory
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device, $memory)
    {
        if (in_array($device, [
            "", "generic_x86", "generic_x86_64", "Simulator",
            "iPad4,4", "iPad5,2", "iPad5,3", "iPad5,4", "iPad6,7", "iPad6,8", "iPad Air", "iPad Air 2"
        ])) {
            return false;
        }

        $body = sprintf(
            'Unknown device queried: %s (%s GB). If device exists, memory may be higher than expected',
            $device,
            $memory
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Unknown Device/Memory')
            ->setFrom('tech@so-sure.com')
            ->setTo('analysis@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);

        return true;
    }

    /**
     * @param string $dbMake
     * @param string $phoneMake
     */
    private function differentMake($dbMake, $phoneMake)
    {
        $body = sprintf(
            'Make in db is different than phone make. Db: %s Phone: %s',
            $dbMake,
            $phoneMake
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Make different in db')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
    }

    /**
     * @param string $device
     * @param float  $memory
     */
    private function rootedDevice($device, $memory)
    {
        $body = sprintf(
            'Rooted device queried: %s (%s GB).',
            $device,
            $memory
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Rooted Device/Memory')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
    }

    protected function sixpack($request, $name, $options)
    {
        $exp = $this->get('app.sixpack')->participate($name, $options);
        if ($request->get('force')) {
            $exp = $request->get('force');
        }

        return $exp;
    }
}
