<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Service\MixpanelService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/ops")
 */
class OpsController extends BaseController
{
    /**
     * @Route("/status", name="ops_status")
     * @Template
     */
    public function statusAction()
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find(1);

            // Ensure there's a bit of free disk space
            $temp = tmpfile();
            fwrite($temp, "t");
            fclose($temp);

            $response = new JsonResponse([
                'status' => 'Ok',
            ]);

            // Disable HSTS for status check - otherwise elb healthcheck will fail
            $response->headers->set('Strict-Transport-Security', 'max-age=0');

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'Error',
            ], 500);
        }
    }

    /**
     * @Route("/exception", name="ops_exception")
     */
    public function exceptionAction()
    {
        throw new \Exception('Exception');
    }

    /**
     * @Route("/csp-warning", name="ops_csp_warning")
     * @Template()
     */
    public function cspWarningAction()
    {
        return [];
    }

    /**
     * @Route("/exception503", name="ops_exception_503")
     */
    public function exceptionDeniedAction()
    {
        throw new HttpException(503);
    }

    /**
     * @Route("/pages", name="ops_pages")
     * @Template()
     */
    public function validatePagesAction()
    {
        if ($this->isProduction()) {
            throw $this->createAccessDeniedException('Only for dev use');
        }

        $dm = $this->getManager();
        $scodeRepo = $dm->getRepository(SCode::class);
        $scode = $scodeRepo->findOneBy(['active' => true, 'type' => 'standard']);

        $invitationRepo = $dm->getRepository(EmailInvitation::class);
        $invitation = $invitationRepo->findOneBy(['accepted' => null, 'rejected' => null, 'cancelled' => null]);

        $policyRepo = $dm->getRepository(Policy::class);
        $expiredPolicy = $policyRepo->findOneBy(['status' => Policy::STATUS_EXPIRED]);
        $unpaidPolicy = $policyRepo->findOneBy(['status' => Policy::STATUS_UNPAID]);
        $validPolicies = $policyRepo->findBy(['status' => Policy::STATUS_ACTIVE]);
        $position = rand(1, count($validPolicies));
        foreach ($validPolicies as $validPolicy) {
            if ($position <= 0 && !$validPolicy->hasMonetaryClaimed()) {
                break;
            }
            $position--;
        }
        foreach ($validPolicies as $validMultiplePolicy) {
            $user = $validMultiplePolicy->getUser();
            if (count($user->getValidPolicies(true)) > 1 && $user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
                break;
            }
        }
        foreach ($validPolicies as $validRenwalPolicy) {
            $user = $validRenwalPolicy->getUser();
            if ($user->canRenewPolicy() && $validRenwalPolicy->isInRenewalTimeframe()) {
                break;
            } else {
                $validRenwalPolicy = null;
            }
        }
        foreach ($validPolicies as $validRemainderPolicy) {
            if ($validRemainderPolicy->getPremiumPlan() == Policy::PLAN_MONTHLY &&
                $validRemainderPolicy->getOutstandingPremiumToDate() > 0) {
                break;
            } else {
                $validRemainderPolicy = null;
            }
        }
        foreach ($validPolicies as $claimedPolicy) {
            if ($claimedPolicy->hasMonetaryClaimed()) {
                break;
            } else {
                $claimedPolicy = null;
            }
        }
        $cancelledPolicy = $policyRepo->findOneBy(['status' => Policy::STATUS_CANCELLED]);

        return [
            'scode' => $scode->getCode(),
            'invitation' => $invitation,
            'unpaid_policy' => $unpaidPolicy,
            'valid_policy' => $validPolicy,
            'cancelled_policy' => $cancelledPolicy,
            'valid_multiple_policy' => $validMultiplePolicy,
            'valid_renewal_policy' => $validRenwalPolicy,
            'valid_remainder_policy' => $validRemainderPolicy,
            'claimed_policy' => $claimedPolicy,
            'expired_policy' => $expiredPolicy,
        ];
    }

    /**
     * @Route("/track/invite/{event}", name="ops_track_invite")
     * @Route("/track/{event}", name="ops_track")
     */
    public function trackAction(Request $request, $event)
    {
        if ($request->get('_route') == 'ops_track_invite') {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_INVITE, [
                'Invitation Method' => 'web',
                'Shared Bundle' => $event,
            ]);
        } else {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_TEST, ['Test Name' => $event]);
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Queued', 200);
    }


    /**
     * @Route("/csp", name="ops_csp")
     */
    public function cspAction(Request $request)
    {
        $logger = $this->get('logger');
        $violationReport = $request->getContent();
        if (empty($violationReport)) {
            $logger->debug('Content-Security-Policy Endpoint called without data');

            return new Response('No report data sent?', 411);
        }

        $violationReport = json_decode($violationReport, true);
        if ($violationReport === null) {
            $logger->debug('Content-Security-Policy Endpoint called with invalid JSON data');

            return new Response('Invalid JSON data supplied?', 400);
        }

        if (!isset($violationReport['csp-report'])) {
            $logger->debug('Content-Security-Policy Endpoint called without "csp-report" data');

            return new Response('Invalid report data, no "csp-report" data supplied.', 400);
        }

        if (isset($violationReport['csp-report']['blocked-uri'])) {
            $host = strtolower(parse_url($violationReport['csp-report']['blocked-uri'], PHP_URL_HOST));
            if (in_array($host, [
                'nikkomsgchannel',
                'www.bizographics.com',
                'secure.adnxs.com',
                'gb.api4load.com',
                'af.adsloads.com',
                'ph.adsloads.com',
                'cdn.stats-collector.org',
                'translate.googleapis.com',
                'client.comprigo.com',
                'www.video2mp3.at',
                's3.amazonaws.com',
                'junglenet-a.akamaihd.net',
                'del.icio.us',
                'loadingpages.me',
                'savingsslider-a.akamaihd.net',
                'cdncache-a.akamaihd.net',
            ])) {
                $logger->debug(sprintf('Content-Security-Policy called with ignore host: %s', $host));

                return new Response('', 204);
            }

            $scheme = strtolower(parse_url($violationReport['csp-report']['blocked-uri'], PHP_URL_SCHEME));
            if (in_array($scheme, [
                'ms-appx-web',
                'none',
                'about',
                'asset',
            ])) {
                $logger->debug(sprintf('Content-Security-Policy called with ignore scheme: %s', $scheme));

                return new Response('', 204);
            }
        }

        // any violation on http are not relivant - should only be https
        if (isset($violationReport['csp-report']['document-uri'])) {
            $host = strtolower(parse_url($violationReport['csp-report']['document-uri'], PHP_URL_HOST));
            $scheme = strtolower(parse_url($violationReport['csp-report']['document-uri'], PHP_URL_SCHEME));
            if ($scheme == "http" && $host == "wearesosure.com") {
                $logger->debug(sprintf('Content-Security-Policy called with non-https host: %s', $host));

                return new Response('', 204);
            }
        }

        // we can work out the original policy
        if (isset($violationReport['csp-report']['original-policy'])) {
            unset($violationReport['csp-report']['original-policy']);
        }
        $this->get('snc_redis.default')->rpush('csp', json_encode($violationReport));
        $logger->debug(
            'Content-Security-Policy Violation Reported',
            $violationReport
        );

        return new Response('', 204);
    }
}
