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
        $qb->field('mobileNumber')->lte($lt)->gte($gt);

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
            $date = \DateTime::createFromFormat('U', time());
        }
        $date = $this->endOfDay($date);

        $qb = $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.mandateStatus')->equals(BankAccount::MANDATE_PENDING_APPROVAL)
            ->field('paymentMethod.bankAccount.initialPaymentSubmissionDate')->lt($date)
        ;

        return $qb;
    }

    /**
     * Finds all users who have not got the attribution field set which were created within the given dates.
     * @param \DateTime $start is the date which none of the returned users can be older than or null for no start.
     * @param \DateTime $end   is the date which none of the returned users can be younger than or null for no end.
     * @return array containing all such users.
     */
    public function findUnattributedUsers(\DateTime $start = null, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder()->field("attribution")->exists(false);
        if ($start) {
            $qb->field("created")->gte($start);
        }
        if ($end) {
            $qb->field("created")->lte($end);
        }
        return $qb->getQuery()->execute();
    }

    /**
     * Gives you the total number of users in the system.
     * @return int containing the number of users that there are.
     */
    public function countAll()
    {
        return $this->createQueryBuilder()->getQuery()->execute()->count();
    }

    /**
     * Gives you all the users in the system in groups of a given size so that you can do something with those groups.
     * @param int $size is the size of each group.
     * @return \Generator which will give you each individual group of users as an array in turn.
     */
    public function findAllUsersGrouped($size = 500)
    {
        $count = 0;
        while (true) {
            $users = $this->createQueryBuilder()->find()->skip($count)->limit($size)->getQuery()->execute();
            if (!$users->dead()) {
                yield $users;
                $count += $size;
            } else {
                return;
            }
        }
    }

    /**
     * Gives you an iterator over all the users that does not load them all into memory at once so that you can process
     * them individually while using less memory.
     * @return \Generator which gives you individual users.
     */
    public function findAllUsersBatched()
    {
        foreach ($this->findAllUsersGrouped() as $group) {
            foreach ($group as $user) {
                yield $user;
            }
        }
    }

    /**
     * Removes every hubspot id on a policy in the system. This is used with a mass delete to remove all references to
     * data that has been deleted on hubspot already.
     */
    public function removeHubspotIds()
    {
        $this->createQueryBuilder()->updateMany()->field("hubspotId")->unsetField()->getQuery()->execute();
    }
}
