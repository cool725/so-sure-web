<?php

namespace AppBundle\DataFixtures\MongoDB\Policy;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Classes\Salva;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;

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

        $this->newPolicyTerms($manager);
        $manager->flush();

        $users = $this->newUsers($manager);
        $manager->flush();

        $count = 0;
        foreach ($users as $user) {
            $this->newPolicy($manager, $user, $count);
            $count++;
        }

        foreach ($users as $user) {
            $this->addConnections($manager, $user, $users);
        }

        // Sample user for apple
        $user = $this->newUser('julien+apple@so-sure.com');
        $user->setPlainPassword('test');
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count);

        $manager->flush();
    }

    private function newPolicyTerms($manager)
    {
        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(true);
        $policyTerms->setVersion('Version 1 May 2016');
        $manager->persist($policyTerms);
    }

    private function newUsers($manager)
    {
        $userRepo = $manager->getRepository(User::class);
        $users = [];
        for ($i = 1; $i <= 200; $i++) {
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

    private function newUser($email)
    {
        $user = new User();
        $user->setFirstName($this->faker->firstName);
        $user->setLastName($this->faker->lastName);
        while ($mobile = $this->faker->mobileNumber) {
            $user->setMobileNumber($mobile);
            // faker can return 070 type numbers, which are disallowed
            if (preg_match('/7[1-9]\d{8,8}$/', $mobile)) {
                break;
            }
        }

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
        $user->setEmail($email);

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1(trim(preg_replace('/[\\n\\r]+/', ' ', $this->faker->streetAddress)));
        $address->setCity($this->faker->city);
        $address->setPostcode($this->faker->postcode);

        $user->setBillingAddress($address);

        return $user;
    }

    private function getRandomPhone($manager)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phones = $phoneRepo->findAll();
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            if (!$phone->getCurrentPhonePrice() || $phone->getMake() == "ALL") {
                $phone = null;
            }
        }

        return $phone;
    }

    private function newPolicy($manager, $user, $count)
    {
        $phone = $this->getRandomPhone($manager);
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $startDate = new \DateTime();
        $startDate->sub(new \DateInterval(sprintf("P%dD", rand(0, 120))));
        $policy = new SalvaPhonePolicy();
        $policy->setPhone($phone);
        $policy->setImei($this->generateRandomImei());
        $policy->init($user, $latestTerms);
        if (rand(0, 3) == 0) {
            $this->addClaim($dm, $policy);
        }
        if (rand(0, 1) == 0) {
            $policy->setPromoCode(Policy::PROMO_LAUNCH);
        }

        $paymentDate = clone $startDate;
        if (rand(0, 1) == 0) {
            $payment = new JudoPayment();
            $payment->setDate($paymentDate);
            $payment->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
            $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setReceipt(rand(1, 999999));
            $policy->addPayment($payment);
        } else {
            $months = rand(1, 12);
            for ($i = 1; $i <= $months; $i++) {
                $payment = new JudoPayment();
                $payment->setDate(clone $paymentDate);
                $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                if ($months == 12) {
                    $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
                }
                $payment->setResult(JudoPayment::RESULT_SUCCESS);
                $payment->setReceipt(rand(1, 999999));
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
        $policy->create(-5000 + $count, null, $startDate);
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);

        $policyService = $this->container->get('app.policy');
        $policyService->generateScheduledPayments($policy, $startDate);
    }

    private function addConnections($manager, $userA, $users)
    {
        $policyA = $userA->getPolicies()[0];
        $connections = rand(0, $policyA->getMaxConnections() - 2);
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

            $connectionA = new Connection();
            $connectionA->setLinkedUser($userA);
            $connectionA->setLinkedPolicy($policyA);
            $connectionA->setValue($policyB->getAllowedConnectionValue());
            if ($policyB->getPromoCode() == SalvaPhonePolicy::PROMO_LAUNCH) {
                $connectionA->setPromoValue($policyB->getAllowedPromoConnectionValue());
            }

            $connectionB = new Connection();
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

    protected function addClaim($manager, Policy $policy)
    {
        $claim = new Claim();
        $claim->setNumber(rand(1, 999999));

        $date = new \DateTime();
        $date->sub(new \DateInterval(sprintf('P%dD', rand(5,15))));
        $claim->setLossDate(clone $date);
        $date->add(new \DateInterval(sprintf('P%dD', rand(0,4))));
        $claim->setNotificationDate(clone $date);

        $claim->setType($this->getRandomClaimType());
        $claim->setStatus($this->getRandomStatus());
        if ($claim->isOpen()) {
            $claim->setDaviesStatus('open');
        } else {
            $claim->setDaviesStatus('closed');
        }
        $claim->setExcess($claim->getExpectedExcess());
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

    protected function getRandomStatus()
    {
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
        $type = rand(0, 2);
        if ($type == 0) {
            return Claim::TYPE_LOSS;
        } elseif ($type == 1) {
            return Claim::TYPE_THEFT;
        } elseif ($type == 2) {
            return Claim::TYPE_DAMAGE;
        }
    }
}
