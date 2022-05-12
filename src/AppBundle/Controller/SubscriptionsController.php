<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

use AppBundle\Classes\ApiErrorCode;

use AppBundle\Document\Lead;
use AppBundle\Document\User;

use AppBundle\Exception\InvalidEmailException;

use AppBundle\Service\MixpanelService;

class SubscriptionsController extends BaseController
{
    /**
     * @Route("/subscribed", name="subscribed_simple")
     * @Route("/subscribed/{email}", name="subscribed_with_details")
     */
    public function subscribedAction($email = null)
    {
        $template = 'AppBundle:Subscriptions:subscribed.html.twig';

        // Is indexed?
        $noindex = false;

        // Always use page load event
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
            'Page' => 'subscribed_page',
            'Step' => 'subscribed'
        ]);

        $data = [
            'is_noindex' => $noindex,
            'email' => $email,
        ];

        return $this->render($template, $data);
    }
}
