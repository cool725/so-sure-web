<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class InvitationVoter extends Voter
{
    // these strings are just invented: you can use anything
    const REINVITE = 'reinvite';
    const ACCEPT = 'accept';
    const REJECT = 'reject';
    const CANCEL = 'cancel';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, array(self::ACCEPT, self::REJECT, self::REINVITE, self::CANCEL))) {
            return false;
        }

        // only vote on Invitation objects inside this voter
        if (!$subject instanceof Invitation) {
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

        // you know $subject is a Invitation object, thanks to supports
        /** @var Invitation $invitation */
        $invitation = $subject;

        if (in_array($attribute, [self::ACCEPT, self::REJECT]) && $invitation->getInvitee()) {
            return $invitation->getInvitee()->getId() == $currentUser->getId();
        } elseif (in_array($attribute, [self::REINVITE, self::CANCEL]) && $invitation->getInviter()) {
            return $invitation->getInviter()->getId() == $currentUser->getId();
        }

        return false;
    }
}
