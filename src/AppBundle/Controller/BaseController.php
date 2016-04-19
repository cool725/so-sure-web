<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\JsonResponse;
use MongoRegex;

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

        // most phones don't care about memory - only 1 entry to return
        if (count($phones) == 1) {
            return $phones[0];
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
            if ($memory < $phone->getMemory()) {
                return $phone;
            }
        }

        return $phones[count($phones) - 1];
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

    protected function formToMongoSearch($form, $qb, $formField, $mongoField)
    {
        $data = $form->get($formField)->getData();
        if (strlen($data) > 0) {
            $qb = $qb->field($mongoField)->equals(new MongoRegex(sprintf("/.*%s.*/", $data)));
        }
    }
}
