<?php

namespace AppBundle\Listener;

use AppBundle\Service\PolicyService;
use AppBundle\Service\SmsService;
use AppBundle\Service\BranchService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\Charge;

class PolicyListener
{
    /** @var PolicyService */
    protected $policyService;

    /** @var LoggerInterface */
    protected $logger;

    /** @var SmsService */
    protected $smsService;

    /** @var BranchService */
    protected $branchService;

    /**
     * @param PolicyService   $policyService
     * @param SmsService      $smsService
     * @param BranchService   $branchService
     * @param LoggerInterface $logger
     */
    public function __construct(
        PolicyService $policyService,
        SmsService $smsService,
        BranchService $branchService,
        LoggerInterface $logger
    ) {
        $this->policyService = $policyService;
        $this->smsService = $smsService;
        $this->branchService = $branchService;
        $this->logger = $logger;
    }

    /**
     * @param ConnectionEvent $event
     */
    public function onConnectionReducedEvent(ConnectionEvent $event)
    {
        $connection = $event->getConnection();
        // There are cases where a connection value might be reduced or eliminated:
        // 1) Policy cancellation
        // 2) Policy is not renewed
        // 3) Policy is renewed, but connection is not renewed and so value is dropped/removed

        // Claims should not affect the connection value itself, but rather impact on the pot value

        // The linked policy should be the policy that was actually cancelled/not renewed/etc
        // so inversely, if the source policy is active/unpaid, its the connection we are not interested
        // in notifying about
        if (!in_array($connection->getSourcePolicy()->getStatus(), [
            Policy::STATUS_ACTIVE,
            Policy::STATUS_UNPAID,
            Policy::STATUS_PICSURE_REQUIRED
        ])) {
            return;
        }

        $this->policyService->connectionReduced($connection);
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy != null) {
            $policyTerms = $policy->getPolicyTerms();
            if ($policyTerms != null) {
                $smsTemplate = "AppBundle:Sms:picsure-required/app-download.txt.twig";
                $medium = 'onboarding-sms';
                if ($policy->getPhone()->isITunes()) {
                    $branchUrl = $this->branchService->linkToAppleDownload($medium);
                } elseif ($policy->getPhone()->isGooglePlay()) {
                    $branchUrl = $this->branchService->linkToGoogleDownload($medium);
                }
                $this->smsService->sendUser(
                    $policy,
                    $smsTemplate,
                    ["branch_url" => $branchUrl],
                    Charge::TYPE_SMS_PAYMENT
                );
                if ($policyTerms->isPicSureRequired()) {
                    $smsTemplate = "AppBundle:Sms:picsure-required/picsureReminderOne.txt.twig";
                    $this->smsService->sendUser(
                        $policy,
                        $smsTemplate,
                        ["policy" => $policy],
                        Charge::TYPE_SMS_PAYMENT
                    );
                }
            }
        }
    }
}
