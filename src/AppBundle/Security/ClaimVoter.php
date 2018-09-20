<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ClaimVoter extends Voter
{
    // these strings are just invented: you can use anything
    const VIEW = 'view';
    const EDIT = 'edit';
    const WITHDRAW = 'withdraw';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::WITHDRAW,
        ])) {
            return false;
        }

        // only vote on Claim objects inside this voter
        if (!$subject instanceof Claim) {
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

        // you know $subject is a Claim object, thanks to supports
        /** @var Claim $claim */
        $claim = $subject;

        if (in_array($attribute, [
            self::EDIT,
        ])) {
            if (!in_array($claim->getStatus(), [
                Claim::STATUS_FNOL,
                Claim::STATUS_SUBMITTED,
                Claim::STATUS_INREVIEW
            ])) {
                return false;
            }
        }

        if (in_array($attribute, [
            self::WITHDRAW,
        ])) {
            if (!in_array($claim->getStatus(), [
                Claim::STATUS_FNOL,
                Claim::STATUS_SUBMITTED,
                Claim::STATUS_INREVIEW
            ])) {
                return false;
            }
        }

        return $claim->getPolicy()->getUser()->getId() == $currentUser->getId();
    }
}
