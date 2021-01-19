<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Classes\Helvetia;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PolicyVoter extends Voter
{
    // these strings are just invented: you can use anything
    const VIEW = 'view';
    const EDIT = 'edit';
    const SEND_INVITATION = 'send-invitation';
    const CONNECT = 'connect';
    const RENEW = 'renew';
    const CASHBACK = 'cashback';
    const REPURCHASE = 'repurchase';
    const UPGRADE = 'upgrade';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::SEND_INVITATION,
            self::CASHBACK,
            self::CONNECT,
            self::RENEW,
            self::REPURCHASE,
            self::UPGRADE
        ])) {
            return false;
        }

        // only vote on Policy objects inside this voter
        if (!$subject instanceof Policy) {
            return false;
        }

        return true;
    }

    public function voteOnAttribute($attribute, $subject, TokenInterface $token)
    {
        $currentUser = $token->getUser();

        if (!$currentUser instanceof User) {
            // the user must be logged in; if not, deny access
            return false;
        }

        // you know $subject is a Policy object, thanks to supports
        /** @var Policy $policy */
        $policy = $subject;

        if ($attribute == self::RENEW) {
            if (!$policy->canRenew()) {
                return false;
            }
        }

        if ($attribute == self::REPURCHASE) {
            if (!$policy->canRepurchase()) {
                return false;
            }
        }

        if ($attribute == self::EDIT) {
            if (in_array($policy->getStatus(), [
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
                Policy::STATUS_EXPIRED,
            ])) {
                return false;
            }
        }

        if ($attribute == self::UPGRADE) {
            if ($policy->getUnderwriterName() != Helvetia::NAME || !in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])) {
                return false;
            }
            if (count($policy->getPendingBacsPayments()) > 0) {
                return false;
            }
        }

        return $policy->getUser()->getId() == $currentUser->getId();
    }
}
