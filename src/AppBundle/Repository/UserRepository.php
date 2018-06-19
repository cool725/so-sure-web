<?php

namespace AppBundle\Repository;

use AppBundle\Document\Address;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\JudoPaymentMethod;

class UserRepository extends DocumentRepository
{
    use DateTrait;
    use PhoneTrait;

    const NEAR_MOBILE_NUMBER_RANGE = 10;

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

    public function existsUser($email, $facebookId = null, $mobileNumber = null, $googleId = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->addOr($qb->expr()->field('emailCanonical')->equals(mb_strtolower($email)));
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }
        if ($mobileNumber) {
            $qb->addOr($qb->expr()->field('mobileNumber')->equals($this->normalizeUkMobile($mobileNumber)));
        }
        if ($googleId) {
            $qb->addOr($qb->expr()->field('googleId')->equals(trim($googleId)));
        }

        return $qb
                ->getQuery()
                ->execute()
                ->count() > 0;
    }

    public function getDuplicateUsers(
        User $user = null,
        $email = null,
        $facebookId = null,
        $mobileNumber = null,
        $googleId = null
    ) {
        // If there's nothing to query, then another user doesn't exist
        if (!$email && !$facebookId && !$mobileNumber) {
            return null;
        }

        $qb = $this->createQueryBuilder();
        if ($user) {
            $qb->field('id')->notEqual($user->getId());
        }
        if ($email) {
            $qb->addOr($qb->expr()->field('emailCanonical')->equals(mb_strtolower($email)));
            $qb->addOr($qb->expr()->field('usernameCanonical')->equals(mb_strtolower($email)));
        }
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }
        if ($mobileNumber) {
            $qb->addOr($qb->expr()->field('mobileNumber')->equals($this->normalizeUkMobile($mobileNumber)));
        }
        if ($googleId) {
            $qb->addOr($qb->expr()->field('googleId')->equals(trim($googleId)));
        }

        return $qb
            ->getQuery()
            ->execute();
    }

    public function existsAnotherUser(
        User $user = null,
        $email = null,
        $facebookId = null,
        $mobileNumber = null,
        $googleId = null
    ) {
        if ($duplicate = $this->getDuplicateUsers($user, $email, $facebookId, $mobileNumber, $googleId)) {
            return $duplicate->count() > 0;
        }

        return false;
    }

    public function getNearMobileNumberCount(User $user)
    {
        if (!$user->getMobileNumber()) {
            return null;
        }

        $qb = $this->createQueryBuilder();
        $qb->field('id')->notEqual($user->getId());
        // strip +44
        $number = mb_substr($user->getMobileNumber(), 3);
        $lt = $this->normalizeUkMobile($number + self::NEAR_MOBILE_NUMBER_RANGE, true);
        $gt = $this->normalizeUkMobile($number - self::NEAR_MOBILE_NUMBER_RANGE, true);

        // {"mobileNumber":{"$gte":"+447781444400","$lte":"+447781444409"}}
        $qb->field('mobileNumber' )->lte($lt)->gte($gt);

        return $qb
            ->getQuery()
            ->execute()
            ->count();

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
        } elseif ($user->getPaymentMethod() instanceof BacsPaymentMethod) {
            $accountHash = $user->getPaymentMethod()->getBankAccount() ?
                $user->getPaymentMethod()->getBankAccount()->getHashedAccount() : 'NotAHash';
            $qb->field('paymentMethod.bankAccount.hashedAccount')->equals($accountHash);
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

    public function findPendingMandates(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $date = $this->endOfDay($date);

        $qb = $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.mandateStatus')->equals(BankAccount::MANDATE_PENDING_APPROVAL)
            ->field('paymentMethod.bankAccount.initialPaymentSubmissionDate')->lt($date)
        ;

        return $qb;
    }
}
