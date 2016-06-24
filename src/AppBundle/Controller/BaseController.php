<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Classes\ApiErrorCode;

use MongoRegex;
use Gedmo\Loggable\Document\LogEntry;

abstract class BaseController extends Controller
{
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

    protected function getQuotes($make, $device, $returnAllIfNone = true)
    {
        // TODO: We should probably be checking make as well.  However, we need to analyize the data
        // See Phone::isSameMake()
        \AppBundle\Classes\NoOp::noOp([$make]);

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $device]);
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
            if (strlen(trim($request->get($field))) == 0) {
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
        return new JsonResponse(['code' => $errorCode, 'description' => $description], $httpCode);
    }

    protected function formToMongoSearch($form, $qb, $formField, $mongoField, $run = false)
    {
        $data = $form->get($formField)->getData();
        if (strlen($data) == 0) {
            return null;
        }

        // Escape special chars
        $data = preg_quote($data, '/');
        $qb = $qb->field($mongoField)->equals(new MongoRegex(sprintf("/.*%s.*/", $data)));
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
        $birthday = \DateTime::createFromFormat(\DateTime::ATOM, $data['birthday']);
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

    protected function getIdentityLog(Request $request)
    {
        // NOTE: not completely secure, but as we're only using for an indication, it's good enough
        // http://docs.aws.amazon.com/apigateway/latest/developerguide/api-gateway-mapping-template-reference.html
        // https://forums.aws.amazon.com/thread.jspa?messageID=673393
        $clientIp = $this->getCognitoIdentityIp($request);

        $geoip = $this->get('app.geoip');

        return $geoip->getIdentityLog($clientIp, $this->getCognitoIdentityId($request));
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

    protected function getPhonePolicyHistory($policyId)
    {
        $dm = $this->getManager();
        $logRepo = $dm->getRepository(LogEntry::class);
        $policyHistory = $logRepo->findBy(['objectClass' => PhonePolicy::class, 'objectId' => $policyId]);

        return $policyHistory;
    }
}
