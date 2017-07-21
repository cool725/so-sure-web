<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class UserVoter extends Voter
{
    // these strings are just invented: you can use anything
    const VIEW = 'view';
    const EDIT = 'edit';
    const ADD_POLICY = 'add-policy';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, array(self::VIEW, self::EDIT, self::ADD_POLICY))) {
            return false;
        }

        // only vote on User objects inside this voter
        if (!$subject instanceof User) {
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

        // you know $subject is a User object, thanks to supports
        /** @var User $user */
        $requestedUser = $subject;

        if ($attribute == self::ADD_POLICY && !$requestedUser->canPurchasePolicy()) {
            return false;
        }

        return $requestedUser->getId() == $currentUser->getId();
    }
}
