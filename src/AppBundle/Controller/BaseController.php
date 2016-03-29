<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use AppBundle\Document\User;

abstract class BaseController extends Controller
{
    protected function getManager()
    {
        return $this->get('doctrine_mongodb')->getManager();
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
            if (!isset($data[$field]) || strlen(trim($data[$field])) == 0) {
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
}
