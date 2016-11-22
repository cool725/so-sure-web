<?php
namespace AppBundle\Security;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\MultiPay;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MultiPayVoter extends Voter
{
    // these strings are just invented: you can use anything
    const PAY = 'pay';

    public function supports($attribute, $subject)
    {
        // if the attribute isn't one we support, return false
        if (!in_array($attribute, array(self::PAY))) {
            return false;
        }

        // only vote on MultiPay objects inside this voter
        if (!$subject instanceof MultiPay) {
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

        // you know $subject is a MultiPay object, thanks to supports
        /** @var MultiPay $multiPay */
        $multiPay = $subject;
        
        if ($attribute == self::PAY) {
            return $multiPay->getPayer()->getId() == $currentUser->getId();
        } else {
            return false;
        }
    }
}
