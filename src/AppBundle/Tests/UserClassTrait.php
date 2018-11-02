<?php

namespace AppBundle\Tests;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Cashback;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Reward;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Classes\Salva;
use AppBundle\Document\PhonePolicy;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\DependencyInjection\Container;

trait UserClassTrait
{
    use CurrencyTrait;
    use ImeiTrait;

    /** @var PolicyService */
    protected static $policyService;

    /** @var Phone */
    protected static $phone;

    /** @var UserManagerInterface */
    protected static $userManager;

    /** @var DocumentManager */
    protected static $dm;

    public static $JUDO_TEST_CARD_NUM = '4921 8100 0000 5462';
    public static $JUDO_TEST_CARD_LAST_FOUR = '5462';
    public static $JUDO_TEST_CARD_EXP = '12/20';
    public static $JUDO_TEST_CARD_PIN = '441';
    public static $JUDO_TEST_CARD_NAME = 'Visa Debit **** 5462 (Exp: 1220)';

    public static $JUDO_TEST_CARD2_NUM = '4976 0000 0000 3436';
    public static $JUDO_TEST_CARD2_LAST_FOUR = '3436';
    public static $JUDO_TEST_CARD2_EXP = '12/20';
    public static $JUDO_TEST_CARD2_PIN = '452';

    public static function generateEmail($name, $caller, $rand = false)
    {
        if ($rand) {
            return sprintf(
                '%s-%d@%s.so-sure.net',
                $name,
                random_int(0, 999999),
                str_replace("\\", ".", get_class($caller))
            );
        } else {
            return sprintf('%s@%s.so-sure.net', $name, str_replace("\\", ".", get_class($caller)));
        }
    }

    public static function createUserPolicy($init = false, $date = null, $setId = false)
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        self::addAddress($user);
        if ($setId) {
            $user->setId(rand(1, 999999));
        }

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        if ($init) {
            $policy->init($user, self::getLatestPolicyTerms(static::$dm));
            $phone = self::$phone;
            if (!$phone) {
                $phone = self::getRandomPhone(self::$dm);
            }
            if (!$phone) {
                throw new \Exception('Missing phone');
            }
            $policy->setPhone($phone, $date);
            $policy->create(rand(1, 999999), 'TEST', $date, rand(1, 999999));
        }

        return $policy;
    }

    /**
     * @param UserManagerInterface $userManager
     * @param string               $email
     * @param string               $password
     * @param mixed                $phone
     * @param DocumentManager|null $dm
     * @return User
     * @throws \Exception
     */
    public static function createUser(
        $userManager,
        $email,
        $password,
        $phone = null,
        \Doctrine\ODM\MongoDB\DocumentManager $dm = null
    ) {
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $user->setEnabled(true);

        if ($phone) {
            $user->setMobileNumber(self::generateRandomMobile());
            $user->setFirstName('Foo');
            $user->setLastName('Bar');
            $user->setBirthday(new \DateTime('1980-01-01'));
        }

        $userManager->updateUser($user);
        if ($dm) {
            $dm->persist($user);
        }

        return $user;
    }

    public static function addAddress(User $user)
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('123 s road');
        $address->setCity('London');
        $address->setPostcode('BX11LT');
        $user->setBillingAddress($address);
    }

    public static function generateRandomMobile()
    {
        $mobile = sprintf('+4477009%05d', rand(1, 99999));
        if (mb_strlen($mobile) != 13) {
            throw new \Exception('Random mobile is not the right length');
        }

        return $mobile;
    }
    
    public static function getRandomPhone(\Doctrine\ODM\MongoDB\DocumentManager $dm, $make = null)
    {
        $phoneRepo = $dm->getRepository(Phone::class);
        if ($make) {
            $phones = $phoneRepo->findBy(['active' => true, 'make' => $make, 'devices' => ['$ne' => 'A0001']]);
        } else {
            $phones = $phoneRepo->findBy(['active' => true, 'devices' => ['$ne' => 'A0001']]);
        }
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            // Many tests rely on past dates, so ensure the date is ok for the past
            if (!$phone->getCurrentPhonePrice(new \DateTime('2016-01-01')) || $phone->getMake() == "ALL") {
                $phone = null;
            }
        }

        return $phone;
    }

    public static function transformMobile($mobile)
    {
        return str_replace("+44", "0", $mobile);
    }

    /**
     * @return PhonePolicy
     */
    public static function initPolicy(
        User $user,
        \Doctrine\ODM\MongoDB\DocumentManager $dm,
        $phone = null,
        $date = null,
        $addPayment = false,
        $createPolicy = false,
        $monthly = true
    ) {
        self::addAddress($user);

        $policy = new SalvaPhonePolicy();
        $policy->setImei(self::generateRandomImei());
        $policy->init($user, self::getLatestPolicyTerms($dm));

        if ($phone) {
            $policy->setPhone($phone, $date);
        }

        if ($addPayment) {
            if (!$policy->getPhone()) {
                throw new \Exception('Missing phone for adding payment');
            }
            $newDate = null;
            if ($date) {
                $newDate = clone $date;
            }
            if ($monthly) {
                $policy->setPremiumInstallments(12);
                self::addPayment(
                    $policy,
                    $policy->getPremium()->getMonthlyPremiumPrice(),
                    Salva::MONTHLY_TOTAL_COMMISSION,
                    null,
                    $newDate
                );
            } else {
                $policy->setPremiumInstallments(1);
                self::addPayment(
                    $policy,
                    $policy->getPremium()->getYearlyPremiumPrice(),
                    Salva::YEARLY_TOTAL_COMMISSION,
                    null,
                    $newDate
                );
            }
        }

        if ($createPolicy) {
            if (!$phone) {
                throw new \Exception('Attempted to create policy without setting a phone');
            }

            $policy->create(rand(1, 999999), 'TEST', $date, rand(1, 999999));
        }

        $dm->persist($policy);
        try {
            $dm->flush();
        } catch (\Exception $e) {
            $policy->createAddSCode(rand(1, 999999));
            $dm->flush();
        }
        return $policy;
    }

    /**
     * @param \DateTime $date
     *
     * @return Cashback
     */
    public static function createCashback($date = null, $status = null)
    {
        $cashback = new Cashback();

        $cashback->setAccountName('foobar');
        $cashback->setAccountNumber(str_pad(rand(0, 99999999), 8));
        $cashback->setSortCode(str_pad(rand(0, 999999), 6));

        if ($date) {
            $cashback->setDate($date);
        }

        if ($status) {
            $cashback->setStatus($status);
        }

        return $cashback;
    }

    public static function addJudoPayPayment(
        $judopay,
        $policy,
        $date = null,
        $monthly = true,
        $adjustment = 0,
        $actual = true
    ) {
        if ($monthly) {
            $policy->setPremiumInstallments(12);
            $premium = $policy->getPremium()->getMonthlyPremiumPrice(null, $date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $policy->setPremiumInstallments(1);
            $premium = $policy->getPremium()->getYearlyPremiumPrice(null, $date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }
        if ($adjustment) {
            $premium = $premium - $adjustment;
            // toTwoDp
            $premium = number_format(round($premium, 2), 2, ".", "");
        }

        if ($actual) {
            $details = self::runJudoPayPayment($judopay, $policy->getUser(), $policy, $premium);
            $receiptId = $details['receiptId'];
        } else {
            $receiptId = random_int(1, 999999);
        }
        self::addPayment($policy, $premium, $commission, $receiptId, $date);
    }

    public static function addBacsPayPayment($policy, $date = null, $monthly = true, $manual = true)
    {
        if ($monthly) {
            $policy->setPremiumInstallments(12);
            $premium = $policy->getPremium()->getMonthlyPremiumPrice(null, $date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $policy->setPremiumInstallments(1);
            $premium = $policy->getPremium()->getYearlyPremiumPrice(null, $date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }

        return self::addBacsPayment($policy, $premium, $commission, $date, $manual);
    }

    public static function runJudoPayPayment($judopay, User $user, Policy $policy, $amount)
    {
        return $judopay->testPayDetails(
            $user,
            $policy->getId(),
            $amount,
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
    }

    public static function addPayment(
        Policy $policy,
        $amount,
        $commission,
        $receiptId = null,
        $date = null,
        $result = JudoPayment::RESULT_SUCCESS
    ) {
        if (!$receiptId) {
            $receiptId = rand(1, 999999);
        }
        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setResult($result);
        $payment->setReceipt($receiptId);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);

        return $payment;
    }

    public static function setPaymentMethod(User $user, $endDate = '1220')
    {
        $account = ['type' => '1', 'lastfour' => '1234', 'endDate' => $endDate];
        $judo = new JudoPaymentMethod();
        $judo->addCardTokenArray(random_int(1, 999999), $account);
        $user->setPaymentMethod($judo);
    }

    public static function addBacsPayment(
        Policy $policy,
        $amount,
        $commission,
        $date = null,
        $manual = true,
        $status = BacsPayment::STATUS_SUCCESS
    ) {
        $payment = new BacsPayment();
        $payment->setManual($manual);
        $payment->setStatus($status);
        $payment->setSuccess(true);
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);

        return $payment;
    }

    public static function setBacsPaymentMethod(
        User $user,
        $mandateStatus = BankAccount::MANDATE_SUCCESS,
        $randomReference = false
    ) {
        $bacs = new BacsPaymentMethod();
        $bankAccount = new BankAccount();
        $bankAccount->setMandateStatus($mandateStatus);
        if ($randomReference) {
            $bankAccount->setReference(sprintf('TESTREF-%d', random_int(1, 999999)));
        }
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
    }

    public static function addSoSureStandardPayment($policy, $date = null, $refund = true, $monthly = true)
    {
        if ($monthly) {
            $premium = $policy->getPremium()->getMonthlyPremiumPrice(null, $date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $premium = $policy->getPremium()->getYearlyPremiumPrice(null, $date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }
        if (!$refund) {
            $premium = 0 - $premium;
            $commission = 0 - $commission;
        }
        self::addSoSurePayment($policy, $premium, $commission, $date);
    }
    
    public static function addSoSurePayment($policy, $amount, $commission, $date = null)
    {
        $payment = new SoSurePayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setSuccess(true);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);
    }

    public static function connectPolicies($invitationService, $policyA, $policyB, $date)
    {
        $invitation = $invitationService->inviteByEmail($policyA, $policyB->getUser()->getEmail());
        $invitationService->accept($invitation, $policyB, $date);
    }

    /**
     * @param DocumentManager $dm
     * @return PolicyTerms
     */
    public static function getLatestPolicyTerms(\Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        return $latestTerms;
    }

    public static function authUser($cognito, $user)
    {
        list($identityId, $token) = $cognito->getCognitoIdToken($user, $cognito->getId());

        return $identityId;
    }

    public static function postRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "POST");
    }

    public static function putRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "PUT");
    }

    public static function deleteRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "DELETE");
    }

    public static function clearEmail($container)
    {
        $redis = $container->get('snc_redis.mailer');
        $redis->del('swiftmailer');
    }

    private static function cognitoRequest($client, $cognitoIdentityId, $url, $body, $method)
    {
        return $client->request(
            $method,
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
            ),
            json_encode(array(
                'body' => $body,
                'cognitoIdentityId' => $cognitoIdentityId,
                'sourceIp' => '62.253.24.189',
            ))
        );
    }

    protected static function createRewardForUser(User $user): Reward
    {
        $reward = new Reward();
        $reward->setUser($user);
        static::$dm->persist($user);
        static::$dm->persist($reward);
        static::$dm->flush();

        return $reward;
    }

    protected static function createReward(string $email): Reward
    {
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        return self::createRewardForUser($user);
    }

    protected function createLinkedConnections(
        $policyA,
        $policyB,
        $valueA,
        $valueB,
        $dateA = null,
        $dateB = null,
        $standard = true
    ) {
        if ($standard) {
            $connectionA = new StandardConnection();
        } else {
            $connectionA = new RenewalConnection();
        }
        $connectionA->setValue($valueA);
        if ($valueA > 10) {
            $connectionA->setValue(10);
            $connectionA->setPromoValue($valueA - 10);
        }
        $connectionA->setLinkedUser($policyB->getUser());
        $connectionA->setLinkedPolicy($policyB);
        if ($dateA) {
            $connectionA->setDate($dateA);
        }
        if ($standard) {
            $policyA->addConnection($connectionA);
        } else {
            $policyA->addRenewalConnection($connectionA);
        }
        $policyA->updatePotValue();

        if ($standard) {
            $connectionB = new StandardConnection();
        } else {
            $connectionB = new RenewalConnection();
        }
        $connectionB->setValue($valueB);
        if ($valueB > 10) {
            $connectionB->setValue(10);
            $connectionB->setPromoValue($valueB - 10);
        }
        $connectionB->setLinkedUser($policyA->getUser());
        $connectionB->setLinkedPolicy($policyA);
        if ($dateB) {
            $connectionB->setDate($dateB);
        }
        if ($standard) {
            $policyB->addConnection($connectionB);
        } else {
            $policyB->addRenewalConnection($connectionB);
        }
        $policyB->updatePotValue();

        return [$connectionA, $connectionB];
    }

    protected static function getRenewalPolicy($policy, $create = true, $date = null)
    {
        if (!$date) {
            $date = new \DateTime("2017-01-01");
            $pendingDate = clone $date;
            $pendingDate = $pendingDate->sub(new \DateInterval('P21D'));
        } else {
            $pendingDate = clone $date;
        }
        $exp = clone $date;
        $exp = $exp->sub(new \DateInterval('PT1S'));
        $end = clone $date;
        $end = $end->add(new \DateInterval('P1Y'));
        $end = $end->sub(new \DateInterval('PT1S'));

        $renewalPolicy = $policy->createPendingRenewal(static::getLatestPolicyTerms(self::$dm), $pendingDate);

        /*
        $renewalPolicy = new SalvaPhonePolicy();
        $renewalPolicy->setPhone($policy->getPhone());

        $renewalPolicy->init($policy->getUser(), static::getLatestPolicyTerms(self::$dm));
        */
        if ($create) {
            $renewalPolicy->create(rand(1, 999999), null, null, rand(1, 9999));
            $renewalPolicy->setStart($date);
            $renewalPolicy->setEnd($end);
        }
        //$renewalPolicy->setStatus(Policy::STATUS_PENDING_RENEWAL);

        $policy->link($renewalPolicy);

        return $renewalPolicy;
    }

    public function getPendingRenewalPolicies(
        $emailA,
        $emailB,
        $connect = true,
        $dateA = null,
        $dateB = null,
        $additional = null,
        $valueA = 10,
        $valueB = 10
    ) {
        if (!$dateA) {
            $dateA = new \DateTime('2016-01-01');
        }
        $renewalDateA = clone $dateA;
        $renewalDateA = $renewalDateA->add(new \DateInterval('P350D'));
        if (!$dateB) {
            $dateB = new \DateTime('2016-01-01');
        }
        $renewalDateB = clone $dateB;
        $renewalDateB = $renewalDateB->add(new \DateInterval('P350D'));
        $userA = static::createUser(
            static::$userManager,
            $emailA,
            'bar',
            static::$dm
        );
        $userB = static::createUser(
            static::$userManager,
            $emailB,
            'bar',
            static::$dm
        );
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            clone $dateA,
            true
        );
        $policyB = static::initPolicy(
            $userB,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            clone $dateB,
            true
        );

        $policyA->setStatus(PhonePolicy::STATUS_PENDING);
        $policyB->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policyA, clone $dateA, true);
        static::$policyService->create($policyB, clone $dateB, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals($this->getCurrentIptRate($dateA), $policyA->getPremium()->getIptRate());
        $this->assertEquals($this->getCurrentIptRate($dateB), $policyB->getPremium()->getIptRate());

        if ($connect) {
            list($connectionA, $connectionB) = $this->createLinkedConnections(
                $policyA,
                $policyB,
                $valueA,
                $valueB,
                clone $dateA,
                clone $dateB
            );
        }

        $this->assertEquals(Policy::STATUS_ACTIVE, $policyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());

        $renewalPolicyA = static::$policyService->createPendingRenewal(
            $policyA,
            $renewalDateA
        );
        $renewalPolicyB = static::$policyService->createPendingRenewal(
            $policyB,
            $renewalDateB
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());
        $this->assertEquals($this->getCurrentIptRate($dateA), $policyA->getPremium()->getIptRate());
        $this->assertEquals($this->getCurrentIptRate($dateB), $policyB->getPremium()->getIptRate());
        $this->assertEquals($this->getCurrentIptRate($policyA->getEnd()), $renewalPolicyA->getPremium()->getIptRate());
        $this->assertEquals($this->getCurrentIptRate($policyB->getEnd()), $renewalPolicyB->getPremium()->getIptRate());

        if ($additional) {
            for ($i = 0; $i < $additional; $i++) {
                $user = static::createUser(
                    static::$userManager,
                    sprintf("%d%s", $i, $emailB),
                    'bar',
                    static::$dm
                );
                $policy = static::initPolicy(
                    $user,
                    static::$dm,
                    $this->getRandomPhone(static::$dm),
                    clone $dateB,
                    true
                );
                $policy->setStatus(PhonePolicy::STATUS_PENDING);
                static::$policyService->setEnvironment('prod');
                static::$policyService->create($policy, clone $dateB, true);
                static::$policyService->setEnvironment('test');
                static::$dm->flush();

                if ($connect) {
                    list($connectionA, $connectionB) = $this->createLinkedConnections(
                        $policyA,
                        $policy,
                        $valueA,
                        $valueB,
                        clone $dateA,
                        clone $dateB
                    );
                }

                $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

                $renewalPolicy = static::$policyService->createPendingRenewal(
                    $policy,
                    $renewalDateB
                );
            }
        }

        return [$policyA, $policyB];
    }

    /**
     * @param Container $container
     * @param User      $user
     * @return User|null
     */
    protected function assertUserExists($container, User $user)
    {
        /** @var DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);
        $updatedUser = $repo->find($user->getId());
        $this->assertNotNull($updatedUser);

        return $updatedUser;
    }

    protected function assertUserDoesNotExist($container, User $user)
    {
        /** @var DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);
        $updatedUser = $repo->find($user->getId());
        $this->assertNull($updatedUser);
    }

    protected function assertPolicyExists($container, Policy $policy)
    {
        return $this->assertPolicyByIdExists($container, $policy->getId());
    }

    protected function assertPolicyByIdExists($container, $id)
    {
        /** @var DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($id);
        $this->assertNotNull($updatedPolicy);

        return $updatedPolicy;
    }

    protected function assertPolicyDoesNotExist($container, Policy $policy)
    {
        /** @var DocumentManager $dm */
        $dm = $container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Policy::class);
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertNull($updatedPolicy);
    }
}
