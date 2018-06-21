<?php

namespace AppBundle\Controller;

use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\SCodeRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\RequestService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Cashback;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Service\MixpanelService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Component\HttpFoundation\Response;
use VasilDakov\Postcode\Postcode;

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
            /** @var UserRepository $repo */
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
     * @Route("/rollbar-js-error", name="ops_rollbar_js_error")
     * @Template()
     */
    public function rollbarJsErrorAction()
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
     * @Route("/preview-prefetch", name="ops_preview_prefetch")
     */
    public function previewPrefetchAction()
    {
        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');

        $response = new JsonResponse([
            'status' => 'Ok',
            'excluded' => $requestService->isExcludedPreviewPrefetch(),
            'headers' => json_encode($requestService->getAllXHeaders()),
        ]);

        return $response;
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
        $validPolicy = null;
        $validPolicyMonthly = null;
        $unpaidValidPolicyMonthly = null;
        $validPolicyYearly = null;
        $validMultiplePolicy = null;
        $validRenwalPolicyMonthlyNoPot = null;
        $validRenwalPolicyYearlyNoPot = null;
        $validRenwalPolicyYearlyOnlyNoPot = null;
        $validRenwalPolicyYearlyOnlyWithPot = null;
        $validRemainderPolicy = null;
        $policyCancelledAndPaymentOwed = null;
        $claimedPolicy = null;
        $validRenwalPolicyMonthlyWithPot = null;
        $validRenwalPolicyYearlyWithPot = null;
        $multiPayPolicy = null;

        $dm = $this->getManager();
        /** @var SCodeRepository $scodeRepo */
        $scodeRepo = $dm->getRepository(SCode::class);
        /** @var SCode $scode */
        $scode = $scodeRepo->findOneBy(['active' => true, 'type' => 'standard']);

        /** @var EmailInvitationRepository $invitationRepo */
        $invitationRepo = $dm->getRepository(EmailInvitation::class);
        $invitation = $invitationRepo->findOneBy(['accepted' => null, 'rejected' => null, 'cancelled' => null]);

        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $activePhone = $phoneRepo->findOneBy(['active' => true]);
        $upcomingPhone = $phoneRepo->findOneBy(['active' => true, 'phonePrices' => null]);

        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        $fullyExpiredPolicies = $policyRepo->findBy(['status' => Policy::STATUS_EXPIRED]);
        $fullyExpiredPolicyNoCashback = null;
        $fullyExpiredPolicyCashback = null;
        foreach ($fullyExpiredPolicies as $fullyExpiredPolicy) {
            /** @var Policy $fullyExpiredPolicy */
            if (!$fullyExpiredPolicy->getCashback()) {
                $fullyExpiredPolicyNoCashback = $fullyExpiredPolicy;
            } elseif ($fullyExpiredPolicy->getCashback() &&
                $fullyExpiredPolicy->getCashback()->getStatus() == Cashback::STATUS_MISSING) {
                    $fullyExpiredPolicyCashback = $fullyExpiredPolicy;
            }
        }

        $expiredPolicies = $policyRepo->findBy(['status' => Policy::STATUS_EXPIRED_CLAIMABLE]);
        $expiredPolicyNoCashback = null;
        $expiredPolicyCashback = null;
        foreach ($expiredPolicies as $expiredPolicy) {
            /** @var Policy $expiredPolicy */
            if (!$expiredPolicy->getCashback()) {
                $expiredPolicyNoCashback = $expiredPolicy;
            } elseif ($expiredPolicy->getCashback() &&
                $expiredPolicy->getCashback()->getStatus() == Cashback::STATUS_MISSING) {
                    $expiredPolicyCashback = $expiredPolicy;
            }
        }

        $picSureApprovedPolicy = $policyRepo->findOneBy([
            'status' => Policy::STATUS_ACTIVE,
            'picSureStatus' => PhonePolicy::PICSURE_STATUS_APPROVED,
        ]);
        /** @var Policy $picSureRejectedPolicy */
        $picSureRejectedPolicy = $policyRepo->findOneBy([
            'status' => Policy::STATUS_ACTIVE,
            'picSureStatus' => PhonePolicy::PICSURE_STATUS_REJECTED,
        ]);
        $nonPicSurePolicy = $policyRepo->findOneBy([
            'policyTerms.$id' => ['$ne' => new \MongoId($picSureRejectedPolicy->getPolicyTerms()->getId())],
        ]);
        $unpaidPolicies = $policyRepo->findBy([
            'status' => Policy::STATUS_UNPAID,
        ]);
        $unpaidPolicyBacs = null;
        foreach ($unpaidPolicies as $unpaidPolicyBacs) {
            /** @var Policy $unpaidPolicyBacs */
            if (!$unpaidPolicyBacs->isPolicyPaidToDate() && !$unpaidPolicyBacs->hasPolicyDiscountPresent() &&
                count($unpaidPolicyBacs->getUser()->getValidPolicies(true)) == 1 &&
                $unpaidPolicyBacs->getUser()->hasBacsPaymentMethod()) {
                break;
            } else {
                $unpaidPolicyBacs = null;
            }
        }
        $unpaidPolicyJudo = null;
        foreach ($unpaidPolicies as $unpaidPolicyJudo) {
            /** @var Policy $unpaidPolicyJudo */
            if (!$unpaidPolicyJudo->isPolicyPaidToDate() && !$unpaidPolicyJudo->hasPolicyDiscountPresent() &&
                count($unpaidPolicyJudo->getUser()->getValidPolicies(true)) == 1 &&
                $unpaidPolicyJudo->getUser()->hasJudoPaymentMethod()) {
                break;
            } else {
                $unpaidPolicyJudo = null;
            }
        }
        $unpaidPolicyDiscountPolicy = $policyRepo->findOneBy([
            'status' => Policy::STATUS_UNPAID,
            'policyDiscountPresent' => true,
        ]);
        $validPolicies = $policyRepo->findBy(['status' => Policy::STATUS_ACTIVE]);
        $cancelledPolicies = $policyRepo->findBy(['status' => Policy::STATUS_CANCELLED]);
        $position = rand(1, count($validPolicies));
        foreach ($validPolicies as $validPolicy) {
            /** @var Policy $validPolicy */
            if ($position <= 0 && !$validPolicy->hasMonetaryClaimed()) {
                break;
            }
            $position--;
        }
        foreach ($validPolicies as $validPolicyMonthly) {
            /** @var Policy $validPolicyMonthly */
            if (!$validPolicyMonthly->hasMonetaryClaimed() &&
                $validPolicyMonthly->getPremiumPlan() == Policy::PLAN_MONTHLY &&
                count($validPolicyMonthly->getUser()->getValidPolicies(true)) == 1 &&
                $validPolicyMonthly->isPolicyPaidToDate(new \DateTime())) {
                break;
            } else {
                $validPolicyMonthly = null;
            }
        }
        foreach ($validPolicies as $unpaidValidPolicyMonthly) {
            /** @var Policy $unpaidValidPolicyMonthly */
            if (!$unpaidValidPolicyMonthly->hasMonetaryClaimed() &&
                $unpaidValidPolicyMonthly->getPremiumPlan() == Policy::PLAN_MONTHLY &&
                count($unpaidValidPolicyMonthly->getUser()->getValidPolicies(true)) == 1 &&
                !$unpaidValidPolicyMonthly->isPolicyPaidToDate(new \DateTime())) {
                break;
            } else {
                $unpaidValidPolicyMonthly = null;
            }
        }
        foreach ($validPolicies as $validPolicyYearly) {
            /** @var Policy $validPolicyYearly */
            if (!$validPolicyYearly->hasMonetaryClaimed() &&
                $validPolicyYearly->getPremiumPlan() == Policy::PLAN_YEARLY) {
                break;
            } else {
                $validPolicyYearly = null;
            }
        }
        foreach ($validPolicies as $validMultiplePolicy) {
            /** @var Policy $validMultiplePolicy */
            $user = $validMultiplePolicy->getUser();
            if (count($user->getValidPolicies(true)) > 1 && $user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
                break;
            } else {
                $validMultiplePolicy = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyMonthlyNoPot) {
            /** @var Policy $validRenwalPolicyMonthlyNoPot */
            if ($this->policyRenewalStatus($validRenwalPolicyMonthlyNoPot, false, Policy::PLAN_MONTHLY)) {
                break;
            } else {
                $validRenwalPolicyMonthlyNoPot = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyYearlyNoPot) {
            /** @var Policy $validRenwalPolicyYearlyNoPot */
            if ($this->policyRenewalStatus($validRenwalPolicyYearlyNoPot, false, Policy::PLAN_YEARLY)) {
                break;
            } else {
                $validRenwalPolicyYearlyNoPot = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyYearlyOnlyNoPot) {
            /** @var Policy $validRenwalPolicyYearlyOnlyNoPot */
            if ($this->policyRenewalStatus($validRenwalPolicyYearlyOnlyNoPot, false, Policy::PLAN_YEARLY, true)) {
                break;
            } else {
                $validRenwalPolicyYearlyOnlyNoPot = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyMonthlyWithPot) {
            /** @var Policy $validRenwalPolicyMonthlyWithPot */
            if ($this->policyRenewalStatus($validRenwalPolicyMonthlyWithPot, true, Policy::PLAN_MONTHLY)) {
                break;
            } else {
                $validRenwalPolicyMonthlyWithPot = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyYearlyWithPot) {
            /** @var Policy $validRenwalPolicyYearlyWithPot */
            if ($this->policyRenewalStatus($validRenwalPolicyYearlyWithPot, true, Policy::PLAN_YEARLY)) {
                break;
            } else {
                $validRenwalPolicyYearlyWithPot = null;
            }
        }
        foreach ($validPolicies as $validRenwalPolicyYearlyOnlyWithPot) {
            /** @var Policy $validRenwalPolicyYearlyOnlyWithPot */
            if ($this->policyRenewalStatus($validRenwalPolicyYearlyOnlyWithPot, true, Policy::PLAN_YEARLY, true)) {
                break;
            } else {
                $validRenwalPolicyYearlyOnlyWithPot = null;
            }
        }
        foreach ($validPolicies as $validRemainderPolicy) {
            /** @var Policy $validRemainderPolicy */
            if ($validRemainderPolicy->getPremiumPlan() == Policy::PLAN_MONTHLY &&
                $validRemainderPolicy->getOutstandingPremiumToDate() > 0) {
                break;
            } else {
                $validRemainderPolicy = null;
            }
        }
        foreach ($validPolicies as $claimedPolicy) {
            /** @var Policy $claimedPolicy */
            if ($claimedPolicy->hasMonetaryClaimed()) {
                break;
            } else {
                $claimedPolicy = null;
            }
        }
        foreach ($validPolicies as $multiPayPolicy) {
            /** @var Policy $multiPayPolicy */
            if ($multiPayPolicy->isDifferentPayer()) {
                break;
            } else {
                $multiPayPolicy = null;
            }
        }
        foreach ($cancelledPolicies as $policyCancelledAndPaymentOwed) {
            /** @var Policy $policyCancelledAndPaymentOwed */
            if ($policyCancelledAndPaymentOwed->isCancelledAndPaymentOwed()) {
                break;
            } else {
                $policyCancelledAndPaymentOwed = null;
            }
        }
        $cancelledFraudPolicy = $policyRepo->findOneBy([
            'status' => Policy::STATUS_CANCELLED,
            'cancelledReason' => Policy::CANCELLED_ACTUAL_FRAUD,
        ]);
        $cancelledPolicy = $policyRepo->findOneBy([
            'status' => Policy::STATUS_CANCELLED,
            'cancelledReason' => Policy::CANCELLED_USER_REQUESTED,
        ]);

        return [
            'scode' => $scode->getCode(),
            'invitation' => $invitation,
            'picsure_approved_policy' => $picSureApprovedPolicy,
            'picsure_rejected_policy' => $picSureRejectedPolicy,
            'non_picsure_policy' => $nonPicSurePolicy,
            'unpaid_policy_bacs' => $unpaidPolicyBacs,
            'unpaid_policy_judo' => $unpaidPolicyJudo,
            'unpaid_policydiscount_policy' => $unpaidPolicyDiscountPolicy,
            'valid_policy' => $validPolicy,
            'valid_policy_monthly' => $validPolicyMonthly,
            'unpaid_valid_policy_monthly' => $unpaidValidPolicyMonthly,
            'valid_policy_annual' => $validPolicyYearly,
            'cancelled_fraud_policy' => $cancelledFraudPolicy,
            'cancelled_policy' => $cancelledPolicy,
            'valid_multiple_policy' => $validMultiplePolicy,
            'valid_renewal_policy_monthly_no_pot' => $validRenwalPolicyMonthlyNoPot,
            'valid_renewal_policy_yearly_no_pot' => $validRenwalPolicyYearlyNoPot,
            'valid_renewal_policy_yearly_only_no_pot' => $validRenwalPolicyYearlyOnlyNoPot,
            'valid_renewal_policy_monthly_with_pot' => $validRenwalPolicyMonthlyWithPot,
            'valid_renewal_policy_yearly_with_pot' => $validRenwalPolicyYearlyWithPot,
            'valid_renewal_policy_yearly_only_with_pot' => $validRenwalPolicyYearlyOnlyWithPot,
            'valid_remainder_policy' => $validRemainderPolicy,
            'policy_cancelled_payment_owed' => $policyCancelledAndPaymentOwed,
            'claimed_policy' => $claimedPolicy,
            'multipay_policy' => $multiPayPolicy,
            'expired_policy_nocashback' => $expiredPolicyNoCashback,
            'expired_policy_cashback' => $expiredPolicyCashback,
            'fully_expired_policy_nocashback' => $fullyExpiredPolicyNoCashback,
            'fully_expired_policy_cashback' => $fullyExpiredPolicyCashback,
            'upcoming_phone' => $upcomingPhone,
            'active_phone' => $activePhone,
        ];
    }

    private function policyRenewalStatus(Policy $policy, $hasPot, $plan, $yearlyOnly = false)
    {
        $user = $policy->getUser();
        if ($user->canRenewPolicy($policy) && $policy->isInRenewalTimeframe() &&
            $policy->isRenewalPending() && !$policy->hasCashback()) {
            if ((!$hasPot && $policy->getPotValue() == 0) || ($hasPot && $policy->getPotValue() > 0)) {
                if ((!$yearlyOnly && $user->allowedMonthlyPayments()) ||
                    ($yearlyOnly && !$user->allowedMonthlyPayments())) {
                    return $policy->getPremiumPlan() == $plan;
                }
            }
        }

        return false;
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
            $host = mb_strtolower(parse_url($violationReport['csp-report']['blocked-uri'], PHP_URL_HOST));
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
                'sxt.cdn.skype.com',
            ])) {
                $logger->debug(sprintf('Content-Security-Policy called with ignore host: %s', $host));

                return new JsonResponse(['details' => 'Ignore Host'], 204);
            }

            if (preg_match('/^[.0-9]+$/', $host)) {
                $logger->debug(sprintf('Content-Security-Policy called with ignore host: %s', $host));

                return new JsonResponse(['details' => 'Ignore Ip'], 204);
            }

            if (in_array(mb_strtolower($violationReport['csp-report']['blocked-uri']), [
                'blob'
            ])) {
                $logger->debug(sprintf(
                    'Content-Security-Policy called with ignore url: %s',
                    $violationReport['csp-report']['blocked-uri']
                ));

                return new JsonResponse(['details' => 'Ignore url'], 204);
            }

            $scheme = mb_strtolower(parse_url($violationReport['csp-report']['blocked-uri'], PHP_URL_SCHEME));
            if (in_array($scheme, [
                'ms-appx-web',
                'none',
                'about',
                'asset',
            ])) {
                $logger->debug(sprintf('Content-Security-Policy called with ignore scheme: %s', $scheme));

                return new JsonResponse(['details' => 'Ignore scheme'], 204);
            }
        }

        // any violation on http are not relivant - should only be https
        if (isset($violationReport['csp-report']['document-uri'])) {
            $host = mb_strtolower(parse_url($violationReport['csp-report']['document-uri'], PHP_URL_HOST));
            $scheme = mb_strtolower(parse_url($violationReport['csp-report']['document-uri'], PHP_URL_SCHEME));
            if ($scheme == "http" && $host == "wearesosure.com") {
                $logger->debug(sprintf('Content-Security-Policy called with non-https host: %s', $host));

                return new JsonResponse(['details' => 'http'], 204);
            }
        }

        // we can work out the original policy
        if (isset($violationReport['csp-report']['original-policy'])) {
            unset($violationReport['csp-report']['original-policy']);
        }
        $violationReport['csp-report']['user-agent']= $request->headers->get('User-Agent');
        $this->get('snc_redis.default')->rpush('csp', json_encode($violationReport));
        $logger->debug(
            'Content-Security-Policy Violation Reported',
            $violationReport
        );

        return new Response('', 204);
    }

    /**
     * @Route("/validation", name="ops_validation")
     * @Method({"POST"})
     */
    public function validationAction(Request $request)
    {
        $logger = $this->get('logger');
        $data = json_decode($request->getContent(), true);
        $now = new \DateTime();
        $data['browser'] = $request->headers->get('User-Agent');
        $this->get('snc_redis.default')->hset('client-validation', json_encode($data), $now->format('U'));
        $logger->debug(sprintf('Validation Endpoint %s', json_encode($data)));

        return new JsonResponse();
    }

    /**
     * @Route("/postcode", name="ops_postocde")
     * @Method({"POST"})
     */
    public function postcodeAction(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        try {
            $postcode = new Postcode($data['postcode']);

            return new JsonResponse(['postcode' => $postcode->normalise()]);
        } catch (\Exception $e) {
            $this->get('logger')->info(sprintf('Invalid postcode %s', json_encode($data)), ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Invalid postcode', 500);
        }
    }
}
