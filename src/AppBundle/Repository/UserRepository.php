<?php

namespace AppBundle\Repository;

use AppBundle\Document\Address;
use AppBundle\Document\User;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\GocardlessPaymentMethod;
use AppBundle\Document\JudoPaymentMethod;

class UserRepository extends DocumentRepository
{
    use PhoneTrait;

    public function findUsersInRole($role)
    {
        $qb = $this->createQueryBuilder();
        // Horrible structure in FOSUserBundle for roles, which is an object
        // with numeric properties e.g. { "0": "ROLE_ADMIN" }
        // Assume max of 6 possible roles per user - currently only using 1
        // TODO: See about transforming the role object to a string and regex that?
        // or perhaps its possible to get to the user with the max size for the roles object
        // and use that as a basis
        for ($i = 0; $i <= 5; $i++) {
            $roleName = sprintf('roles.%d', $i);
            $qb->addOr($qb->expr()->field($roleName)->equals($role));
        }

        return $qb
            ->getQuery()
            ->execute();
    }

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

    public function getDuplicateUsers(User $user = null, $email = null, $facebookId = null, $mobileNumber = null)
    {
        // If there's nothing to query, then another user doesn't exist
        if (!$email && !$facebookId && !$mobileNumber) {
            return null;
        }

        $qb = $this->createQueryBuilder();
        if ($user) {
            $qb->field('id')->notEqual($user->getId());
        }
        if ($email) {
            $qb->addOr($qb->expr()->field('emailCanonical')->equals(strtolower($email)));
            $qb->addOr($qb->expr()->field('usernameCanonical')->equals(strtolower($email)));
        }
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }
        if ($mobileNumber) {
            $qb->addOr($qb->expr()->field('mobileNumber')->equals($this->normalizeUkMobile($mobileNumber)));
        }

        return $qb
                ->getQuery()
                ->execute();
    }

    public function existsAnotherUser(User $user = null, $email = null, $facebookId = null, $mobileNumber = null)
    {
        return $this->getDuplicateUsers($user, $email, $facebookId, $mobileNumber)->count() > 0;
    }

    public function getDuplicatePostcodeCount(User $user)
    {
        if (!$user->getBillingAddress() || !$user->getBillingAddress()->getPostcode()) {
            return null;
        }

        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());
        $qb->field('billingAddress.postcode')->equals($user->getBillingAddress()->getPostcode());

        return $qb
            ->getQuery()
            ->execute()
            ->count();
    }

    public function findBankAccount(User $user)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());

        if ($user->getPaymentMethod() instanceof JudoPaymentMethod) {
            $accountHash = $user->getPaymentMethod() ? $user->getPaymentMethod()->getCardTokenHash() : ['NotAHash'];
            $qb->field('paymentMethod.cardTokenHash')->equals($accountHash);
        } elseif ($user->getPaymentMethod() instanceof GocardlessPaymentMethod) {
            $accountHashes = $user->getPaymentMethod() ? $user->getPaymentMethod()->getAccountHashes() : ['NotAHash'];
            $qb->field('paymentMethod.accountHashes')->in($accountHashes);
        } else {
            throw new \Exception('User is missing a payment type');
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

    public function findNewUsers(\DateTime $startDate, \DateTime $endDate)
    {
        $qb = $this->createQueryBuilder()
            ->field('created')->lt($endDate)
            ->field('created')->gte($startDate);

        return $qb
            ->getQuery()
            ->execute();
    }
}
