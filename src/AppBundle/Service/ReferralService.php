<?php
namespace AppBundle\Service;

use AppBundle\Document\ReferralBonus;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

use Psr\Log\LoggerInterface;

use Doctrine\ODM\MongoDB\DocumentManager;

class ReferralService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailerService */
    protected $mailer;

    /** @var PolicyService */
    protected $policy;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param MailerService   $mailer
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        PolicyService $policy
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->policy = $policy;
    }

    public function processReferrals(\DateTime $date, $pendingOnly = false)
    {
        $statuses = [
            ReferralBonus::STATUS_PENDING
        ];

        if (!$pendingOnly) {
            $statuses[] = ReferralBonus::STATUS_RETRY;
        }

        $coolofDate = clone $date;
        $coolofDate->sub(new \DateInterval('P14D'));

        $referrals = $this->dm->createQueryBuilder(ReferralBonus::class)
            ->field('created')->lte($coolofDate)
            ->field('status')->in($statuses)
            ->getQuery()
            ->execute();

        $result = [
            'Applied' => 0,
            'Pending' => 0
        ];

        foreach ($referrals as $referral) {
            $applyError = false;
            if (!$referral->getInviterPaid()) {
                if ($referral->applicableToInviter($date)) {
                    try {
                        $this->policy->applyReferralBonus($referral->getInviter());
                        $referral->setInviterPaid(true);
                        $result['Applied']++;
                        $this->sendInviterAppliedEmail($referral);
                    } catch (\Exception $e) {
                        $this->logger->error(sprintf(
                            'Error applying referral bonus %s to inviter policy %s',
                            $referral->getId(),
                            $referral->getInviter()
                        ), ['exception' => $e]);
                        $applyError = true;
                    }
                } else {
                    $referral->setInviterPaid(false);
                    if ($referral->getInviterCancelled() === false) {
                        $result['Pending']++;
                    }
                }
            }
            if (!$referral->getInviteePaid()) {
                if ($referral->applicableToInvitee($date)) {
                    try {
                        $this->policy->applyReferralBonus($referral->getInvitee());
                        $referral->setInviteePaid(true);
                        $result['Applied']++;
                        $this->sendInviteeAppliedEmail($referral);
                    } catch (\Exception $e) {
                        $this->logger->error(sprintf(
                            'Error applying referral bonus %s to invitee policy %s',
                            $referral->getId(),
                            $referral->getInviter()
                        ), ['exception' => $e]);
                        $applyError = true;
                    }
                } else {
                    $this->logger->error(sprintf(
                        'Referral bonus %s to invitee policy %s failed',
                        $referral->getId(),
                        $referral->getInviter()
                    ));
                    $applyError = true;
                }
            }
            if (($referral->getInviterPaid() === true || $referral->getInviterCancelled() === true) &&
                $referral->getInviteePaid() === true
            ) {
                $referral->setStatus(ReferralBonus::STATUS_APPLIED);
            } elseif ($applyError) {
                $referral->setStatus(ReferralBonus::STATUS_RETRY);
            } else {
                $referral->setStatus(ReferralBonus::STATUS_SLEEPING);
            }
            $this->dm->flush();
        }
        return $result;
    }

    public function processSleepingReferrals(Policy $policy)
    {
        $referralRepo = $this->dm->getRepository(ReferralBonus::Class);

        $referrals = $referralRepo->findBy([
            'inviter' => $policy,
            'inviterPaid' => false,
            'status' => ReferralBonus::STATUS_SLEEPING
        ]);

        foreach ($referrals as $referral) {
            if ($policy->isExpired() && $policy->getNextPolicy()) {
                $referral->setInviter($policy->getNextPolicy());
            } else {
                $this->logger->warning(sprintf(
                    'Can\'t apply sleeping referral bonus to policy: %s',
                    $policy->getId()
                ));
                continue;
            }
            try {
                $this->policy->applyReferralBonus($referral->getInviter());
                $referral->setInviterPaid(true);
                $this->sendInviterAppliedEmail($referral);
                $referral->setStatus(ReferralBonus::STATUS_APPLIED);
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error applying referral bonus %s to inviter policy %s',
                    $referral->getId(),
                    $referral->getInviter()
                ), ['exception' => $e]);
                $referral->setStatus(ReferralBonus::STATUS_RETRY);
            }
        }
        $this->dm->flush();
    }

    public function cancelReferrals(Policy $policy)
    {
        $referralRepo = $this->dm->getRepository(ReferralBonus::Class);

        $referrals = $referralRepo->findBy([
            'invitee' => $policy,
            'status' => ReferralBonus::STATUS_PENDING
        ]);
        foreach ($referrals as $referral) {
            if ($policy->isWithinCooloffPeriod()) {
                $referral->setStatus(ReferralBonus::STATUS_CANCELLED);
                $this->dm->flush();
            }
        }

        $referrals = $referralRepo->findBy([
            'inviter' => $policy,
            'inviterPaid' => false
        ]);
        foreach ($referrals as $referral) {
            if ($policy->isWithinCooloffPeriod()) {
                $referral->setStatus(ReferralBonus::STATUS_CANCELLED);
            }
            $referral->setInviterCancelled(true);
            $this->dm->flush();
        }
    }

    private function sendInviteeAppliedEmail($referral, $attachmentFiles = null, $bcc = null)
    {
        if (!$this->mailer) {
            return;
        }
        try {
            $this->mailer->sendTemplateToUser(
                sprintf('One month free!'),
                $referral->getInvitee()->getUser(),
                'AppBundle:Email:invitation/invitee-post-cooloff.html.twig',
                ['referral' => $referral],
                'AppBundle:Email:invitation/invitee-post-cooloff.txt.twig',
                ['referral' => $referral],
                $attachmentFiles,
                $bcc
            );
            $referral->getInvitee()->setLastEmailed(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending referral email to %s', $referral->getInvitee()->getUser()->getEmail()),
                ['exception' => $e]
            );
        }
    }

    private function sendInviterAppliedEmail($referral, $attachmentFiles = null, $bcc = null)
    {
        if (!$this->mailer) {
            return;
        }
        try {
            $this->mailer->sendTemplateToUser(
                sprintf('One month free for referring a friend!'),
                $referral->getInviter()->getUser(),
                'AppBundle:Email:invitation/inviter-post-cooloff.html.twig',
                ['referral' => $referral],
                'AppBundle:Email:invitation/inviter-post-cooloff.txt.twig',
                ['referral' => $referral],
                $attachmentFiles,
                $bcc
            );
            $referral->getInviter()->setLastEmailed(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending referral email to %s', $referral->getInviter()->getUser()->getEmail()),
                ['exception' => $e]
            );
        }
    }
}
