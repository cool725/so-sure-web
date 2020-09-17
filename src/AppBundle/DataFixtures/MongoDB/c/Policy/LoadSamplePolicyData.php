<?php

namespace AppBundle\DataFixtures\MongoDB\c\Policy;

use AppBundle\Classes\Helvetia;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;
use AppBundle\Document\Subvariant;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\S3File;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PhonePremium;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PolicyTermsRepository;
use AppBundle\Repository\SubvariantRepository;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RouterService;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\SCode;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface, ContainerAwareInterface
{
    use ImeiTrait;
    use DateTrait;

    const CLAIM_NONE = 'none';
    const CLAIM_RANDOM = 'random';
    const CLAIM_SETTLED_LOSS = 'settled-loss';

    const CONNECTIONS_RANDOM_OR_NONE = 'random-or-none';
    const CONNECTIONS_RANDOM = 'random';
    const CONNECTIONS_ONE = 1;

    const PICSURE_RANDOM = 'random';
    const PICSURE_NON_POLICY = 'n/a';

    /**
     * @var ContainerInterface|null
     */
    private $container;

    private $faker;
    private $emails = [];
    private $receiptIds = [];
    private $bacsSubmissions = [];
    private $bacsInputReports = [];

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->faker = Faker\Factory::create('en_GB');
        $users = $this->newUsers($manager, 100);
        $manager->flush();
        $unpaid = $this->newUsers($manager, 10);
        $unpaidDiscount = $this->newUsers($manager, 10);
        $iosPreExpireUsers = $this->newUsers($manager, 10);
        $androidPreExpireUsers = $this->newUsers($manager, 10);
        $preExpireUsers = $this->newUsers($manager, 10);
        $preExpireYearlyUsers = $this->newUsers($manager, 10, true);
        $expiredUsers = $this->newUsers($manager, 10);
        $fullyExpiredUsers = $this->newUsers($manager, 10);
        $damageUsers = $this->newUsers($manager, 5);
        $essentialsUsers = $this->newUsers($manager, 5);
        $manager->flush();
        /** @var SubvariantRepository $subvariantRepo */
        $subvariantRepo = $manager->getRepository(Subvariant::class);
        $damage = $subvariantRepo->getSubvariantByName('damage');
        $essentials = $subvariantRepo->getSubvariantByName('essentials');

        $count = 0;
        foreach ($users as $user) {
            $this->newPolicy($manager, $user, $count);
            $count++;

            // add a second policy for some users
            $rand = random_int(1, 5);
            if ($rand == 1) {
                $this->newPolicy($manager, $user, $count);
                $count++;
            }
        }
        $manager->flush();
        foreach ($users as $user) {
            $this->addConnections($manager, $user, $users);
        }
        $manager->flush();
        foreach ($unpaid as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, false, true, 200, null, 1);
            $count++;
        }
        $manager->flush();
        foreach ($unpaidDiscount as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, false, true, 200, random_int(2, 50), 1);
            $count++;
        }
        foreach ($iosPreExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, true, true);
            $count++;
        }
        $manager->flush();
        foreach ($androidPreExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, true, true);
            $count++;
        }
        $manager->flush();
        foreach ($preExpireUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_RANDOM, null, null, null, null, true, 345);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();
        foreach ($preExpireYearlyUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_RANDOM, null, null, null, null, true, 345);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();
        foreach ($expiredUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, 366);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();
        foreach ($fullyExpiredUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, 396);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();
        foreach ($damageUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, null, null, null, self::PICSURE_RANDOM, null, Helvetia::NAME, $damage);
            $user->setEnabled(true);
            $count++;
        }
        foreach ($essentialsUsers as $user) {
            $this->newPolicy($manager, $user, $count, self::CLAIM_NONE, null, null, null, null, true, null, null, null, self::PICSURE_RANDOM, null, Helvetia::NAME, $essentials);
            $user->setEnabled(true);
            $count++;
        }
        $manager->flush();
        foreach ($preExpireUsers as $user) {
            $rand = random_int(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $preExpireUsers);
            }
        }
        $manager->flush();
        foreach ($preExpireYearlyUsers as $user) {
            $rand = random_int(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $preExpireYearlyUsers);
            }
        }
        $manager->flush();
        foreach ($fullyExpiredUsers as $user) {
            $rand = random_int(0, 1);
            if ($rand == 0) {
                $this->addConnections($manager, $user, $fullyExpiredUsers);
            }
        }
        $manager->flush();

        $phones = [];
        foreach ($preExpireYearlyUsers as $user) {
            $phones[] = $user->getPolicies()[0]->getPhone();
        }
        foreach ($preExpireYearlyUsers as $user) {
            $phones[] = $user->getPolicies()[0]->getPhone();
        }
        $fiveMonthsAgo = \DateTime::createFromFormat('U', time());
        $fiveMonthsAgo = $fiveMonthsAgo->sub(new \DateInterval('P5M'));
        $sixMonthsAgo = \DateTime::createFromFormat('U', time());
        $sixMonthsAgo = $sixMonthsAgo->sub(new \DateInterval('P6M'));
        $sevenMonthsAgo = \DateTime::createFromFormat('U', time());
        $sevenMonthsAgo = $sevenMonthsAgo->sub(new \DateInterval('P7M'));
        $adjusted = [];
        for ($i = 0; $i < 5; $i++) {
            /** @var Phone $phone */
            $phone = $phones[random_int(0, count($phones) - 1)];
            if (isset($adjusted[$phone->getId()])) {
                continue;
            }
            $adjusted[] = $phone->getId();
            /** @var PhonePremium $currentPrice */
            $currentPrice = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
            $adjustedPrice = $currentPrice->getGwp() - 0.01;
            if ($phone->getSalvaMiniumumBinderMonthlyPremium() < $currentPrice->getGwp() - 0.30) {
                $adjustedPrice = $currentPrice->getGwp() - 0.30;
            }
            $phone->changePrice(
                $adjustedPrice,
                $sixMonthsAgo, //feb feb > jan
                PolicyTerms::getHighExcess(),
                PolicyTerms::getLowExcess(),
                null,
                null,
                $sevenMonthsAgo
            );
        }
        $manager->flush();

        // Sample user for apple
        $user = $this->newUser('julien+apple@so-sure.com');
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++);

        // claimed user test data
        $networkUser = $this->newUser('user-network-claimed@so-sure.net');
        $networkUser->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $networkUser->setEnabled(true);
        $manager->persist($networkUser);
        $this->newPolicy($manager, $networkUser, $count++, self::CLAIM_SETTLED_LOSS);
        $manager->flush();
        //\Doctrine\Common\Util\Debug::dump($networkUser);

        $user = $this->newUser('user-claimed@so-sure.net');
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_SETTLED_LOSS);
        $this->addConnections($manager, $user, [$networkUser], 1);

        $user = $this->newUser('non-picsure@so-sure.net');
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            null,
            false,
            true,
            null,
            random_int(2, 50),
            3,
            self::PICSURE_NON_POLICY
        );

        // Users for iOS Testing
        $iphoneUI = $this->getIPhoneUI($manager);
        $userInviter = $this->newUser('ios-testing+inviter@so-sure.org', false, false);
        $userInviter->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $userInviter->setEnabled(true);
        $userInviter->setMobileNumberVerified(true);
        $manager->persist($userInviter);
        $this->newPolicy($manager, $userInviter, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true);

        $userInvitee = $this->newUser('ios-testing+invitee@so-sure.org', false, false);
        $userInvitee->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $userInvitee->setEnabled(true);
        $userInvitee->setMobileNumberVerified(true);
        $manager->persist($userInvitee);
        $this->newPolicy($manager, $userInvitee, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false);

        $user = $this->newUser('ios-testing+scode@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, 'IOS-TEST', $iphoneUI, true);

        $this->invite($manager, $userInviter, $userInvitee, false);
        $manager->flush();

        $user = $this->newUser('ios-testing+renew+pot@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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
        $manager->flush();

        /** @var PolicyTermsRepository $policyTermsRepo */
        $policyTermsRepo = $manager->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $aggregatorTerms */
        $aggregatorTerms = $policyTermsRepo->findOneBy(['latest' => true]);
        $user = $this->newUser('ios-testing+aggregator@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $iphoneUI,
            true,
            false,
            5,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Helvetia::NAME
        );
        $policy->setStatus(PhonePolicy::STATUS_PICSURE_REQUIRED);
        $policy->setPolicyTerms($aggregatorTerms);
        $user = $this->newUser('ios-testing+salva-upgrade@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $iphoneUI,
            true,
            false,
            31,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Salva::NAME
        );
        $user = $this->newUser('ios-testing+helvetia-upgrade@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $iphoneUI,
            true,
            false,
            31,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Helvetia::NAME
        );
        $user = $this->newUser('ios-testing+direct-connection@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false, 21);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $iphoneUI, true, false, 18);

        $user = $this->newUser('ios-testing+renew+nopot@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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
        $manager->flush();

        $user = $this->newUser('ios-testing+cashback@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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
        $manager->flush();

        // Users for Android Testing
        $androidUI = $this->getAndroidUI($manager);
        $userInviter = $this->newUser('android-testing+inviter@so-sure.org', false, false);
        $userInviter->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $userInviter->setEnabled(true);
        $userInviter->setMobileNumberVerified(true);
        $manager->persist($userInviter);
        $this->newPolicy($manager, $userInviter, $count++, self::CLAIM_NONE, null, null, $androidUI, true);

        $userInvitee = $this->newUser('android-testing+invitee@so-sure.org', false, false);
        $userInvitee->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $userInvitee->setEnabled(true);
        $userInvitee->setMobileNumberVerified(true);
        $manager->persist($userInvitee);
        $this->newPolicy($manager, $userInvitee, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false);

        $user = $this->newUser('android-testing+aggregator@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $androidUI,
            true,
            false,
            5,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Helvetia::NAME
        );
        $policy->setStatus(PhonePolicy::STATUS_PICSURE_REQUIRED);
        $policy->setPolicyTerms($aggregatorTerms);
        $user = $this->newUser('android-testing+salva-upgrade@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $androidUI,
            true,
            false,
            31,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Salva::NAME
        );
        $user = $this->newUser('android-testing+helvetia-upgrade@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $androidUI,
            true,
            false,
            31,
            null,
            null,
            self::PICSURE_RANDOM,
            false,
            Helvetia::NAME
        );
        $user = $this->newUser('android-testing+direct-connection@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false, 21);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, null, $androidUI, true, false, 18);

        $user = $this->newUser('android-testing+scode@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count++, self::CLAIM_NONE, null, 'AND-TEST', $androidUI, true);

        $this->invite($manager, $userInviter, $userInvitee, false);

        $user = $this->newUser('android-testing+renew+pot@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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

        $user = $this->newUser('android-testing+renew+nopot@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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

        $user = $this->newUser('android-testing+cashback@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
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

        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var PolicyService $policyService */
        $policyService = $this->container->get('app.policy');

        $policyRepo = $manager->getRepository(Policy::class);
        if (!$policyRepo->findOneBy(['status' => Policy::STATUS_UNPAID])) {
            throw new \Exception('missing unpaid policy');
        }

        // cancelled policy with outstanding payment due
        $user = $this->newUser('cancelled-policy-unpaid@so-sure.com', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $manager->persist($user);
        $policy = $this->newPolicy($manager, $user, $count++, self::CLAIM_SETTLED_LOSS, null, null, null, false);
        $policy->setStatus(Policy::STATUS_UNPAID);
        $policyService->cancel($policy, Policy::CANCELLED_UNPAID, true, null, true);

        /** @var PolicyRepository $policyRepo */
        $policyRepo = $manager->getRepository(Policy::class);
        $fraud = null;
        $unpaid = null;
        $maxAttempts = 0;

        $policies = $policyRepo->findBy(['status' => Policy::STATUS_ACTIVE]);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
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

        // unpaid policy with discount
        $user = $this->newUser('ios-testing+unpaid+discount@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $iphoneUI,
            false,
            true,
            180,
            random_int(2, 50),
            3
        );

        $user = $this->newUser('android-testing+unpaid+discount@so-sure.org', false, false);
        $user->setPlainPassword(LoadUserData::DEFAULT_PASSWORD);
        $user->setEnabled(true);
        $user->setMobileNumberVerified(true);
        $manager->persist($user);
        $policy = $this->newPolicy(
            $manager,
            $user,
            $count++,
            self::CLAIM_NONE,
            null,
            null,
            $androidUI,
            false,
            true,
            180,
            random_int(2, 50),
            3
        );

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

    private function newUser($email = null, $yearlyOnlyPostcode = false, $isPaymentMethodBacs = null)
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
            $rand = random_int(1, 3);
            if ($rand == 1) {
                $email = sprintf("%s.%s@%s", $user->getFirstName(), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 2) {
                $email = sprintf("%s%s@%s", mb_substr($user->getFirstName(), 0, 1), $user->getLastName(), explode("@", $email)[1]);
            } elseif ($rand == 3) {
                $email = sprintf("%s%s%2d@%s", mb_substr($user->getFirstName(), 0, 1), $user->getLastName(), random_int(1, 99), explode("@", $email)[1]);
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

        if (random_int(0, 1) == 0) {
            $charge = new Charge();
            $charge->setType(Charge::TYPE_ADDRESS);
            $user->addCharge($charge);
        }

        return $user;
    }

    /**
     * Creates a randomly generated payment method and returns it.
     * @param Policy  $policy              is the policy that will own the payment method as some of their details can
     *                                     be needed for setting up. NB: the policy's payment method field is not set.
     * @param boolean $isPaymentMethodBacs true iff this payment method should use bacs. Otherwise it will use checkout.
     * @return PaymentMethod that has been freshly created according to the given specifications.
     */
    private function getPaymentMethod(Policy $policy, $isPaymentMethodBacs = false)
    {
        $paymentMethod = null;
        if ($isPaymentMethodBacs) {
            $bankAccount = new BankAccount();
            $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
            $reference = sprintf('SOSURE%d', random_int(1, 999999));
            $bankAccount->setReference($reference);
            $bankAccount->setSortCode('000099');
            $bankAccount->setAccountNumber('87654321');
            if ($policy->getUser()) {
                $bankAccount->setAccountName($policy->getUser()->getName());
            }
            $bankAccount->setMandateSerialNumber(0);
            $this->giveRandomMandateStatus($bankAccount);
            $initialPaymentSubmissionDate = new \DateTime();
            $initialPaymentSubmissionDate = $this->addBusinessDays($initialPaymentSubmissionDate, 2);
            $bankAccount->setInitialPaymentSubmissionDate($initialPaymentSubmissionDate);
            $paymentMethod = new BacsPaymentMethod();
            $paymentMethod->setBankAccount($bankAccount);
        } else {
            $paymentMethod = new CheckoutPaymentMethod();
        }
        return $paymentMethod;
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
            $phone = $phones[random_int(0, count($phones) - 1)];
            if (!$phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY, new \DateTime('2016-01-01')) ||
                $phone->getMake() == "ALL"
            ) {
                $phone = null;
            }
        }

        return $phone;
    }

    /**
     * @param ObjectManager $manager
     * @param User          $user
     * @param integer       $count
     * @param string        $claim
     * @param string|null   $promo
     * @param string|null   $code
     * @param Phone|null    $phone
     * @param boolean|null  $paid
     * @param bool          $sendInvitation
     * @param integer|null  $days
     * @param float|null    $policyDiscount
     * @param integer|null  $paidMonths
     * @param string        $picSure
     * @param boolean       $isPaymentMethodBacs
     * @param string|null   $underwriter         Lets you determine the underwriter.
     * @return PhonePolicy
     * @throws \AppBundle\Exception\InvalidPremiumException
     */
    private function newPolicy(
        ObjectManager $manager,
        User $user,
        $count,
        $claim = self::CLAIM_RANDOM,
        $promo = null,
        $code = null,
        $phone = null,
        $paid = null,
        $sendInvitation = true,
        $days = null,
        $policyDiscount = null,
        $paidMonths = null,
        $picSure = self::PICSURE_RANDOM,
        $isPaymentMethodBacs = null,
        $underwriter = null,
        $subvariant = null
    ) {
        if (!$phone) {
            $phone = $this->getRandomPhone($manager);
        }
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var DocumentManager $dm */
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
        /** @var PolicyTerms $nonPicSureTerms */
        $nonPicSureTerms = $policyTermsRepo->findOneBy(['version' => 'Version 1 June 2016']);

        if (!$latestTerms || count($latestTerms->getAllowedExcesses()) == 0) {
            throw new \Exception('Missing latest terms');
        }
        if (!$nonPicSureTerms || count($nonPicSureTerms->getAllowedExcesses()) == 0) {
            throw new \Exception('Missing non pic-sure terms');
        }

        $startDate = \DateTime::createFromFormat('U', time());
        if ($days === null) {
            $days = sprintf("P%dD", random_int(0, 120));
        } else {
            $days = sprintf("P%dD", $days);
        }
        $startDate->sub(new \DateInterval($days));
        $policy = $underwriter === Salva::NAME ? new SalvaPhonePolicy() :
            $underwriter === Helvetia::NAME ? new HelvetiaPhonePolicy() :
            rand(0, 1) ? new SalvaPhonePolicy() : new HelvetiaPhonePolicy();
        $policy->setPaymentMethod(
            $this->getPaymentMethod($policy, ($isPaymentMethodBacs !== null) ? $isPaymentMethodBacs : (rand(0, 1) == 0))
        );
        $policy->setPhone($phone, null);
        $policy->setPremium($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->createPremium());
        $policy->setImei($this->generateRandomImei());
        if ($picSure == self::PICSURE_NON_POLICY) {
            $policy->init($user, $nonPicSureTerms,false);
        } else {
            $policy->init($user, $latestTerms, false);
        }
        if (!$code) {
            $policy->createAddSCode($count);
        } else {
            $scode = new SCode();
            $scode->setCode($code);
            $scode->setType(SCode::TYPE_STANDARD);
            $policy->addSCode($scode);
        }
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        /** @var RouterService $router */
        $router = $this->container->get('app.router');
        $shareUrl = $router->generateUrl(
            'scode',
            ['code' => $policy->getStandardSCode()->getCode()]
        );
        $policy->getStandardSCode()->setShareLink($shareUrl);

        $claimStatus = null;
        $claimType = null;
        if ($claim == self::CLAIM_RANDOM) {
            $claim = random_int(0, 3) == 0;
        } elseif ($claim == self::CLAIM_SETTLED_LOSS) {
            $claimStatus = Claim::STATUS_SETTLED;
            $claimType = Claim::TYPE_LOSS;
        }
        if (in_array($claim, [self::CLAIM_RANDOM, self::CLAIM_SETTLED_LOSS])) {
            $this->addClaim($dm, $policy, $claimType, $claimStatus);
        }
        if ($promo === null) {
            $promo = random_int(0, 1) == 0;
        }
        if ($promo) {
            $policy->setPromoCode(Policy::PROMO_LAUNCH);
        }

        $bacs = $policy->hasPolicyOrUserBacsPaymentMethod() && count($user->getValidPolicies(true)) < 1;

        $paymentDate = clone $startDate;
        if ($paid === true || ($paid === null && random_int(0, 1) == 0)) {
            if ($bacs) {
                $payment = $this->newBacsPayment(
                    $manager,
                    $policy,
                    $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getYearlyPremiumPrice(null, clone $startDate),
                    Salva::YEARLY_TOTAL_COMMISSION,
                    clone $paymentDate);
                $policy->addPayment($payment);
                $payment->submit(clone $paymentDate);
                $payment->approve($payment->getBacsReversedDate());

                // randomly add refunds
                if (rand(0, 9) == 0) {
                    $refund = $this->newBacsPayment(
                        $manager,
                        $policy,
                        $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getYearlyPremiumPrice(null, clone $startDate)*-1,
                        Salva::YEARLY_TOTAL_COMMISSION,
                        clone $paymentDate);
                    $policy->addPayment($refund);
                    $refund->submit(clone $paymentDate);
                    $refund->approve($refund->getBacsReversedDate());
                }
            } else {
                $payment = new CheckoutPayment();
                $payment->setResult(CheckoutPayment::RESULT_AUTHORIZED);
                $receiptId = "charge_test_" . random_int(1, 9999999);
                while (in_array($receiptId, $this->receiptIds)) {
                    $receiptId = "charge_test_" . random_int(1, 9999999);
                }
                $this->receiptIds[] = $receiptId;
                $payment->setReceipt($receiptId);
                $payment->setDate($paymentDate);
                $payment->setAmount($phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getYearlyPremiumPrice(null, clone $startDate));
                $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
                $payment->setNotes('LoadSamplePolicyData');
                $policy->addPayment($payment);
            }
        } else {
            $months = $paidMonths;
            $lastPaymentSuccess = true;
            if ($paidMonths === null) {
                $months = random_int(1, 11);
                $lastPaymentSuccess = random_int(0, 1) == 0;
            }
            for ($i = 1; $i <= $months; $i++) {
                if ($bacs) {
                    $payment = $this->newBacsPayment(
                        $manager,
                        $policy,
                        $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMonthlyPremiumPrice(null, clone $startDate),
                        $months == 12 ? Salva::FINAL_MONTHLY_TOTAL_COMMISSION : Salva::MONTHLY_TOTAL_COMMISSION,
                        clone $paymentDate);
                    $policy->addPayment($payment);
                    $payment->submit(clone $paymentDate);
                    $payment->approve($payment->getBacsReversedDate());

                    // randomly add refunds on last month
                    if (rand(0, 4) == 0 && $i == $months) {
                        $refund = $this->newBacsPayment(
                            $manager,
                            $policy,
                            $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMonthlyPremiumPrice(null, clone $startDate)*-1,
                            $months == 12 ? Salva::FINAL_MONTHLY_TOTAL_COMMISSION : Salva::MONTHLY_TOTAL_COMMISSION,
                            clone $paymentDate);
                        $policy->addPayment($refund);
                        $refund->submit(clone $paymentDate);
                        $refund->approve($refund->getBacsReversedDate());
                    }
                } else {
                    $payment = new CheckoutPayment();
                    if ($i == 1 || $i < $months || $lastPaymentSuccess) {
                        $payment->setResult(CheckoutPayment::RESULT_AUTHORIZED);
                    } else {
                        $payment->setResult(CheckoutPayment::RESULT_DECLINED);
                    }
                    $receiptId = "charge_test_" . random_int(1, 9999999);
                    while (in_array($receiptId, $this->receiptIds)) {
                        $receiptId = "charge_test_" . random_int(1, 9999999);
                    }
                    $this->receiptIds[] = $receiptId;
                    $payment->setReceipt($receiptId);
                    $payment->setDate(clone $paymentDate);
                    $payment->setAmount(
                        $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY)->getMonthlyPremiumPrice(null, clone $startDate)
                    );
                    $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                    if ($months == 12) {
                        $payment->setTotalCommission(Salva::FINAL_MONTHLY_TOTAL_COMMISSION);
                    }
                    $payment->setNotes('LoadSamplePolicyData');
                    $policy->addPayment($payment);
                }
                $paymentDate->add(new \DateInterval('P1M'));
                if (random_int(0, 3) == 0) {
                    $tDate = clone $paymentDate;
                    $tDate->add(new \DateInterval('P1D'));
                    if ($policy instanceof SalvaPhonePolicy) {
                        $policy->incrementSalvaPolicyNumber($tDate);
                    }
                }
            }
        }
        $manager->persist($policy);
        if (!$this->container) {
            throw new \Exception('missing container');
        }
        $env = $this->container->getParameter('kernel.environment');
        $policy->create(-5000 + $count, mb_strtoupper($env), $startDate);
        $now = \DateTime::createFromFormat('U', time());
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        if ($picSure == self::PICSURE_RANDOM) {
            $picSureStatus = null;
            $rand = random_int(0, 3);
            if ($rand == 0) {
                $picSureStatus = PhonePolicy::PICSURE_STATUS_APPROVED;
            } elseif ($rand == 1) {
                $picSureStatus = PhonePolicy::PICSURE_STATUS_REJECTED;
            } elseif ($rand == 2) {
                $picSureStatus = PhonePolicy::PICSURE_STATUS_INVALID;
            } elseif ($rand == 3) {
                $picSureStatus = PhonePolicy::PICSURE_STATUS_MANUAL;
            }
            $policy->setPicSureStatus($picSureStatus);
        }

        /** @var PolicyService $policyService */
        $policyService = $this->container->get('app.policy');
        $policyService->generateScheduledPayments($policy, $startDate, $startDate, 12, null, false);

        if ($policyDiscount) {
            $policy->setPolicyDiscountPresent(true);
            $policy->getPremium()->setAnnualDiscount($policyDiscount);
            $payment = new PolicyDiscountPayment();
            $payment->setDate(clone $startDate);
            $payment->setAmount(0 - $policyDiscount);
            $payment->setNotes('LoadSamplePolicyData');
            $policy->addPayment($payment);
        }

        if ($sendInvitation) {
            $invitation = new EmailInvitation();
            $invitation->setInviter($user);
            $invitation->setEmail($this->faker->email);
            $rand = random_int(0, 2);
            if ($rand == 0) {
                $invitation->setCancelled($policy->getStart());
            } elseif ($rand == 1) {
                $invitation->setRejected($policy->getStart());
            }
            $policy->addInvitation($invitation);
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

        if ($subvariant) {
            $policy->setSubvariant($subvariant);
        }

        return $policy;
    }

    private function newBacsPayment(ObjectManager $manager, Policy $policy, $amount, $totalComission, $paymentDate)
    {
        $user = $policy->getUser();
        $serialNumber = $paymentDate->format("ymd");
        $submissionFile = null;
        $inputFile = null;
        if (array_key_exists($serialNumber, $this->bacsSubmissions)) {
            $submissionFile = $this->bacsSubmissions[$serialNumber];
            $inputFile = $this->bacsInputReports[$serialNumber];
        }
        if (!$submissionFile) {
            $submissionFile = new AccessPayFile();
            $submissionFile->setDate($paymentDate);
            $submissionFile->setSerialNumber(AccessPayFile::formatSerialNumber($serialNumber));
            $submissionFile->addMetadata('debit-amount', 0.0);
            $submissionFile->addMetadata('credit-amount', 0.0);
            $submissionFile->setStatus(AccessPayFile::STATUS_SUBMITTED);
            $submissionFile->setSubmittedDate($paymentDate);
            $this->bacsSubmissions[$serialNumber] = $submissionFile;
            $inputFile = new BacsReportInputFile();
            $inputFile->setDate($paymentDate);
            $inputFile->addMetadata('serial-number', $serialNumber);
            $inputFile->addMetadata('debit-accepted-value', 0.0);
            $inputFile->addMetadata('credit-accepted-value', 0.0);
            $this->bacsInputReports[$serialNumber] = $inputFile;
        }

        $payment = new BacsPayment();
        $payment->setStatus(BacsPayment::STATUS_SUBMITTED);
        $payment->setSuccess(true);
        /** @var BacsPaymentMethod $bacsPaymentMethod */
        $bacsPaymentMethod = $policy->getBacsPaymentMethod();
        $bankAccount = $bacsPaymentMethod->getBankAccount();
        $bankAccount->setMandateSerialNumber($serialNumber);
        $manager->persist($bankAccount);
        $payment->setSerialNumber(AccessPayFile::formatSerialNumber($serialNumber));
        $payment->setDate(clone $paymentDate);
        $payment->setAmount($amount);
        $payment->setTotalCommission($totalComission);
        $payment->setNotes('LoadSamplePolicyData');

        if ($amount < 0.0) {
            $submissionFile->addMetadata('credit-amount', $submissionFile->getMetadata()['credit-amount']+$amount*-1);
            $inputFile->addMetadata('credit-accepted-value', $inputFile->getMetadata()['credit-accepted-value']+$amount*-1);
        } else {
            $submissionFile->addMetadata('debit-amount', $submissionFile->getMetadata()['debit-amount']+$amount);
            $inputFile->addMetadata('debit-accepted-value', $inputFile->getMetadata()['debit-accepted-value']+$amount);
        }
        $manager->persist($submissionFile);
        $manager->persist($inputFile);

        return $payment;
    }

    private function invite($manager, $userA, $userB, $accepted = true)
    {
        if (count($userA->getPolicies()) == 0 || count($userB->getPolicies()) == 0) {
            return;
        }
        /** @var Policy $policyA */
        $policyA = $userA->getPolicies()[0];
        /** @var Policy $policyB */
        $policyB = $userB->getPolicies()[0];

        $invitation = new EmailInvitation();
        $invitation->setInviter($userA);
        $policyA->addInvitation($invitation);
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
            if ($policyA->getMaxConnections() > 2) {
                $connections = random_int(0, $policyA->getMaxConnections() - 2);
            } else {
                $connections = 0;
            }
        } elseif ($connections == self::CONNECTIONS_RANDOM) {
            if ($policyA->getMaxConnections() > 4) {
                $connections = random_int(2, $policyA->getMaxConnections() - 2);
            } else {
                $connections = 2;
            }
        }

        //$connections = random_int(0, 3);
        $maxRetries = 20;
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[random_int(0, count($users) - 1)];
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
        $claim->setNumber(random_int(1, 999999));

        if (random_int(0, 1) == 0) {
            $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        } else {
            $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        }

        $date = \DateTime::createFromFormat('U', time());
        $date->sub(new \DateInterval(sprintf('P%dD', random_int(5,15))));
        $claim->setLossDate(clone $date);
        $date->add(new \DateInterval(sprintf('P%dD', random_int(0,4))));
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
        $claim->setTransactionFees(random_int(90,190) / 100);

        $phone = $this->getRandomPhone($manager);
        $claim->setReservedValue($phone->getReplacementPriceOrSuggestedReplacementPrice() + 15);
        if ($claim->getStatus() == Claim::STATUS_SETTLED &&
            in_array($claim->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            $claim->setReplacementPhone($phone);
            $claim->setReplacementImei($this->generateRandomImei());
            if (random_int(0, 1) == 0) {
                $claim->setReplacementReceivedDate(\DateTime::createFromFormat('U', time()));
            }
            $claim->setPhoneReplacementCost($phone->getReplacementPriceOrSuggestedReplacementPrice());
            $claim->setUnauthorizedCalls(random_int(0, 20000) / 100);
            $claim->setAccessories(random_int(0, 20000) / 100);
            $claim->setClosedDate(\DateTime::createFromFormat('U', time()));
        }
        $claim->setDescription($this->getRandomDescription($claim));
        $claim->setIncurred(array_sum([$claim->getClaimHandlingFees(), $claim->getTransactionFees(), $claim->getAccessories(),
            $phone->getReplacementPriceOrSuggestedReplacementPrice(), $claim->getUnauthorizedCalls()]));

        $claim->setTotalIncurred($claim->getIncurred() + $claim->getClaimHandlingFees());

        $policy->addClaim($claim);
        $manager->persist($claim);
    }

    /**
     * Sets the bank account's madate status and potentially cancellation reasons and cancellers.
     * @param BankAccount $bankAccount is the bank account that we are operating on.
     */
    protected function giveRandomMandateStatus($bankAccount)
    {
        $status = rand(0, 4);
        if ($status == 0) {
            $canceller = BankAccount::CANCELLERS[array_rand(BankAccount::CANCELLERS)];
            if (array_key_exists($canceller, BankAccount::CANCEL_REASONS)) {
                $bankAccount->cancelMandate($canceller, array_rand(BankAccount::CANCEL_REASONS[$canceller]));
            } elseif ($canceller == BankAccount::CANCELLER_SOSURE) {
                $bankAccount->cancelMandate($canceller, BankAccount::CANCEL_REASON_PERSONAL_DETAILS);
            } else {
                $bankAccount->cancelMandate($canceller, uniqid());
            }
        } elseif ($status == 1) {
            $bankAccount->setMandateStatus(BankAccount::MANDATE_FAILURE);
        } elseif ($status == 2) {
            $bankAccount->setMandateStatus(BankAccount::MANDATE_PENDING_APPROVAL);
        } elseif ($status == 3) {
            $bankAccount->setMandateStatus(BankAccount::MANDATE_PENDING_INIT);
        } elseif ($status == 4) {
            $bankAccount->setMandateStatus(BankAccount::MANDATE_SUCCESS);
        }
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

        return sprintf('%s - %s', ucfirst($claim->getType()), $data[random_int(0, count($data) - 1)]);
    }

    protected function getRandomStatus($type)
    {
        if (in_array($type, [Claim::TYPE_WARRANTY])) {
            $random = random_int(0, 1);
            if ($random == 0) {
                return Claim::STATUS_INREVIEW;
            } elseif ($random == 1) {
                return Claim::STATUS_WITHDRAWN;
            }
        }

        $random = random_int(0, 4);
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
        $type = random_int(0, 4);
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
