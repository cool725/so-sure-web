<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class InvitationVoter extends Voter
{
    // these strings are just invented: you can use anything
    const VIEW = 'view';
    const EDIT = 'edit';
    const DELETE = 'delete';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, array(self::VIEW, self::EDIT, self::DELETE))) {
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

        return $invitation->getInviter()->getId() == $currentUser->getId();
    }
}
