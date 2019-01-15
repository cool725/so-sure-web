<?php

namespace AppBundle\Controller;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\S3File;
use AppBundle\Document\PolicyTerms;
use AppBundle\Form\Type\ClaimSearchType;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Security\CognitoIdentityAuthenticator;
use AppBundle\Service\MaxMindIpService;
use AppBundle\Service\QuoteService;
use Doctrine\MongoDB\Cursor;
use Doctrine\ODM\MongoDB\Query\Builder;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Adapter\MongoAdapter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait;

use Pagerfanta\Pagerfanta;
use AppBundle\Classes\DoctrineODMMongoDBAdapter;
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
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\RedirectException;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PolicySearchType;
use AppBundle\Validator\Constraints\FullName;

use MongoRegex;
use Gedmo\Loggable\Document\LogEntry;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use AppBundle\Validator\Constraints\AgeValidator;
use AppBundle\Service\SixpackService;

abstract class BaseController extends Controller
{
    use PhoneTrait;
    use TargetPathTrait;
    use ControllerTrait;
    use DateTrait;

    const MONGO_QUERY_TYPE_REGEX = 'regex';
    const MONGO_QUERY_TYPE_EQUAL = 'equal';
    const MONGO_QUERY_TYPE_ID = 'id';
    const MONGO_QUERY_TYPE_DAY = 'day';
    const MONGO_QUERY_TYPE_MOBILE = 'mobile';

    public function isDataStringPresent($data, $field)
    {
        return mb_strlen($this->getDataString($data, $field)) > 0;
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

    protected function getPicsureMLManager()
    {
        return $this->get('doctrine_mongodb.odm.picsureml_document_manager');
    }

    protected function getCognitoIdentityId(Request $request)
    {
        $auth = $this->get('app.user.cognitoidentity.authenticator');

        return $auth->getCognitoIdentityId($request->getContent());
    }

    protected function getCognitoIdentityIp(Request $request)
    {
        /** @var CognitoIdentityAuthenticator $auth */
        $auth = $this->get('app.user.cognitoidentity.authenticator');

        return $auth->getCognitoIdentityIp((string) $request->getContent());
    }

    protected function getCognitoIdentitySdk(Request $request)
    {
        /** @var CognitoIdentityAuthenticator $auth */
        $auth = $this->get('app.user.cognitoidentity.authenticator');

        return $auth->getCognitoIdentitySdk((string) $request->getContent());
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
            $phones[$phone->getMake()][$phone->getModel()][$phone->getId()] = [
                'memory' => $phone->getMemory(), 'featured' => $phone->isHighlight()
            ];
            if ($phone->getAlternativeMake()) {
                if (!in_array($phone->getAlternativeMake(), $makes)) {
                    $makes[] = $phone->getAlternativeMake();
                }
                $phones[$phone->getAlternativeMake()][$phone->getModel()][$phone->getId()] = [
                    'memory' => $phone->getMemory(), 'featured' => $phone->isHighlight()
                ];
            }
        }

        return $phones;
    }

    /**
     * @param string $type simple, all, highlight
     */
    protected function getPhonesSearchArray($type = 'all')
    {
        $makes = [];
        $phones = [];
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $items = $repo->findActive()->getQuery()->execute();
        foreach ($items as $phone) {
            if ($type == 'simple') {
                $phones[] = [
                    'id' => $phone->getId(),
                    'name' => $phone->__toString(),
                ];
            } else {
                $phones[sprintf('%s %s', $phone->getMake(), $phone->getModel())][] = [
                    'id' => $phone->getId(),
                    'memory' => $phone->getMemory(),
                    'highlight' => $phone->isHighlight(),
                ];
            }
        }

        if ($type != 'simple') {
            $transformedPhones = [];
            foreach ($phones as $name => $data) {
                $highlight = false;
                foreach ($data as $item) {
                    if ($item['highlight']) {
                        $highlight = true;
                    }
                }
                $results = [
                    'id' => $data[0]['id'],
                    'name' => $name,
                    'sizes' => $data,
                ];
                if ($highlight) {
                    $results['highlight'] = $name;
                }
                $transformedPhones[] = $results;
            }

            $phones = $transformedPhones;
        }

        return $phones;
    }

    /**
     * Get the best matching phone.
     * Assuming that memory will be a bit less than actual advertised size, but find the closest matching
     *
     * @param string|null $make
     * @param string      $device see googe play device list (or apple phone list)
     * @param float       $memory in gb
     *
     * @return Phone|null
     */
    protected function getPhone($make, $device, $memory, $ignoreMake = false)
    {
        /** @var QuoteService $quoteService */
        $quoteService = $this->get('app.quote');
        $quotes = $quoteService->getQuotes($make, $device, null, null, $ignoreMake);
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
     * @param Builder $qb
     * @param integer $maxPerPage
     *
     * @return Pagerfanta
     */
    protected function pager(Request $request, Builder $qb, $maxPerPage = 50)
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
     * @param array   $array
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
            if (is_array($data[$field]) || mb_strlen(trim($data[$field])) == 0) {
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
            if (mb_strlen($this->getRequestString($request, $field)) == 0) {
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
     * @param integer $errorCode
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

    protected function formToMongoSearch(
        $form,
        $qb,
        $formField,
        $mongoField,
        $run = false,
        $queryType = self::MONGO_QUERY_TYPE_REGEX
    ) {
        $data = (string) $form->get($formField)->getData();

        return $this->dataToMongoSearch($qb, $data, $mongoField, $run, $queryType);
    }

    protected function dataToMongoSearch(
        $qb,
        $data,
        $mongoField,
        $run = false,
        $queryType = self::MONGO_QUERY_TYPE_REGEX
    ) {
        if (mb_strlen($data) == 0) {
            return null;
        }

        if ($queryType == self::MONGO_QUERY_TYPE_REGEX) {
            // Escape special chars
            $data = preg_quote($data, '/');
            $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals(new MongoRegex(sprintf("/.*%s.*/i", $data))));
        } elseif ($queryType == self::MONGO_QUERY_TYPE_EQUAL) {
            $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals($data));
        } elseif ($queryType == self::MONGO_QUERY_TYPE_MOBILE) {
            $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals($this->normalizeUkMobile($data)));
        } elseif ($queryType == self::MONGO_QUERY_TYPE_DAY) {
            if ($this->isValidDate($data)) {
                $date = $this->createValidDate($data);
                $qb = $qb->addAnd($qb->expr()->field($mongoField)->gte($this->startOfDay($date)));
                $qb = $qb->addAnd($qb->expr()->field($mongoField)->lt($this->endOfDay($date)));
            } else {
                $this->addFlash('warning', sprintf('Invalid date format %s', $data));
            }
        } elseif ($queryType == self::MONGO_QUERY_TYPE_ID) {
            if (\MongoId::isValid($data)) {
                $qb = $qb->addAnd($qb->expr()->field($mongoField)->equals(new \MongoId($data)));
            }
        } else {
            throw new \Exception('unknown query type');
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
        $now = \DateTime::createFromFormat('U', time());
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
        if (mb_strlen($referer) > 0) {
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

        /** @var MaxMindIpService $geoip */
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
        $sdk = $this->getCognitoIdentitySdk($request);
        if ($sdk != IdentityLog::SDK_UNKNOWN) {
            $identityLog->setSdk($sdk);
        }

        return $identityLog;
    }

    protected function getIdentityLogWeb(Request $request)
    {
        /** @var MaxMindIpService $geoip */
        $geoip = $this->get('app.geoip');
        $identityLog = $geoip->getIdentityLog($request->getClientIp());
        $identityLog->setSdk(IdentityLog::SDK_WEB);

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

            if (isset($data['paymentMethod'])) {
                if (isset($data['paymentMethod']['bankAccount']['sortCode'])) {
                    $data['paymentMethod']['bankAccount']['sortCode'] = "**Redacted**";
                }
                if (isset($data['paymentMethod']['bankAccount']['accountNumber'])) {
                    $data['paymentMethod']['bankAccount']['accountNumber'] = "**Redacted**";
                }
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
            foreach ($errors as $error) {
                switch (get_class($error->getConstraint())) {
                    case Email::class:
                        throw new InvalidEmailException($error->getMessage());
                        break;
                    case FullName::class:
                        throw new InvalidFullNameException($error->getMessage());
                        break;
                }
            }
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

    /**
     * @param Request $request
     * @return Phone|null
     */
    protected function getSessionQuotePhone(Request $request)
    {
        $dm = $this->getManager();
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);

        /** @var Phone $phone */
        $phone = null;
        $session = $request->getSession();
        if ($session && $session->get('quote')) {
            /** @var Phone $phone */
            $phone = $phoneRepo->find($session->get('quote'));
        }

        return $phone;
    }

    /**
     * @param Request $request
     * @param Phone   $phone
     */
    protected function setSessionQuotePhone(Request $request, Phone $phone)
    {
        $session = $request->getSession();
        if ($session && !$session->get('quote')) {
            $session->set('quote', $phone->getId());
        }
    }

    protected function getSessionSixpackTest(Request $request, $name, $options)
    {
        $exp = null;
        $sessionName = sprintf('sixpack:%s', $name);

        $session = $request->getSession();
        if ($session) {
            $exp = $session->get($sessionName);
        }

        if (!$exp) {
            $exp = $this->sixpack($request, $name, $options);
            if ($session) {
                $session->set($sessionName, $exp);
            }
        }

        $override = $request->get('force');
        if ($override && in_array($override, $options)) {
            $exp = $override;
        }

        return $exp;
    }

    protected function searchPolicies(Request $request, $includeInvalidPolicies = null)
    {
        $dm = $this->getManager();
        /** @var UserRepository $userRepo */
        $userRepo = $dm->getRepository(User::class);
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        /** @var Builder $policiesQb */
        $policiesQb = $policyRepo->createQueryBuilder()
            ->eagerCursor(true)
            ->field('user')->prime(true);
        $form = $this->createForm(PolicySearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        if ($includeInvalidPolicies === null) {
            if (!empty($form->getData('id'))) {
                $includeInvalidPolicies = true;
            } else {
                $includeInvalidPolicies = $form->get('invalid')->getData();
            }
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
        } elseif ($status == 'call') {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('status')->in([Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'called') {
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('notesList.type')->equals('call')
            );
            $oneWeekAgo = \DateTime::createFromFormat('U', time());
            $oneWeekAgo = $oneWeekAgo->sub(new \DateInterval('P7D'));
            $policiesQb = $policiesQb->addAnd(
                $policiesQb->expr()->field('notesList.date')->gte($oneWeekAgo)
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
            $this->formToMongoSearch(
                $form,
                $policiesQb,
                'status',
                'status',
                false,
                self::MONGO_QUERY_TYPE_EQUAL
            );
        }

        $this->formToMongoSearch($form, $policiesQb, 'policy', 'policyNumber');
        $this->formToMongoSearch($form, $policiesQb, 'imei', 'imei');
        $this->formToMongoSearch(
            $form,
            $policiesQb,
            'id',
            '_id',
            false,
            self::MONGO_QUERY_TYPE_ID
        );
        $this->formToMongoSearch($form, $policiesQb, 'serial', 'serialNumber');
        if ($form->get('phone')->getData()) {
            $this->dataToMongoSearch(
                $policiesQb,
                $form->get('phone')->getData()->getId(),
                'phone.$id',
                false,
                self::MONGO_QUERY_TYPE_ID
            );
        }

        if (!$includeInvalidPolicies) {
            $policy = new PhonePolicy();
            $search = sprintf('%s/', $policy->getPolicyNumberPrefix());
            $this->dataToMongoSearch($policiesQb, $search, 'policyNumber');
        }

        $userFormData = [
            'email' => 'emailCanonical',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'postcode' => 'billingAddress.postcode',
            'facebookId' => 'facebookId',
            'paymentMethod' => 'paymentMethod.type',
            'bacsReference' => 'paymentMethod.bankAccount.reference'
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
                $policiesQb->addAnd(
                    $policiesQb->expr()->addOr(
                        $policiesQb->expr()->field('user.id')->in($ids),
                        $policiesQb->expr()->field('namedUser.id')->in($ids)
                    )
                );
            }
        }
        $users = $this->dataToMongoSearch(
            $userRepo->createQueryBuilder(),
            $this->normalizeUkMobile((string) $form->get('mobile')->getData()),
            'mobileNumber',
            true
        );
        if ($users) {
            $ids = [];
            foreach ($users as $user) {
                $ids[] = $user->getId();
            }
            if (count($ids) > 0) {
                $policiesQb->addAnd(
                    $policiesQb->expr()->addOr(
                        $policiesQb->expr()->field('user.id')->in($ids),
                        $policiesQb->expr()->field('namedUser.id')->in($ids)
                    )
                );
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
        } elseif ($status == 'call') {
            $policies = $policiesQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                /** @var Policy $policy */
                $fourteenDays = \DateTime::createFromFormat('U', time());
                $fourteenDays = $fourteenDays->sub(new \DateInterval('P14D'));
                $sevenDays = \DateTime::createFromFormat('U', time());
                $sevenDays = $fourteenDays->sub(new \DateInterval('P7D'));

                // 14 days & no calls or 7 days & at most 1 call
                if ($policy->getPolicyExpirationDateDays() <= 14 && $policy->getNoteCalledCount($fourteenDays) == 0) {
                    return true;
                } elseif ($policy->getPolicyExpirationDateDays() <= 7 &&
                    $policy->getNoteCalledCount($fourteenDays) <= 1) {
                    return true;
                } else {
                    return false;
                }
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
            $pager = $this->arrayPager($request, $policies);
        } else {
            $pager = $this->pager($request, $policiesQb);
        }

        return [
            'policies' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'form' => $form->createView(),
            'status' => $status,
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
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'postcode' => 'billingAddress.postcode',
            'mobile' => 'mobileNumber',
            'facebookId' => 'facebookId',
            'dob' => 'birthday',
        ];
        foreach ($userFormData as $formField => $dataField) {
            $queryType = self::MONGO_QUERY_TYPE_REGEX;
            if ($dataField == 'birthday') {
                $queryType = self::MONGO_QUERY_TYPE_DAY;
            } elseif ($dataField == 'mobileNumber') {
                $queryType = self::MONGO_QUERY_TYPE_MOBILE;
            }
            $this->formToMongoSearch(
                $form,
                $usersQb,
                $formField,
                $dataField,
                false,
                $queryType
            );
        }
        $this->formToMongoSearch(
            $form,
            $usersQb,
            'id',
            '_id',
            false,
            self::MONGO_QUERY_TYPE_ID
        );
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

    protected function searchClaims(Request $request)
    {
        $dm = $this->getManager();
        /** @var User $user */
        $user = $this->getUser();
        $repo = $dm->getRepository(Claim::class);
        $qb = $repo->createQueryBuilder();

        $form = $this->createForm(ClaimSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $status = $form->get('status')->getData();
        $claimNumber = $form->get('number')->getData();
        $claimId = $form->get('id')->getData();
        $handlingTeam = $form->get('handlingTeam')->getData();
        $qb = $qb->field('status')->in($status);
        if (mb_strlen($claimNumber) > 0) {
            $qb = $qb->field('number')->equals(new MongoRegex(sprintf("/.*%s.*/i", $claimNumber)));
        }
        if (mb_strlen($claimId) > 0 && \MongoId::isValid($claimId)) {
            $qb = $qb->field('id')->equals(new \MongoId($claimId));
        }
        if ($user->getHandlingTeam()) {
            $qb = $qb->field('handlingTeam')->equals($user->getHandlingTeam());
        } elseif ($handlingTeam) {
            $qb = $qb->field('handlingTeam')->equals($handlingTeam);
        }
        $qb = $qb->sort('replacementReceivedDate', 'desc')
            ->sort('approvedDate', 'desc')
            ->sort('lossDate', 'desc')
            ->sort('notificationDate', 'desc');
        $pager = $this->pager($request, $qb);

        return [
            'claims' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'phones' => $dm->getRepository(Phone::class)->findActiveInactive()->getQuery()->execute(),
            'claim_types' => Claim::$claimTypes,
            'form' => $form->createView(),
        ];
    }

    protected function isProduction()
    {
        return $this->getParameter('kernel.environment') == 'prod';
    }

    protected function sixpack(
        $request,
        $name,
        $options,
        $logMixpanel = SixpackService::LOG_MIXPANEL_CONVERSION,
        $clientId = null,
        $trafficFraction = 1,
        $force = null
    ) {
        $exp = $this->get('app.sixpack')->participate(
            $name,
            $options,
            $logMixpanel,
            $trafficFraction,
            $clientId,
            $force
        );
        $override = $request->get('force');
        if ($override && in_array($override, $options)) {
            $exp = $override;
        }

        return $exp;
    }

    protected function sixpackSimple($name, Request $request = null)
    {
        $sixpack = $this->get('app.sixpack');
        $exp = $sixpack->runningSixpackExperiment($name);

        if ($request) {
            $override = $request->get('force');
            if ($override && in_array($override, $sixpack->getOptionsAvailable($name))) {
                return $override;
            }
        }

        return $exp;
    }

    protected function isRealUSAIp(Request $request)
    {
        $geoip = $this->get('app.geoip');
        $ip = $request->getClientIp();
        //$ip = "72.229.28.185";
        $userAgent = $request->headers->get('User-Agent');

        // make sure to exclude us based bots that import content - eg. facebook/twitter
        // https://developers.facebook.com/docs/sharing/webmasters/crawler
        // https://dev.twitter.com/cards/getting-started#crawling
        return !preg_match("/Twitterbot|facebookexternalhit|Facebot/i", $userAgent) &&
            $geoip->findCountry($ip) == "US";
    }

    protected function getQuerystringPhone(Request $request)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);

        $phone = null;
        $phoneName = (string) $request->get('phone');
        $matches = null;
        if (preg_match('/([^ ]+) (.*) ([0-9]+)GB/', $phoneName, $matches) !== false && count($matches) >= 3) {
            $decodedModel = Phone::decodeModel($matches[2]);
            $phone = $phoneRepo->findOneBy([
                'active' => true,
                'make' => $matches[1],
                'model' => $decodedModel,
                'memory' => (int) $matches[3]
            ]);
        }

        return $phone;
    }

    protected function setPhoneSession(Request $request, Phone $phone)
    {
        if ($phone->getMemory()) {
            $url = $this->generateUrl('quote_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        } else {
            $url = $this->generateUrl('quote_make_model', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
            ], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $session = $request->getSession();
        if ($session) {
            $session->set('quote', $phone->getId());
            $session->set('quote_url', $url);
        }

        return $url;
    }

    protected function starlingOAuthSession(Request $request, $targetPath = null)
    {
        /** @var Session $session */
        $session = $request->getSession();
        if ($session) {
            $session->set('oauth2Flow', 'starling');
            // our local copy of the target path, to use to go back to the oauth2/v2/auth page
            if (!$targetPath) {
                $targetPath = $this->getTargetPath($session, 'main');
            }
            $session->set('oauth2Flow.targetPath', $targetPath);
        }
    }

    protected function policyDownloadFile($id, $attachment = false)
    {
        $dm = $this->getManager();
        /** @var S3FileRepository $repo */
        $repo = $dm->getRepository(S3File::class);
        /** @var S3File $s3File */
        $s3File = $repo->find($id);
        if (!$s3File) {
            throw new NotFoundHttpException();
        }

        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
        $environment = $this->getParameter('kernel.environment');
        $file = str_replace(sprintf('%s/', $environment), '', $s3File->getKey());

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException(sprintf('URL not found %s', $file));
        }

        $headers = [
            'Content-Type' => $filesystem->getMimetype($file)
        ];
        if ($attachment) {
            $headers['Content-Disposition'] = sprintf('attachment; filename="%s"', $s3File->getFilename());
        }
        return StreamedResponse::create(
            function () use ($file, $filesystem) {
                $stream = $filesystem->readStream($file);
                echo stream_get_contents($stream);
                flush();
            },
            200,
            $headers
        );
    }

    protected function phoneAlternatives($id)
    {
        $dm = $this->getManager();
        /** @var PhoneRepository $repo */
        $repo = $dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $repo->find($id);
        $alternatives = [];
        $suggestedReplacement = null;
        if ($phone) {
            $alternatives = $repo->alternatives($phone);
            if ($phone->getSuggestedReplacement()) {
                /** @var Phone $suggestedReplacement */
                $suggestedReplacement = $repo->find($phone->getSuggestedReplacement()->getId());
            }
        }

        $data = [];
        foreach ($alternatives as $alternative) {
            /** @var Phone $alternative */
            $data[] = $alternative->toAlternativeArray();
        }

        return new JsonResponse([
            'alternatives' => $data,
            'suggestedReplacement' => $suggestedReplacement ? $suggestedReplacement->toAlternativeArray() : null,
        ]);
    }

    /**
     * @return PolicyTerms
     */
    protected function getLatestPolicyTerms()
    {
        $dm = $this->getManager();
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        return $latestTerms;
    }

    protected function claimsNotes(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        /** @var ClaimRepository $repo */
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->find($id);
        $validator = new AlphanumericSpaceDotValidator();
        $claim->setNotes($validator->conform($this->getRequestString($request, 'notes')));

        $dm->flush();
        $this->addFlash(
            'success',
            'Claim notes updated'
        );

        $redirectRoute = 'claims_policy';
        /** @var User $user */
        $user = $this->getUser();
        if ($user->hasEmployeeRole()) {
            $redirectRoute = 'admin_policy';
        }

        return new RedirectResponse($this->generateUrl($redirectRoute, ['id' => $claim->getPolicy()->getId()]));
    }
}
