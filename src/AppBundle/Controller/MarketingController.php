<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;

class MarketingController extends BaseController
{
    /**
     * @Route("/iphone8", name="iphone8_redirect")
     */
    public function iPhone8RedirectAction()
    {
        return new RedirectResponse($this->generateUrl('phone_insurance_make_model', [
            'make' => 'apple',
            'model' => 'iphone+8',
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'iPhone8',
        ]));
    }

    /**
     * @Route("/trinitymaxwell", name="trinitiymaxwell_redirect")
     */
    public function trinityMaxwellAction()
    {
        return new RedirectResponse($this->generateUrl('homepage', [
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'trinitiymaxwell',
        ]));
    }
}
