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
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface, ContainerAwareInterface
{
    use \AppBundle\Tests\UserClassTrait;

     /**
     * @var ContainerInterface
     */
    private $container;

    private $faker;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->faker = Faker\Factory::create('en_GB');

        $users = $this->newUsers($manager, 200);
        $expUsers = $this->newUsers($manager, 20);
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

        foreach ($expUsers as $user) {
            $this->newPolicy($manager, $user, $count, null, null, null, null, null, true, false);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();

        foreach ($expUsers as $user) {
            $rand = rand(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $expUsers);
            }
        }

        // Sample user for apple
        $user = $this->newUser('julien+apple@so-sure.com', true);
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++);

        // claimed user test data
        $networkUser = $this->newUser('user-network-claimed@so-sure.com', true);
        $networkUser->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $networkUser->setEnabled(true);
        $manager->persist($networkUser);
        $this->newPolicy($manager, $networkUser, $count++, false);
        //\Doctrine\Common\Util\Debug::dump($networkUser);

        $user = $this->newUser('user-claimed@so-sure.com', true);
        $user->setPlainPassword('w3ares0sure!');
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, true);
        $this->addConnections($manager, $user, [$networkUser], 1);

        // Users for iOS Testing
        $iphoneSE = $this->getIPhoneSE($manager);
        $userInviter = $this->newUser('ios-testing+inviter@so-sure.com', true);
        $userInviter->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInviter->setEnabled(true);
        $manager->persist($userInviter);
        $this->newPolicy($manager, $userInviter, $count++, false, null, null, $iphoneSE, true);

        $userInvitee = $this->newUser('ios-testing+invitee@so-sure.com', true);
        $userInvitee->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $userInvitee->setEnabled(true);
        $manager->persist($userInvitee);
        $this->newPolicy($manager, $userInvitee, $count++, false, null, null, $iphoneSE, true, false);

        $user = $this->newUser('ios-testing+scode@so-sure.com', true);
        $user->setPlainPassword(\AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, false, null, 'IOS-TEST', $iphoneSE, true);

        $this->invite($manager, $userInviter, $userInvitee, false);

        $manager->flush();

        $policyRepo = $manager->getRepository(Policy::class);
        if (!$policyRepo->findOneBy(['status' => Policy::STATUS_UNPAID])) {
            throw new \Exception('missing unpaid policy');
        }

        $policyRepo = $manager->getRepository(Policy::class);
        $policy = $policyRepo->findOneBy(['status' => Policy::STATUS_ACTIVE, 'claims' => null]);
        $policyService = $this->container->get('app.policy');
        $policyService->cancel($policy, Policy::CANCELLED_ACTUAL_FRAUD, true);
        $manager->flush();
    }

    private function newUsers($manager, $number)
    {
        $userRepo = $manager->getRepository(User::class);
        $users = [];
        for ($i = 1; $i <= $number; $i++) {
            $email = $this->faker->email;
            while ($userRepo->findOneBy(['email' => $email])) {
                $email = $this->faker->email;
            }
            $user = $this->newUser($email);
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    private function newUser($email, $forceEmailAddress = false)
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

        if (!$forceEmailAddress) {
            // Use the first/last name as the user portion of the email address so they vaugely match
            // Keep the random portion of the email domain though
            $rand = rand(1, 3);
            if ($rand == 1) {
                $email = sprintf("%s.%s@%s", $user->getFirstName(), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 2) {
                $email = sprintf("%s%s@%s", substr($user->getFirstName(), 0, 1), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 3) {
                $email = sprintf("%s%s%2d@%s", substr($user->getFirstName(), 0, 1), $user->getLastName(), rand(1, 99), explode("@", $email)[1]);
            }
        }
        $user->setEmail(str_replace(' ', '', $email));

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1(trim(preg_replace('/[\\n\\r]+/', ' ', $this->faker->streetAddress)));
        $address->setCity($this->faker->city);
        $address->setPostcode($this->faker->postcode);

        $user->setBillingAddress($address);

        return $user;
    }

    private function getIPhoneSE($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['devices' => 'iPhone8,4', 'memory' => 16]);

        return $phone;
    }

    private function getRandomPhone($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phones = $phoneRepo->findAll();
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
        $claim = null,
        $promo = null,
        $code = null,
        $phone = null,
        $paid = null,
        $sendInvitation = true,
        $recent = true
    ) {
        if (!$phone) {
            $phone = $this->getRandomPhone($manager);
        }
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $startDate = new \DateTime();
        if ($recent) {
            $days = sprintf("P%dD", rand(0, 120));
        } else {
            $days = sprintf("P336D");
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
        $router = $this->container->get('router');
        $shareUrl = $router->generate(
            'scode',
            ['code' => $policy->getStandardSCode()->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $policy->getStandardSCode()->setShareLink($shareUrl);

        $claimStatus = null;
        $claimType = null;
        if ($claim === null) {
            $claim = rand(0, 3) == 0;
        } else {
            $claimStatus = Claim::STATUS_SETTLED;
            $claimType = Claim::TYPE_LOSS;
        }
        if ($claim) {
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
            $payment->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice(clone $startDate));
            $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setReceipt(rand(1, 999999) + rand(1, 999999));
            $payment->setNotes('LoadSamplePolicyData');
            $policy->addPayment($payment);
        } else {
            $months = rand(1, 12);
            for ($i = 1; $i <= $months; $i++) {
                $payment = new JudoPayment();
                $payment->setDate(clone $paymentDate);
                $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(clone $startDate));
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                if ($months == 12) {
                    $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
                }
                $payment->setResult(JudoPayment::RESULT_SUCCESS);
                $payment->setReceipt(rand(1, 999999) + rand(1, 999999));
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

        if ($policy->isPolicyPaidToDate($now)) {
            $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        } else {
            $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        }
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

    private function addConnections($manager, $userA, $users, $connections = null)
    {
        $policyA = $userA->getPolicies()[0];
        if ($connections === null) {
            $connections = rand(0, $policyA->getMaxConnections() - 2);
        }
        //$connections = rand(0, 3);
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[rand(0, count($users) - 1)];
            $policyB = $userB->getPolicies()[0];
            if ($policyA->getId() == $policyB->getId() || count($policyB->getConnections()) > 0) {
                continue;
            }

            // only 1 connection for user
            foreach ($policyA->getConnections() as $connection) {
                if ($connection->getLinkedPolicy()->getId() == $policyB->getId()) {
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
