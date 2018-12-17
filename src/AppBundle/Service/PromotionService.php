<?php
namespace AppBundle\Service;

use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Repository\ParticipationRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Repository\Invitation\InvitationRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

/**
 * Checks out promotion participation and sees what users are ripe for getting a reward.
 */
class PromotionService
{
    /** @var DocumentManager */
    protected $dm;
    /** @var MailerService */
    protected $mailerService;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * Builds service and injects dependencies.
     * @param DocumentManager $dm            is the document manager.
     * @param MailerService   $mailerService is the mail sender.
     * @param LoggerInterface $logger        is the logger.
     */
    public function __construct(DocumentManager $dm, MailerService $mailerService, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->mailerService = $mailerService;
        $this->logger = $logger;
    }

    /**
     * Loops through a bunch of promotions and checks if the users in them are conforming to the promotion conditions,
     * and invalidates promotion participations that have failed or become impossible.
     * @param array|null $promotions is the list of promotions or null to load in those promotions.
     * @param \DateTime  $date       is the date that is to be considered as current for the evaluation of conditions.
     * @return array associative and each key is a set status and the tally set to that. if not existent then 0.
     */
    public function generate($promotions = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        /** @var ParticipationRepository $participationRepository */
        $participationRepository = $this->dm->getRepository(Participation::class);
        $participations = $participationRepository->findByStatus(Participation::STATUS_ACTIVE, $promotions);
        $endings = [];
        foreach ($participations as $participation) {
            $policy = $participation->getPolicy();
            $status = $this->invalidationConditions($participation);
            if ($status) {
                $this->invalidateParticipation($participation, $status, $date);
                $status = Participation::STATUS_INVALID;
            } else {
                $status = $this->rewardConditions($participation, $date);
                if ($status) {
                    $this->endParticipation($participation, $status, $date);
                }
            }
            if ($status && array_key_exists($status, $endings)) {
                $endings[$status]++;
            } else {
                $endings[$status] = 1;
            }
        }
        return $endings;
    }

    /**
     * Tells what the status of a participation should be set to.
     * @param Participation $participation is the participation which we are checking.
     * @param \DateTime     $date          is the date considered as current for the purposes of the conditions.
     * @return String|null a participation status or null to keep it active.
     */
    public function rewardConditions($participation, \DateTime $date)
    {
        $promotion = $participation->getPromotion();
        $policy = $participation->getPolicy();
        $interval = new \DateInterval("P".$promotion->getConditionPeriod()."D");
        $end = (clone ($participation->getStart()))->add($interval);
        $finished = $end->diff($date)->invert == 0;
        $claims = $policy->getClaimsInPeriod($participation->getStart(), $end);
        // Conditions.
        if (!$promotion->getConditionAllowClaims() && count($claims) > 0) {
            return Participation::STATUS_FAILED;
        }
        if ($finished) {
            /** @var InvitationRepository $invitationRepository */
            $invitationRepository = $this->dm->getRepository(Invitation::class);
            $invites = $invitationRepository->count([$policy], $participation->getStart(), $end);
            if ($promotion->getConditionInvitations() > $invites) {
                return Participation::STATUS_FAILED;
            } else {
                return Participation::STATUS_COMPLETED;
            }
        }
        return null;
    }

    /**
     * Checks if a given participation is invalid.
     * @param Participation $participation is the one we are checking on.
     * @return String|null containing the participation's reason for invalidation or null if it's valid.
     */
    public function invalidationConditions($participation)
    {
        $promotion = $participation->getPromotion();
        $policy = $participation->getPolicy();
        if ($promotion->getReward() == Promotion::REWARD_TASTE_CARD && $policy->getTasteCard()) {
            return Participation::INVALID_EXISTING_TASTE_CARD;
        }
        return null;
    }

    /**
     * Set a participation as complete and email marketing to tell them to give the reward.
     * @param Participation $participation is the participation to complete.
     * @param String        $status        is the status to set the participation as now having.
     * @param \DateTime     $date          is the date of the participation's completion.
     * @return String the status that you passed in for convenience.
     */
    public function endParticipation($participation, $status, \DateTime $date)
    {
        $participation->endWithStatus($status, $date);
        $this->dm->flush();
        if ($status == Participation::STATUS_COMPLETED) {
            $this->mailerService->sendTemplate(
                "Promotion Reward Earned",
                "marketing@so-sure.com",
                "AppBundle:Email:promotion/rewardEarned.html.twig",
                ["participation" => $participation]
            );
        }
        return $status;
    }

    /**
     * Makes a participation invalid and sends marketing an email based on the failure type.
     * A failure type is probably necessary as opposed to just basing it on the reward type, as there could be in future
     * a type of reward in which multiple kinds of failures can occur and it would affect the needed copy.
     * @param Participation $participation is the participation to set as invalid.
     * @param String        $reason        is the reason for invalidation from Participation::INVALID_*.
     * @param \DateTime     $date          is the date to set the invalidation as having occured.
     */
    public function invalidateParticipation($participation, $reason, \DateTime $date)
    {
        $participation->endWithStatus(Participation::STATUS_INVALID, $date);
        $this->dm->flush();
        $message = null;
        // Failure condition messages. NOTE: only one now, but more may be added.
        switch ($reason) {
            case Participation::INVALID_EXISTING_TASTE_CARD:
                $message = "Promotion reward is a tastecard, but user already has a tastecard.";
                break;
        }
        if ($message) {
            $this->mailerService->sendTemplate(
                "Promotion Reward Cannot Be Awarded",
                "marketing@so-sure.com",
                "AppBundle:Email:promotion/rewardInvalid.html.twig",
                [
                    "participation" => $participation,
                    "reason" => $message
                ]
            );
        }
    }
}
