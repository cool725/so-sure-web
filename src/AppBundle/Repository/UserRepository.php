<?php

namespace AppBundle\Repository;

use AppBundle\Document\Address;
use AppBundle\Document\User;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class UserRepository extends DocumentRepository
{
    use PhoneTrait;

    public function existsUser($email, $facebookId = null, $mobileNumber = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->addOr($qb->expr()->field('emailCanonical')->equals(strtolower($email)));
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }
        if ($mobileNumber) {
            $qb->addOr($qb->expr()->field('mobileNumber')->equals($this->normalizeUkMobile($mobileNumber)));
        }

        return $qb
            ->getQuery()
            ->execute()
            ->count() > 0;
    }

    public function existsAnotherUser(User $user, $email = null, $facebookId = null, $mobileNumber = null)
    {
        // If there's nothing to query, then another user doesn't exist
        if (!$email && !$facebookId && !$mobileNumber) {
            return false;
        }

        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());
        if ($email) {
            $qb->addOr($qb->expr()->field('emailCanonical')->equals(strtolower($email)));
        }
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }
        if ($mobileNumber) {
            $qb->addOr($qb->expr()->field('mobileNumber')->equals($this->normalizeUkMobile($mobileNumber)));
        }

        return $qb
            ->getQuery()
            ->execute()
            ->count() > 0;
    }

    public function findPostcode(User $user)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());
        $qb->field('billingAddress.postcode')->equals($user->getBillingAddress()->getPostcode());

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findBankAccount(User $user)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());

        if ($user->getPaymentMethod() instanceof JudoPaymentMethod) {
            $accountHash = $user->getPaymentMethod() ? $user->getPaymentMethod()->getCardTokenHash() : ['NotAHash'];
            $qb->field('paymentMethod.cardTokenHash')->equals($accountHash);
        } elseif ($user->getPaymentMethod() instanceof GocardlessPaymentMethod) {
            $accountHash = $user->getPaymentMethod() ? $user->getPaymentMethod()->getAccountHashes() : ['NotAHash'];
            $qb->field('paymentMethod.accountHashes')->in($accountHashes);
        }

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findIp(User $user)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());
        $qb->field('identityLog.ip')->equals($user->getIdentityLog()->getIp());

        return $qb
            ->getQuery()
            ->execute();
    }
}
