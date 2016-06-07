<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyKeyFacts;
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

        $this->newPolicyKeyFacts($manager);
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

    private function newPolicyKeyFacts($manager)
    {
        $policyKeyFacts = new PolicyKeyFacts();
        $policyKeyFacts->setLatest(true);
        $policyKeyFacts->setVersion('Version 1 May 2016');
        $manager->persist($policyKeyFacts);
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
        $user->setEmail($email);
        $user->setFirstName($this->faker->firstName);
        $user->setLastName($this->faker->lastName);
        $user->setMobileNumber($this->faker->mobileNumber);

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1($this->faker->streetAddress);
        $address->setCity($this->faker->city);
        $address->setPostcode($this->faker->address);

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

        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $startDate = new \DateTime();
        $startDate->sub(new \DateInterval(sprintf("P%dD", rand(0, 120))));
        $policy = new PhonePolicy();
        $policy->setPhone($phone);
        $policy->setImei($this->generateRandomImei());
        $policy->init($user, $latestTerms, $latestKeyFacts);
        if (rand(0, 3) == 0) {
            $this->addClaim($dm, $policy);
        }

        $paymentDate = clone $startDate;
        if (rand(0, 1) == 0) {
            $payment = new JudoPayment();
            $payment->setDate($paymentDate);
            $payment->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
            $payment->setBrokerFee(Salva::YEARLY_BROKER_FEE);
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $policy->addPayment($payment);
        } else {
            $months = rand(1, 12);
            for ($i = 1; $i <= $months; $i++) {
                $payment = new JudoPayment();
                $payment->setDate(clone $paymentDate);
                $payment->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
                $payment->setBrokerFee(Salva::MONTHLY_BROKER_FEE);
                if ($months == 12) {
                    $payment->setBrokerFee(Salva::FINAL_MONTHLY_BROKER_FEE);
                }
                $payment->setResult(JudoPayment::RESULT_SUCCESS);
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
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $policyService = $this->container->get('app.policy');
        $policyService->generateScheduledPayments($policy, $startDate);
    }

    private function addConnections($manager, $userA, $users)
    {
        $policyA = $userA->getPolicies()[0];
        //$connections = rand(0, $policyA->getMaxConnections());
        $connections = rand(0, 4);
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[rand(0, count($users) - 1)];
            $policyB = $userB->getPolicies()[0];
            if ($policyA->getId() == $policyB->getId()) {
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

            $connectionB = new Connection();
            $connectionB->setLinkedUser($userB);
            $connectionB->setLinkedPolicy($policyB);
            $connectionB->setValue($policyA->getAllowedConnectionValue());
    
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
        if ($claim->getStatus() == Claim::STATUS_SETTLED &&
            in_array($claim->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            $claim->setReplacementPhone($this->getRandomPhone($manager));
            $claim->setReplacementImei($this->generateRandomImei());
            if (rand(0, 1) == 0) {
                $claim->setReplacementReceivedDate(new \DateTime());
            }
            $claim->setPhoneReplacementCost($claim->getReplacementPhone()->getReplacementPrice());
            $claim->setUnauthorizedCalls(rand(0, 20000) / 100);
            $claim->setAccessories(rand(0, 20000) / 100);
            $claim->setReservedValue($claim->getReplacementPhone()->getReplacementPrice() + 15);
        } else {
            $phone = $this->getRandomPhone($manager);
            $claim->setReservedValue($phone->getReplacementPrice() + 15);
        }
        $claim->setTransactionFees(rand(90,190) / 100);
        $claim->setIncurred(abs($claim->getClaimHandlingFees()) + 15);
        $claim->setDescription($this->getRandomDescription($claim));

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
