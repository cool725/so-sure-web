<?php

namespace AppBundle\Listener;

use AppBundle\Document\Charge;
use AppBundle\Service\SmsService;
use AppBundle\Service\BranchService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;

class SmsListener
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var SmsService */
    protected $smsService;

    /** @var BranchService */
    protected $branchService;

    /**
     * @param SmsService      $smsService
     * @param BranchService   $branchService
     * @param LoggerInterface $logger
     */
    public function __construct(
        SmsService $smsService,
        BranchService $branchService,
        LoggerInterface $logger
    ) {
        $this->smsService = $smsService;
        $this->branchService = $branchService;
        $this->logger = $logger;
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
                $smsTemplate = "AppBundle:Sms:app-download.txt.twig";
                $medium = 'onboarding-sms';
                $branchUrl = false;
                if ($policy->getPhone()->isITunes()) {
                    $branchUrl = $this->branchService->linkToAppleDownload($medium);
                } elseif ($policy->getPhone()->isGooglePlay()) {
                    $branchUrl = $this->branchService->linkToGoogleDownload($medium);
                }
                if ($branchUrl) {
                    $this->smsService->sendUser(
                        $policy,
                        $smsTemplate,
                        ["branch_url" => $branchUrl],
                        Charge::TYPE_SMS_DOWNLOAD
                    );
                }
                if ($policyTerms->isPicSureRequired()) {
                    $smsTemplate = "AppBundle:Sms:picsure-required/picsureReminderOne.txt.twig";
                    $this->smsService->sendUser(
                        $policy,
                        $smsTemplate,
                        ["policy" => $policy],
                        Charge::TYPE_SMS_GENERAL
                    );
                }
            }
        }
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyUpgradedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy) {
            $smsTemplate = "AppBundle:Sms:upgrades/phoneUpgrade.txt.twig";
            $this->smsService->sendUser(
                $policy,
                $smsTemplate,
                [],
                Charge::TYPE_SMS_GENERAL
            );
        }
    }
}
