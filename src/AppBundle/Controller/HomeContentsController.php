<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

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
     */
    public function contentsInsuranceAction(Request $request)
    {
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        // Temp
        $promo = false;

        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'contents_insurance_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - LP']);
        } elseif ($request->get('_route') == 'contents_insurance_getmyslice') {
            $noindex = true;
            $promo = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Contents Insurance - Get My Slice']);
        } else {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CONTENTS_INSURANCE_HOME_PAGE);
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
                'Page' => 'landing_page',
                'Step' => 'contents_insurance'
            ]);
        }

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

        // Optimise - pass along sskey
        if ($sskey) {
            $sskey = sprintf('&sskey=%s', $sskey);
            $utms = $utms . $sskey;
        }

        $template = 'AppBundle:ContentsInsurance:contentsInsurance.html.twig';
        if ($promo) {
            $template = 'AppBundle:ContentsInsurance:contentsInsurancePromo.html.twig';
        }

        $data = [
            'lead_csrf' => $csrf->refreshToken('lead'),
            'is_noindex' => $noindex,
            'utms' => $utms,
            'promo' => $promo,
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
