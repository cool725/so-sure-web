<?php

namespace AppBundle\DataFixtures\MongoDB\c\Policy;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\SCode;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface, ContainerAwareInterface
{
    use \AppBundle\Tests\UserClassTrait;

    const CLAIM_NONE = 'none';
    const CLAIM_RANDOM = 'random';
    const CLAIM_SETTLED_LOSS = 'settled-loss';

    const CONNECTIONS_RANDOM_OR_NONE = 'random-or-none';
    const CONNECTIONS_RANDOM = 'random';
    const CONNECTIONS_ONE = 1;

     /**
     * @var ContainerInterface
     */
    private $container;

    private $faker;
    private $emails = [];
    private $receiptIds = [];

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->faker = Faker\Factory::create('en_GB');

        $users = $this->newUsers($manager, 150);
        $iosPreExpireUsers = $this->newUsers($manager, 40);
        $androidPreExpireUsers = $this->newUsers($manager, 40);
        $preExpireUsers = $this->newUsers($manager, 40);
        $preExpireYearlyUsers = $this->newUsers($manager, 40, true);
        $expiredUsers = $this->newUsers($manager, 40);
        $fullyExpiredUsers = $this->newUsers($manager, 40);
        $manager->flush();

        $count = 0;
        foreach ($users as $user) {
            $this->newPolicy($manager, $user, $count);
            $count++;

            // add a second policy for some users
            $rand = rand(1, 5);
            if ($rand == 1) {
                $this->newPolicy($manager, $user, $count);
                $count++;
            }
        }

        foreach ($users as $user) {
            $this->addConnections($manager, $user, $users);
        }

        foreach ($iosPreExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, true, true);
            $count++;
        }
        foreach ($androidPreExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, true, true);
            $count++;
        }
        foreach ($preExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_RANDOM, null, null, null, null, true, 345);
            $user->setEnabled(true);
            $count++;
        }
        foreach ($preExpireYearlyUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_RANDOM, null, null, null, null, true, 345);
            $user->setEnabled(true);
            $count++;
        }
        foreach ($expiredUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, 366);
            $user->setEnabled(true);
            $count++;
        }
        foreach ($fullyExpiredUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, 396);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();

        foreach ($preExpireUsers as $user) {
            $rand = rand(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $preExpireUsers);
            }
        }
        foreach ($preExpireYearlyUsers as $user) {
            $rand = rand(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $preExpireYearlyUsers);
            }
        }
        foreach ($fullyExpiredUsers as $user) {
            $rand = rand(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $fullyExpiredUsers);
            }
        }

        $phones = [];
        foreach ($preExpireYearlyUsers as $user) {
            $phones[] = $user->getPolicies()[0]->getPhone();
        }
        foreach ($preExpireYearlyUsers as $user) {
            $phones[] = $user->getPolicies()[0]->getPhone();
        }
        $sixMonthsAgo = new \DateTime();
        $sixMonthsAgo = $sixMonthsAgo->sub(new \DateInterval('P6M'));
        $adjusted = [];
        for ($i = 0; $i < 5; $i++) {
            $phone = $phones[rand(0, count($phones) - 1)];
            if (isset($adjusted[$phone->getId()])) {
                continue;
            }
            $adjusted[] = $phone->getId();
            $adjustedPrice = $phone->getCurrentPhonePrice()->getGwp() - 0.01;
            if ($phone->getSalvaMiniumumBinderMonthlyPremium() < $phone->getCurrentPhonePrice()->getGwp() - 0.30) {
                $adjustedPrice = $phone->getCurrentPhonePrice()->getGwp() - 0.30;
            }
            $phone->changePrice($adjustedPrice, $sixMonthsAgo, null, null, $sixMonthsAgo);
        }

        // Sample user for apple
        $user = $this->newUser('julien+apple@so-sure.com');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++);

        // claimed user test data
        $networkUser = $this->newUser('user-network-claimed@so-sure.net');
        $networkUser->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $networkUser->setEnabled(true);
        $manager->persist($networkUser);
        $this->newPolicy($manager, $networkUser, $count++, self::CLAIM_SETTLED_LOSS);
        //\Doctrine\Common\Util\Debug::dump($networkUser);

        $user = $this->newUser('user-claimed@so-sure.net');
        $user->setPlainPassword('w3ares0sure!');
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_SETTLED_LOSS);
        $this->addConnections($manager, $user, [$networkUser], 1);

        // Users for iOS Testing
        $iphoneUI = $this->getIPhoneUI($manager);
        $userInviter = $this->newUser('ios-testing+inviter@so-sure.net');
        $userInviter->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInviter->setEnabled(true);
        $manager->persist($userInviter);
        $this->newPolicy($manager, $userInviter, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true);

        $userInvitee = $this->newUser('ios-testing+invitee@so-sure.net');
        $userInvitee->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInvitee->setEnabled(true);
        $manager->persist($userInvitee);
        $this->newPolicy($manager, $userInvitee, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false);

        $user = $this->newUser('ios-testing+scode@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, 'IOS-TEST', $iphoneUI, true);

        $this->invite($manager, $userInviter, $userInvitee, false);

        $user = $this->newUser('ios-testing+renew+pot@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $iosPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        $user = $this->newUser('ios-testing+renew+nopot@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $iosPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        $user = $this->newUser('ios-testing+cashback@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $iosPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        // Users for Android Testing
        $androidUI = $this->getAndroidUI($manager);
        $userInviter = $this->newUser('android-testing+inviter@so-sure.net');
        $userInviter->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInviter->setEnabled(true);
        $manager->persist($userInviter);
        $this->newPolicy($manager, $userInviter, $count++, self::CLAIM_NONE, null, null, $androidUI, true);

        $userInvitee = $this->newUser('android-testing+invitee@so-sure.net');
        $userInvitee->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInvitee->setEnabled(true);
        $manager->persist($userInvitee);
        $this->newPolicy($manager, $userInvitee, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false);

        $user = $this->newUser('android-testing+scode@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, 'AND-TEST', $androidUI, true);

        $this->invite($manager, $userInviter, $userInvitee, false);

        $user = $this->newUser('android-testing+renew+pot@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $androidPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        $user = $this->newUser('android-testing+renew+nopot@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $androidPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        $user = $this->newUser('android-testing+cashback@so-sure.net');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false, 345);
        $maxAttempts = 20;
        while ($policy->getPotValue() == 0) {
            $maxAttempts--;
            $this->addConnections($manager, $user, $androidPreExpireUsers, self::CONNECTIONS_ONE);
            if ($maxAttempts < 0) {
                throw new \Exception(sprintf('0 pot value policy %s', $user->getEmail()));
            }
        }

        $manager->flush();
        $policyService = $this->container->get('app.policy');

        $policyRepo = $manager->getRepository(Policy::class);
        if (!$policyRepo->findOneBy(['status' => Policy::STATUS_UNPAID])) {
            throw new \Exception('missing unpaid policy');
        }

        // cancelled policy with outstanding payment due
        $user = $this->newUser('cancelled-policy-unpaid@so-sure.com');
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_SETTLED_LOSS, null, null, null, false);
        $policy->setStatus(Policy::STATUS_UNPAID);
        $policyService->cancel($policy, Policy::CANCELLED_UNPAID, true, null, true);

        $policyRepo = $manager->getRepository(Policy::class);
        $fraud = null;
        $unpaid = null;
        $maxAttempts = 0;

        $policies = $policyRepo->findBy(['status' => Policy::STATUS_ACTIVE]);
        foreach ($policies as $policy) {
            if (count($policy->getClaims()) == 0 && count($policy->getUser()->getPolicies()) == 1) {
                if (!$fraud && $policy->canCancel(Policy::CANCELLED_ACTUAL_FRAUD)) {
                    $fraud = true;
                    $policyService->cancel($policy, Policy::CANCELLED_ACTUAL_FRAUD, true);
                    $policy->getUser()->setEnabled(true);
                } elseif (!$unpaid) {
                    if ($policy->canCancel(Policy::CANCELLED_USER_REQUESTED)) {
                        $unpaid = true;
                        $policyService->cancel($policy, Policy::CANCELLED_USER_REQUESTED, true);
                        $policy->getUser()->setEnabled(true);
                    }
                } else {
                    break;
                }
            }
            $maxAttempts++;
            if ($maxAttempts > 50) {
                throw new \Exception('Unable to cancel policies');
            }
        }
        $manager->flush();
    }

    private function newUsers($manager, $number, $yearlyOnlyPostcode = false)
    {
        $userRepo = $manager->getRepository(User::class);
        $users = [];
        for ($i = 1; $i <= $number; $i++) {
            $user = $this->newUser(null, $yearlyOnlyPostcode);
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    private function newUser($email = null, $yearlyOnlyPostcode = false)
    {
        $user = new User();
        $user->setFirstName($this->faker->firstName);
        $user->setLastName($this->faker->lastName);
        $user->setBirthday(new \DateTime('1980-01-01'));
        while ($mobile = $this->faker->mobileNumber) {
            $user->setMobileNumber($mobile);
            // faker can return 070 type numbers, which are disallowed
            if (preg_match('/7[1-9]\d{8,8}$/', $mobile)) {
                break;
            }
        }

        if (!$email) {
            // Use the first/last name as the user portion of the email address so they vaugely match
            // Keep the random portion of the email domain though
            $email = $this->faker->email;
            $rand = rand(1, 3);
            if ($rand == 1) {
                $email = sprintf("%s.%s@%s", $user->getFirstName(), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 2) {
                $email = sprintf("%s%s@%s", substr($user->getFirstName(), 0, 1), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 3) {
                $email = sprintf("%s%s%2d@%s", substr($user->getFirstName(), 0, 1), $user->getLastName(), rand(1, 99), explode("@", $email)[1]);
            }
        }
        $email = str_replace(' ', '', $email);
        if (in_array($email, $this->emails)) {
            return $this->newUser(null, $yearlyOnlyPostcode);
        }
        $this->emails[] = $email;
        $user->setEmail($email);

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1(trim(preg_replace('/[\\n\\r]+/', ' ', $this->faker->streetAddress)));
        $address->setCity($this->faker->city);
        if ($yearlyOnlyPostcode) {
            $address->setPostcode(SoSure::$yearlyOnlyPostcodes[0]);
        } else {
            $address->setPostcode($this->faker->postcode);
        }

        $user->setBillingAddress($address);

        return $user;
    }

    private function getIPhoneUI($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['devices' => 'iPhone8,4', 'memory' => 16]);

        return $phone;
    }

    private function getAndroidUI($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['devices' => 'marmite']);

        return $phone;
    }

    private function getRandomPhone($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phones = $phoneRepo->findAll(['active' => true]);
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            if (!$phone->getCurrentPhonePrice(new \DateTime('2016-01-01')) || $phone->getMake() == "ALL") {
                $phone = null;
            }
        }

        return $phone;
    }

    private function newPolicy(
        $manager,
        $user,
        $count,
        $claim = self::CLAIM_RANDOM,
        $promo = null,
        $code = null,
        $phone = null,
        $paid = null,
        $sendInvitation = true,
        $days = null
    ) {
        if (!$phone) {
            $phone = $this->getRandomPhone($manager);
        }
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $startDate = new \DateTime();
        if ($days === null) {
            $days = sprintf("P%dD", rand(0, 120));
        } else {
            $days = sprintf("P%dD", $days);
        }
        $startDate->sub(new \DateInterval($days));
        $policy = new SalvaPhonePolicy();
        $policy->setPhone($phone);
        $policy->setImei($this->generateRandomImei());
        $policy->init($user, $latestTerms);
        if (!$code) {
            $policy->createAddSCode($count);
        } else {
            $scode = new SCode();
            $scode->setCode($code);
            $scode->setType(SCode::TYPE_STANDARD);
            $policy->addSCode($scode);
        }
        $router = $this->container->get('app.router');
        $shareUrl = $router->generateUrl(
            'scode',
            ['code' => $policy->getStandardSCode()->getCode()]
        );
        $policy->getStandardSCode()->setShareLink($shareUrl);

        $claimStatus = null;
        $claimType = null;
        if ($claim == self::CLAIM_RANDOM) {
            $claim = rand(0, 3) == 0;
        } elseif ($claim == self::CLAIM_SETTLED_LOSS) {
            $claimStatus = Claim::STATUS_SETTLED;
            $claimType = Claim::TYPE_LOSS;
        }
        if (in_array($claim, [self::CLAIM_RANDOM, self::CLAIM_SETTLED_LOSS])) {
            $this->addClaim($dm, $policy, $claimType, $claimStatus);
        }
        if ($promo === null) {
            $promo = rand(0, 1) == 0;
        }
        if ($promo) {
            $policy->setPromoCode(Policy::PROMO_LAUNCH);
        }

        $paymentDate = clone $startDate;
        if ($paid === true || rand(0, 1) == 0) {
            $payment = new JudoPayment();
            $payment->setDate($paymentDate);
            $payment->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, clone $startDate));
            $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $receiptId = rand(1, 9999999);
            while (in_array($receiptId, $this->receiptIds)) {
                $receiptId = rand(1, 9999999);
            }
            $this->receiptIds[] = $receiptId;
            $payment->setReceipt($receiptId);
            $payment->setNotes('LoadSamplePolicyData');
            $policy->addPayment($payment);
        } else {
            $months = rand(1, 11);
            for ($i = 1; $i <= $months; $i++) {
                $payment = new JudoPayment();
                $payment->setDate(clone $paymentDate);
                $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, clone $startDate));
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                if ($months == 12) {
                    $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
                }
                $payment->setResult(JudoPayment::RESULT_SUCCESS);
                $receiptId = rand(1, 9999999);
                while (in_array($receiptId, $this->receiptIds)) {
                    $receiptId = rand(1, 9999999);
                }
                $this->receiptIds[] = $receiptId;
                $payment->setReceipt($receiptId);
                $payment->setNotes('LoadSamplePolicyData');
                $policy->addPayment($payment);
                $paymentDate->add(new \DateInterval('P1M'));
                if (rand(0, 3) == 0) {
                    $tDate = clone $paymentDate;
                    $tDate->add(new \DateInterval('P1D'));
                    $policy->incrementSalvaPolicyNumber($tDate);
                }
            }
        }
        $manager->persist($policy);
        $env = $this->container->getParameter('kernel.environment');
        $policy->create(-5000 + $count, strtoupper($env), $startDate);
        $now = new \DateTime();
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);

        $policyService = $this->container->get('app.policy');
        $policyService->generateScheduledPayments($policy, $startDate);

        if ($sendInvitation) {
            $invitation = new EmailInvitation();
            $invitation->setInviter($user);
            $invitation->setPolicy($policy);
            $invitation->setEmail($this->faker->email);
            $rand = rand(0, 2);
            if ($rand == 0) {
                $invitation->setCancelled($policy->getStart());
            } elseif ($rand == 1) {
                $invitation->setRejected($policy->getStart());
            }
            $manager->persist($invitation);
        }

        if ($days > 393) {
            $policy->setStatus(SalvaPhonePolicy::STATUS_EXPIRED);
        } elseif ($days > 365) {
            $policy->setStatus(SalvaPhonePolicy::STATUS_EXPIRED_CLAIMABLE);
        } elseif ($policy->isPolicyPaidToDate($now)) {
            $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        } else {
            $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        }

        return $policy;
    }

    private function invite($manager, $userA, $userB, $accepted = true)
    {
        if (count($userA->getPolicies()) == 0 || count($userB->getPolicies()) == 0) {
            return;
        }
        $policyA = $userA->getPolicies()[0];
        $policyB = $userB->getPolicies()[0];

        $invitation = new EmailInvitation();
        $invitation->setInviter($userA);
        $invitation->setPolicy($policyA);
        $invitation->setEmail($userB->getEmail());
        $invitation->setInvitee($userB);
        if ($accepted) {
            $invitation->setAccepted($policyB->getStart());
        }
        $manager->persist($invitation);        
    }

    private function addConnections($manager, $userA, $users, $connections = self::CONNECTIONS_RANDOM_OR_NONE)
    {
        $policyA = $userA->getPolicies()[0];
        if ($connections == self::CONNECTIONS_RANDOM_OR_NONE) {
            $connections = rand(0, $policyA->getMaxConnections() - 2);
        } elseif ($connections == self::CONNECTIONS_RANDOM) {
            $connections = rand(2, $policyA->getMaxConnections() - 2);
        }

        //$connections = rand(0, 3);
        $maxRetries = 20;
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[rand(0, count($users) - 1)];
            $policyB = $userB->getPolicies()[0];
            if ($policyA->getId() == $policyB->getId() || count($policyB->getConnections()) > 0) {
                $maxRetries--;
                if ($maxRetries > 0) {
                    $i--;
                }
                continue;
            }

            // only 1 connection for user
            foreach ($policyA->getConnections() as $connection) {
                if ($connection->getLinkedPolicy()->getId() == $policyB->getId()) {
                    $maxRetries--;
                    if ($maxRetries > 0) {
                        $i--;
                    }
                    continue;
                }
            }

            $this->invite($manager, $userA, $userB);

            $connectionA = new StandardConnection();
            $connectionA->setLinkedUser($userA);
            $connectionA->setLinkedPolicy($policyA);
            $connectionA->setValue($policyB->getAllowedConnectionValue());
            if ($policyB->getPromoCode() == SalvaPhonePolicy::PROMO_LAUNCH) {
                $connectionA->setPromoValue($policyB->getAllowedPromoConnectionValue());
            }

            $connectionB = new StandardConnection();
            $connectionB->setLinkedUser($userB);
            $connectionB->setLinkedPolicy($policyB);
            $connectionB->setValue($policyA->getAllowedConnectionValue());
            if ($policyA->getPromoCode() == SalvaPhonePolicy::PROMO_LAUNCH) {
                $connectionB->setPromoValue($policyA->getAllowedPromoConnectionValue());
            }
    
            $policyA->addConnection($connectionB);
            $policyA->updatePotValue();
    
            $policyB->addConnection($connectionA);
            $policyB->updatePotValue();

            $manager->persist($connectionA);
            $manager->persist($connectionB);            
        }
    }

    protected function addClaim($manager, Policy $policy, $type = null, $status = null)
    {
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));

        $date = new \DateTime();
        $date->sub(new \DateInterval(sprintf('P%dD', rand(5,15))));
        $claim->setLossDate(clone $date);
        $date->add(new \DateInterval(sprintf('P%dD', rand(0,4))));
        $claim->setNotificationDate(clone $date);

        if (!$type) {
            $type = $this->getRandomClaimType();
        }
        if (!$status) {
            $status = $this->getRandomStatus($type);
        }
        $claim->setType($type);
        $claim->setStatus($status);
        if ($claim->isOpen()) {
            $claim->setDaviesStatus('open');
        } else {
            $claim->setDaviesStatus('closed');
        }
        if (in_array($claim->getType(), [
            Claim::TYPE_LOSS,
            Claim::TYPE_THEFT,
        ])) {
            $claim->setExcess(70);
        } elseif (in_array($claim->getType(), [
            Claim::TYPE_DAMAGE,
            Claim::TYPE_WARRANTY,
            Claim::TYPE_EXTENDED_WARRANTY,
        ])) {
            $claim->setExcess(50);
        }
        $claim->setClaimHandlingFees(15);
        $claim->setTransactionFees(rand(90,190) / 100);

        $phone = $this->getRandomPhone($manager);
        $claim->setReservedValue($phone->getReplacementPriceOrSuggestedReplacementPrice() + 15);
        if ($claim->getStatus() == Claim::STATUS_SETTLED &&
            in_array($claim->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            $claim->setReplacementPhone($phone);
            $claim->setReplacementImei($this->generateRandomImei());
            if (rand(0, 1) == 0) {
                $claim->setReplacementReceivedDate(new \DateTime());
            }
            $claim->setPhoneReplacementCost($phone->getReplacementPriceOrSuggestedReplacementPrice());
            $claim->setUnauthorizedCalls(rand(0, 20000) / 100);
            $claim->setAccessories(rand(0, 20000) / 100);
            $claim->setClosedDate(new \DateTime());
        }
        $claim->setDescription($this->getRandomDescription($claim));
        $claim->setIncurred(array_sum([$claim->getClaimHandlingFees(), $claim->getTransactionFees(), $claim->getAccessories(),
            $phone->getReplacementPriceOrSuggestedReplacementPrice(), $claim->getUnauthorizedCalls()]));

        $policy->addClaim($claim);
        $manager->persist($claim);
    }

    protected function getRandomDescription($claim)
    {
        $data = [];
        if ($claim->getType() == Claim::TYPE_DAMAGE) {
            $data = [
                'Cracked Screen',
                'Dropped in Water',
                'Run over',
                'Unknown'
            ];
        } elseif ($claim->getType() == Claim::TYPE_THEFT) {
            $data = [
                'Pick Pocket',
                'From Car',
                'From Home',
            ];
        } elseif ($claim->getType() == Claim::TYPE_LOSS) {
            $data = [
                'Loss from Pocket',
                'Left on Table',
                'Loss from Bag',
            ];
        } else {
            return null;
        }

        return sprintf('%s - %s', ucfirst($claim->getType()), $data[rand(0, count($data) - 1)]);
    }

    protected function getRandomStatus($type)
    {
        if (in_array($type, [Claim::TYPE_WARRANTY])) {
            $random = rand(0, 1);
            if ($random == 0) {
                return Claim::STATUS_INREVIEW;
            } elseif ($random == 1) {
                return Claim::STATUS_WITHDRAWN;
            }
        }

        $random = rand(0, 4);
        if ($random == 0) {
            return Claim::STATUS_INREVIEW;
        } elseif ($random == 1) {
            return Claim::STATUS_APPROVED;
        } elseif ($random == 2) {
            return Claim::STATUS_SETTLED;
        } elseif ($random == 3) {
            return Claim::STATUS_DECLINED;
        } elseif ($random == 4) {
            return Claim::STATUS_WITHDRAWN;
        }
    }

    protected function getRandomClaimType()
    {
        $type = rand(0, 4);
        if ($type == 0) {
            return Claim::TYPE_LOSS;
        } elseif ($type == 1) {
            return Claim::TYPE_THEFT;
        } elseif ($type == 2) {
            return Claim::TYPE_DAMAGE;
        } elseif ($type == 3) {
            return Claim::TYPE_WARRANTY;
        } elseif ($type == 4) {
            return Claim::TYPE_EXTENDED_WARRANTY;
        }
    }
}
