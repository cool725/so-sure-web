<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\PhoneType;

use AppBundle\Document\Address;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Sns;
use AppBundle\Document\User;

use AppBundle\Classes\ApiErrorCode;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/api/v1/auth")
 */
class ApiAuthController extends BaseController
{
    /**
     * @Route("/ping", name="api_auth_ping")
     * @Method({"GET", "POST"})
     */
    public function pingAuthAction()
    {
        try {
            return new JsonResponse(['pong' => 1]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api pingAuth. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}", name="api_auth_get_user")
     * @Method({"POST"})
     */
    public function getUserAction(Request $request, $id)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);
            if (!$user) {
                return new JsonResponse([], 404);
            }

            $this->denyAccessUnlessGranted('view', $user);

            return new JsonResponse($user->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getUser. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user/{id}/address", name="api_auth_add_user_address")
     * @Method({"POST"})
     *
    public function addUserAddressAction(Request $request, $id)
    {
        try {
            if (!$this->validateQueryFields($request, ['id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($id);

            $this->denyAccessUnlessGranted('view', $user);

            return new JsonResponse($user->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getUser. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
    /
}
