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
}
