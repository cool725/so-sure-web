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
        return new RedirectResponse($this->generateUrl('quote_make_model', [
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

    /**
     * @Route("/starling", name="starling_landing")
     */
    public function starlingAction(Request $request)
    {
        //$this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);
        $session = $request->getSession();
        if ($session && $session->isStarted()) {
            $session->set('partner', 'starling');
        }

        $data = array(
            'device_category' => $this->get('app.request')->getDeviceCategory(),
            'partner' => 'starling',
        );

        return $this->render('AppBundle:Default:indexV2.html.twig', $data);
    }

    /**
     * @Route("/replacement-24", name="replacement_24_landing")
     */
    public function replacement24Action(Request $request)
    {
        $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_PHONE_REPLACEMENT_MATCHING_ADVERT,
            ['default', 'next-working-day', 'seventytwo-hours'],
            SixpackService::LOG_MIXPANEL_CONVERSION,
            null,
            1,
            'next-working-day'
        );

        return new RedirectResponse($this->generateUrl('homepage'));
    }

    /**
     * @Route("/replacement-72", name="replacement_72_landing")
     */
    public function replacement72Action(Request $request)
    {
        $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_PHONE_REPLACEMENT_MATCHING_ADVERT,
            ['default', 'next-working-day', 'seventytwo-hours'],
            SixpackService::LOG_MIXPANEL_CONVERSION,
            null,
            1,
            'seventytwo-hours'
        );

        return new RedirectResponse($this->generateUrl('homepage'));
    }
}
