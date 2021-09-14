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

class HomeContentsController extends BaseController
{
    /**
     * @Route("/contents-insurance", name="contents_insurance")
     * @Route("/contents-insurance/m", name="contents_insurance_m")
     * @Route("/contents-insurance/getmyslice", name="contents_insurance_getmyslice")
     * @Route("/contents-insurance/creditspring", name="contents_insurance_creditspring")
     * @Route("/contents-insurance/topcashback", name="contents_insurance_topcashback")
     * @Route("/contents-insurance/quidco", name="contents_insurance_quidco")
     * @Route("/contents-insurance/ppc", name="contents_insurance_ppc")
     */
    public function contentsInsuranceAction(Request $request)
    {
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        $promo = false;
        $partner = null;
        $code = null;
        $terms = false;
        $template = 'AppBundle:ContentsInsurance:contentsInsurance.html.twig';

        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'contents_insurance_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - LP']);
        } elseif ($request->get('_route') == 'contents_insurance_getmyslice') {
            $noindex = true;
            $promo = true;
            $partner = 'getmyslice';
            $template = 'AppBundle:ContentsInsurance:contentsInsurancePromo.html.twig';
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - Get My Slice']);
        } elseif ($request->get('_route') == 'contents_insurance_creditspring') {
            $noindex = true;
            $promo = true;
            $partner = 'creditspring';
            $code = 'HCCRED15';
            $terms = true;
            $template = 'AppBundle:ContentsInsurance:contentsInsurancePromo.html.twig';
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - Creditspring']);
        } elseif ($request->get('_route') == 'contents_insurance_topcashback') {
            $noindex = true;
            $promo = true;
            $partner = 'topcashback';
            $template = 'AppBundle:ContentsInsurance:contentsInsuranceComparison.html.twig';
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - Topcashback']);
        } elseif ($request->get('_route') == 'contents_insurance_quidco') {
            $noindex = true;
            $promo = true;
            $partner = 'quidco';
            $template = 'AppBundle:ContentsInsurance:contentsInsuranceComparison.html.twig';
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - Quidco']);
        } elseif ($request->get('_route') == 'contents_insurance_ppc') {
            $noindex = true;
            $promo = false;
            $partner = 'ppc';
            $template = 'AppBundle:ContentsInsurance:contentsInsuranceComparison.html.twig';
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - PPC']);
        } else {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CONTENTS_INSURANCE_HOME_PAGE);
        }

        // Always use page load event
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
            'Page' => 'landing_page',
            'Step' => 'contents_insurance'
        ]);

        // Pass along UTM params to web app for Mixapanel
        $utms = null;
        $source = $request->query->get('utm_source');
        $medium = $request->query->get('utm_medium');
        $campaign = $request->query->get('utm_campaign');
        $sskey = $request->query->get('sskey');

        if ($source || $medium || $campaign) {
            $source = urlencode($source);
            $medium = urlencode($medium);
            $campaign = urlencode($campaign);
            $utms = sprintf('utm_source=%s&utm_medium=%s&utm_campaign=%s', $source, $medium, $campaign);
        }

        // Set Greeting
        if (date('H') >= 12 && date('H') <= 18) {
            $greeting = 'afternoon';
        } elseif (date('H') > 18 && date('H') <= 22) {
            $greeting = 'evening';
        } elseif (date('H') > 22 && date('H') <= 5) {
            $greeting = 'night';
        } else {
            $greeting = 'morning';
        }

        $data = [
            'lead_csrf' => $csrf->refreshToken('lead'),
            'is_noindex' => $noindex,
            'utms' => $utms,
            'promo' => $promo,
            'partner' => $partner,
            'greeting' => $greeting,
            'code' => $code,
            'terms' => $terms,
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/contents-lead/{source}", name="contents_lead")
     */
    public function contentsLeadAction(Request $request, $source)
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->validateFields(
            $data,
            ['email', 'csrf']
        )) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        if (!$this->isCsrfTokenValid('lead', $data['csrf'])) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid csrf', 422);
        }

        $email = $this->getDataString($data, 'email');

        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $leadRepo = $dm->getRepository(Lead::class);
        $existingLead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        // Add tracking - always catpure lead as we need to verify if exisiting users signed up
        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_CONTENTS_LEAD_CAPTURE, [
            'email' => $email]);

        if (!$existingLead && !$existingUser) {
            $lead = new Lead();
            $lead->setSource($source);
            $lead->setEmail($email);

            try {
                $this->validateObject($lead);
            } catch (InvalidEmailException $e) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid email format', 200);
            }
                $dm->persist($lead);
                $dm->flush();
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }
}
