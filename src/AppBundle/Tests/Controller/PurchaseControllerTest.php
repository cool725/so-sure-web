<?php

namespace AppBundle\Tests\Controller;

use AppBundle\DataFixtures\MongoDB\d\Oauth2\LoadOauth2Data;
use AppBundle\Document\Feature;
use AppBundle\Document\LostPhone;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\FeatureService;
use AppBundle\Service\PCAService;
use AppBundle\Service\RouterService;
use AppBundle\Tests\Service\CheckoutServiceTest;
use function GuzzleHttp\Psr7\build_query;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\Classes\Salva;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\ReceperioService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\PurchaseControllerTest
 */
class PurchaseControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    const SEARCH_URL1_TEMPLATE = '/phone-insurance/%s';
    const SEARCH_URL2_TEMPLATE = '/phone-insurance/%s+%sGB';
    const LOSTSTOLEN_IMEI = '351451208401216';

    protected static $rootDir;

    /** @var FeatureService */
    protected static $featureService;

    /** @var CheckoutService */
    protected static $checkoutService;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$rootDir = self::$container->getParameter('kernel.root_dir');
        /** @var FeatureService $featureService */
        self::$featureService = self::$container->get('app.feature');
        self::$featureService->setEnabled(Feature::FEATURE_CARD_OPTION_WITH_BACS, true);

        /** @var  CheckoutService $checkoutService */
        $checkoutService = self::$container->get('app.checkout');
        self::$checkoutService = $checkoutService;
    }

    public function tearDown()
    {
        //self::$client->request('GET', '/logout');
        //self::$client->followRedirect();
        self::$client->getCookieJar()->clear();
    }

    public function setUp()
    {
        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, false);
        $this->assertFalse(self::$featureService->isEnabled(Feature::FEATURE_CHECKOUT));
    }

    public function testPurchaseOkNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $email = self::generateEmail('testPurchaseNew', $this);
        $crawler = $this->createPurchase(
            $email,
            'foo bar',
            new \DateTime('1980-01-01')
        );

        //self::verifyResponse(302);
        //$this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-missing-phone'));

        $dm = $this->getDocumentManager();
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $now = \DateTime::createFromFormat('U', time());

        $this->assertNotNull($user->getIdentityLog());
        $diff = $user->getIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);

        $this->assertNotNull($user->getLatestWebIdentityLog());
        $diff = $user->getLatestWebIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);
    }

    public function testPurchaseUserPhoneSpaceNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseUserPhoneSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01'),
            implode(' ', str_split(self::generateRandomMobile(), 1))
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserDiffDetailsNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserDiffDetailsNew', $this),
            'foo'
        );

        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    /**
     * Tests if user can progress through the purchase flow with personal details unset.
     */
    public function testPurchaseMissingPersonalDetailsPledge()
    {
        $phone = $this->getRandomPhoneAndSetSession();
        $email = self::generateEmail('testPurchaseMissingPersonalDetailsPledge', $this);
        $user = self::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));
        self::verifyResponse(302);
        $crawler = $this->setPhone($phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $user = self::$dm->getRepository(User::class)->findBy(["email" => $email])[0];
        $user->setMobileNumber("");
        static::$dm->flush();
        $crawler = $this->agreePledge($crawler);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('step-personal', self::$client->getHistory()->current()->getUri());
    }

    /**
     * Tests if user can progress through the purchase flow with personal details unset.
     */
    public function testPurchaseMissingPersonalDetailsPayment()
    {
        $phone = $this->getRandomPhoneAndSetSession();
        $email = self::generateEmail('testPurchaseMissingPersonalDetailsPayment', $this);
        $user = self::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));
        self::verifyResponse(302);
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());
        $crawler = $this->setPhone($phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());
        $crawler = $this->agreePledge($crawler);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());
        $user = self::$dm->getRepository(User::class)->findBy(["email" => $email])[0];
        $user->setMobileNumber("");
        static::$dm->flush();
        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('step-personal', self::$client->getHistory()->current()->getUri());
    }

    public function testPurchaseExistingUserWithPolicyDiffDetailsNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserWithPolicyDiffDetailsNew', $this),
            'foo'
        );
        // TODO: add policy
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $crawler = $this->createPurchaseUserNew($user, 'not me', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/login'));
    }

    public function testPurchaseExistingUserSameDetailsNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsNew', $this),
            'foo',
            self::getRandomPhone(self::$dm)
        );

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserSameDetailsWithPartialPolicyNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithPartialPolicyNew', $this),
            'foo',
            $phone
        );
        self::initPolicy($user, self::$dm, $phone);

        $crawler = $this->createPurchaseUserNew($user, 'foo bar', new \DateTime('1980-01-01'));

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyNew', $this),
            'foo',
            $phone
        );
        $policy1 = self::initPolicy($user, self::$dm, $phone);
        sleep(1);
        $policy2 = self::initPolicy($user, self::$dm, $phone);

        $this->login($user->getEmail(), 'foo', 'user/invalid');

        $crawler = $this->setPhone($phone, $policy1->getImei());

        self::verifyResponse(302);
        $this->assertRedirectionPathPartial('/purchase/step-pledge');
    }

    public function testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyExisting()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPurchaseExistingUserSameDetailsWithMultiplePartialPolicyExisting', $this),
            'foo',
            $phone
        );
        $imei1 = self::generateRandomImei();
        $policy1 = self::initPolicy($user, self::$dm, $phone, null, false, false, true, $imei1);
        $infiniteLoopCount = 0;
        $imei2 = self::generateRandomImei();
        while ($imei1 == $imei2) {
            usleep(100);
            $imei2 = self::generateRandomImei();
            $infiniteLoopCount++;
            if ($infiniteLoopCount > 100) {
                break;
            }
        }
        $this->assertNotEquals($imei1, $imei2);
        $policy2 = self::initPolicy($user, self::$dm, $phone, null, false, false, true, $imei1);

        $this->login($user->getEmail(), 'foo', 'user/invalid');

        $crawler = $this->setPhone($phone, $policy1->getImei(), null, null, $policy1);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(
            sprintf('/purchase/step-pledge/%s', $policy1->getId())
        ), $crawler->html());
    }

    public function testStarlingLead()
    {
        $phone = self::getRandomPhone(self::$dm);

        /** @var SessionInterface $session */
        $session = self::$container->get('session');
        $session->set('oauth2Flow', 'starling');
        $session->set('quote', $phone->getId());
        $session->save();
        static::$client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        $email = self::generateEmail('testStarling', $this);
        $crawler = $this->createPurchase(
            self::generateEmail('testStarling', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        /** @var UserRepository $repo */
        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($user);
        if ($user) {
            $this->assertEquals(Lead::LEAD_SOURCE_AFFILIATE, $user->getLeadSource());
            $this->assertEquals('starling', $user->getLeadSourceDetails());
        }
    }

    public function testPurchaseAddressNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseAddressNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));
    }

    public function testPurchasePhone()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        // test without the judo flag
        self::$featureService->setEnabled(Feature::FEATURE_CARD_OPTION_WITH_BACS, false);
        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $this->assertTrue($cardButton->count() == 0);

        // test with the judo flag
        self::$featureService->setEnabled(Feature::FEATURE_CARD_OPTION_WITH_BACS, true);
        self::$client->back(); // takes us back to the redirect notice
        $crawler = self::$client->back(); // takes us back to the payment page
        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $this->assertTrue($cardButton->count() == 1);
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneCheckout()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneCheckout', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());
        $url = self::$client->getHistory()->current()->getUri();
        $this->assertContains('/purchase/step-pledge', $url);
        $policyId = mb_substr(
            $url,
            mb_stripos($url, '/step-pledge/') + mb_strlen('/step-pledge/')
        );

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        // test with the card option & checkout flag
        self::$featureService->setEnabled(Feature::FEATURE_CARD_OPTION_WITH_BACS, true);
        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);
        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $this->assertTrue($cardButton->count() == 1);
        $cardNode = $cardButton->getNode(0);
        $this->assertNotNull($cardNode);
        if ($cardNode) {
            $this->assertContains('btn-card-pay', $cardNode->getAttribute('class'));
        }

        $csrf = null;
        $url = null;
        $paymentNode = $crawler->filter('.payment-form')->getNode(0);
        $this->assertNotNull($paymentNode);
        if ($paymentNode) {
            $csrf = $paymentNode->getAttribute('data-csrf');
            $url = $paymentNode->getAttribute('data-url');
        }

        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $this->convertToPennies($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice()),
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user');
        self::verifyResponse(200);
    }

    public function testPurchasePhoneCheckoutFailed()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneCheckoutFailed', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());
        $url = self::$client->getHistory()->current()->getUri();
        $this->assertContains('/purchase/step-pledge', $url);
        $policyId = mb_substr(
            $url,
            mb_stripos($url, '/step-pledge/') + mb_strlen('/step-pledge/')
        );

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        // test with the card option & checkout flag
        self::$featureService->setEnabled(Feature::FEATURE_CARD_OPTION_WITH_BACS, true);
        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);
        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $this->assertTrue($cardButton->count() == 1);
        $cardNode = $cardButton->getNode(0);
        $this->assertNotNull($cardNode);
        if ($cardNode) {
            $this->assertContains('btn-card-pay', $cardNode->getAttribute('class'));
        }

        $csrf = null;
        $url = null;
        $paymentNode = $crawler->filter('.payment-form')->getNode(0);
        $this->assertNotNull($paymentNode);
        if ($paymentNode) {
            $csrf = $paymentNode->getAttribute('data-csrf');
            $url = $paymentNode->getAttribute('data-url');
        }

        // need to adjust pennies on the querystring. This is only because we're adjusting the amount to fail
        // the payment (as required by checkout)
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $queryData);
        $queryData['pennies'] = $this->convertToPennies(CheckoutServiceTest::$CHECKOUT_TEST_CARD_FAIL_AMOUNT);
        $port = parse_url($url, PHP_URL_PORT);
        $url = sprintf(
            '%s://%s%s%s?%s',
            parse_url($url, PHP_URL_SCHEME),
            parse_url($url, PHP_URL_HOST),
            mb_strlen($port) > 0 ? ':' . $port : null,
            parse_url($url, PHP_URL_PATH),
            build_query($queryData)
        );

        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_FAIL_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_FAIL_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_FAIL_PIN
        );
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $this->convertToPennies(CheckoutServiceTest::$CHECKOUT_TEST_CARD_FAIL_AMOUNT),
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);

        $crawler = self::$client->request('GET', '/user');
        self::verifyResponse(302);
        $this->assertRedirectionPathPartial('/purchase/step-phone');
    }

    public function testPurchasePhoneBacs()
    {
        /** @var FeatureService $feature */
        $feature = $this->getContainer(true)->get('app.feature');
        $this->assertTrue($feature->isEnabled(Feature::FEATURE_BACS), 'Bacs feature disabled');

        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneBacs', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302, null, $crawler);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();

        $policy = $this->getPolicyFromPaymentUrl();
        $url = sprintf('%s?force=bacs', self::$client->getHistory()->current()->getUri());
        $crawler = self::$client->request('GET', $url);
        $this->assertNotContains(
            'judo',
            $crawler->html(),
            sprintf('%s Payment page is referencing judopay', self::$client->getHistory()->current()->getUri())
        );
        //print $crawler->html();
        //print $url;

        $crawler = $this->setPayment($crawler, $phone);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment/', self::$client->getHistory()->current()->getUri());
        $this->assertContains('/monthly', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setBacs($crawler, $policy);
        self::verifyResponse(200);
        $crawler = $this->setBacsConfirm($crawler);
        // print $crawler->html();

        self::verifyResponse(302, null, $crawler);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        //print $crawler->html();
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl), 'Redirect to user welcome page');
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testPurchasePhonePartial()
    {
        /** @var FeatureService $feature */
        $feature = $this->getContainer(true)->get('app.feature');
        $this->assertTrue($feature->isEnabled(Feature::FEATURE_BACS), 'Bacs feature disabled');

        $email = static::generateEmail('testPurchasePhonePartial', $this);
        $phone = $this->getRandomPhone(static::$dm);
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar',
            $phone,
            static::$dm
        );
        self::addAddress($user);
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $phone
        );

        $url = sprintf('/purchase/step-pledge/%s', $policy->getId());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(302, null, $crawler);
        $this->login($email, 'bar');

        $crawler = self::$client->request('GET', $url);

        // print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();

        $policy = $this->getPolicyFromPaymentUrl();
        $url = sprintf('%s?force=bacs', self::$client->getHistory()->current()->getUri());
        $crawler = self::$client->request('GET', $url);
        $this->assertNotContains(
            'judo',
            $crawler->html(),
            sprintf('%s Payment page is referencing judopay', self::$client->getHistory()->current()->getUri())
        );
        //print $crawler->html();
        //print $url;

        $crawler = $this->setPayment($crawler, $policy->getPhone());
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment/', self::$client->getHistory()->current()->getUri());
        $this->assertContains('/monthly', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setBacs($crawler, $policy);
        self::verifyResponse(200);
        $crawler = $this->setBacsConfirm($crawler);
        // print $crawler->html();

        self::verifyResponse(302, null, $crawler);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        //print $crawler->html();
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl), 'Redirect to user welcome page');
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testPurchasePhoneBacsInvalidAccountNumber()
    {
        /** @var FeatureService $feature */
        $feature = $this->getContainer(true)->get('app.feature');
        $this->assertTrue($feature->isEnabled(Feature::FEATURE_BACS), 'Bacs feature disabled');

        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneBacsInvalidAccountNumber', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302, null, $crawler);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();

        $policy = $this->getPolicyFromPaymentUrl();
        $url = sprintf('%s?force=bacs', self::$client->getHistory()->current()->getUri());
        $crawler = self::$client->request('GET', $url);
        $this->assertNotContains(
            'judo',
            $crawler->html(),
            sprintf('%s Payment page is referencing judopay', self::$client->getHistory()->current()->getUri())
        );
        //print $crawler->html();
        //print $url;

        $crawler = $this->setPayment($crawler, $phone);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment/', self::$client->getHistory()->current()->getUri());
        $this->assertContains('/monthly', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setBacs($crawler, $policy, PCAService::TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER);
        self::verifyResponse(200);
        //print $crawler->html();

        $this->assertTrue($crawler->selectButton('bacs_form[save]')->count() > 0);
        $this->assertTrue($crawler->selectButton('bacs_confirm_form[save]')->count() == 0);

        $crawler = $this->setBacs($crawler, $policy, PCAService::TEST_ACCOUNT_NUMBER_OK);
        self::verifyResponse(200);
        //print $crawler->html();

        $this->assertTrue($crawler->selectButton('bacs_form[save]')->count() == 0);
        $this->assertTrue($crawler->selectButton('bacs_confirm_form[save]')->count() > 0);
    }

    private function getPolicyFromPaymentUrl()
    {
        $urlData = explode('/', self::$client->getHistory()->current()->getUri());
        //print_r($urlData);
        $this->assertCount(6, $urlData);
        $policyId = $urlData[5];
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($policyId);
        $this->assertNotNull($policy);

        return $policy;
    }

    public function testPurchasePhoneNoPledge()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNoPledge', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect());
        self::$client->followRedirect();
        $this->assertContains('/purchase/step-phone', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPhone($phone);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler, false);
        //print $crawler->html();
        self::verifyResponse(200);
    }

    /*
    public function testPurchasePhoneNewUploadFile()
    {
        $phone = $this->setRandomPhone('Apple');

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneNewUploadFile', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone, null, true, null, null, true);
        //print $crawler->html();
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
        $this->verifyImei($crawler, '355424073417084', 'C77QMB7SGRY9');
    }
    */

    /**
     *
     */
    public function testPurchasePhoneImeiSpaceNineSixtyEightNew()
    {
        $phoneRepo = static::$dm->getRepository(Phone::class);
        /** @var Phone $phone */
        $phone = $phoneRepo->findOneBy(['devices' => 'zeroflte', 'memory' => 128]);
        //$phone = self::getRandomPhone(static::$dm);

        // set phone in session
        $this->setPhoneSession($phone);

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpaceNineSixtyEightNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSpaceNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSpaceNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode(' ', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneLostImei()
    {
        $lostPhone = new LostPhone();
        $lostPhone->setImei(self::LOSTSTOLEN_IMEI);
        self::$dm->persist($lostPhone);
        self::$dm->flush();

        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneLostImei', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone, self::LOSTSTOLEN_IMEI);

        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'Sorry, it looks this phone is already insured');
    }

    public function testPurchaseChangePhone()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseChangePhone', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains($phone->getModel(), $crawler->html());

        $infiniteLoopCount = 0;
        $phone2 = $this->getRandomPhone(static::$dm);
        while ($phone->getId() == $phone2->getId()) {
            usleep(100);
            $phone2 = $this->getRandomPhone(static::$dm);
            $infiniteLoopCount++;
            if ($infiniteLoopCount > 100) {
                break;
            }
        }
        $this->assertNotEquals($phone2->getId(), $phone->getId());

        $crawler = $this->changePhone($phone2);
        self::verifyResponse(200);
        $this->assertContains($phone2->getModel(), $crawler->html());
    }

    public function testRePurchase()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testRePurchase', $this),
            'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2016-01-01'),
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'), true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();
        $this->assertEquals(new \DateTimeZone(Salva::SALVA_TIMEZONE), $policy->getStart()->getTimeZone());

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        static::$policyService->expire($policy, new \DateTime('2017-01-01'));

        $this->login($user->getEmail(), 'bar', 'user/invalid');

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('user_repurchase_policy', ['id' => $policy->getId()])
        );
        $crawler = self::$client->followRedirect();
        //print_r($crawler->html());
        self::verifyResponse(200);
        $this->assertEquals('readonly', $crawler->filter('#purchase_form_imei')->attr('readonly'));
        $this->assertNotEquals('readonly', $crawler->filter('#purchase_form__token')->attr('readonly'));
    }

    public function testPurchasePhoneImeiDashNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiDashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode('-', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiSlashNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiSlashNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = implode('/', str_split(self::generateRandomImei(), 3));
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchasePhoneImeiS7New()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchasePhoneImeiS7New', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $imei = sprintf('%s/71', self::generateRandomImei());
        $crawler = $this->setPhone($phone, $imei);

        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewToJudopay()
    {
        // unable to implement test
        // form post needs to be direct to judopay
        // symfony tests are unable to perform client side events
    }

    public function testPurchaseReviewWithAcceptNew()
    {
        $phone = $this->getRandomPhoneAndSetSession();

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewRequiresAcceptNew', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302, null, $crawler, 'create Purchase');
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = $this->setPhone($phone);

        self::verifyResponse(302, null, $crawler, sprintf('Set Phone %s', $phone->__toString()));
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-pledge', self::$client->getHistory()->current()->getUri());

        //print $crawler->html();
        $crawler = $this->agreePledge($crawler);
        //print $crawler->html();
        self::verifyResponse(302, null, $crawler, 'pledge');
        $crawler = self::$client->followRedirect();
        $this->assertContains('/purchase/step-payment', self::$client->getHistory()->current()->getUri());

        $crawler = $this->setPayment($crawler, $phone);
        self::verifyResponse(302, null, $crawler, 'payment');
        $crawler = self::$client->followRedirect();

        $cardButton = $crawler->selectButton('to_card_form[submit]');
        $form = $cardButton->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200, null, $crawler, 'card');
        $this->verifyPurchaseReady($crawler);
    }

    public function testPurchaseReviewWithInvalidSerial()
    {
        $phone = $this->getRandomPhoneAndSetSession('Apple');

        $crawler = $this->createPurchase(
            self::generateEmail('testPurchaseReviewWithInvalidSerial', $this),
            'foo bar',
            new \DateTime('1980-01-01')
        );

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/purchase/step-phone'));

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone')
        );
        self::verifyResponse(200, null, $crawler);
        $this->assertNotContains('Get a Quote', $crawler->html());

        $crawler = $this->setPhone($phone, null, null, ReceperioService::TEST_INVALID_SERIAL);

        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'we are unable to insure you');
    }

    public function testPurchaseReviewNotIOSWithNoPhoneSession()
    {
        $phone = self::getRandomPhone(static::$dm, 'Apple');
        $email = self::generateEmail('testPurchaseReviewNotIOSWithNoPhoneSession', $this);

        $user = static::createUser(
            static::$userManager,
            $email,
            'bar',
            static::$dm
        );
        static::addAddress($user);
        static::$dm->flush();

        $this->logout();
        self::$client->getCookieJar()->clear();

        $this->login($email, 'bar');

        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone')
        );
        self::verifyResponse(200, null, $crawler);
        $this->assertContains('Get a Quote', $crawler->html());
    }

    public function testPurchaseReviewIOSWithNoPhoneSession()
    {
        $phone = self::getRandomPhone(static::$dm, 'Apple');
        $email = self::generateEmail('testPurchaseReviewIOSWithNoPhoneSession', $this);

        $user = static::createUser(
            static::$userManager,
            $email,
            'bar',
            static::$dm
        );
        static::addAddress($user);
        static::$dm->flush();

        $this->logout();
        self::$client->getCookieJar()->clear();

        $this->login($email, 'bar');

        // @codingStandardsIgnoreStart
        $crawler = self::$client->request(
            'GET',
            self::$router->generate('purchase_step_phone'),
            [],
            [],
            ['HTTP_USER_AGENT' => "Mozilla/5.0 (iPhone; CPU iPhone OS 9_1 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Version/9.0 Mobile/13B143 Safari/601.1"]
        );
        // @codingStandardsIgnoreEnd
        self::verifyResponse(200, null, $crawler);
        $this->assertContains('Get a Quote', $crawler->html());
    }

    public function testPayCC()
    {
        $dm = $this->getDocumentManager();
        $email = self::generateEmail('testPayCC', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testPayCCRetry()
    {
        $dm = $this->getDocumentManager();
        $email = self::generateEmail('testPayCCRetry', $this);
        $password = 'foo';
        $phone = self::getRandomPhone($dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            $dm
        );
        $policy = self::initPolicy($user, $dm, $phone);
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $payment->setReference($policy->getId());
        $payment->setUser($user);
        $policy->addPayment($payment);
        $dm->flush();

        $response = static::runJudoPayPayment(
            self::$container->get('app.judopay'),
            $user,
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice()
        );

        // change to use a flash message for purchase
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);
        self::verifyResponse(302);
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);

        $crawler = self::$client->request('POST', '/purchase/cc/success', [
            'Reference' => $policy->getId(),
            'ReceiptId' => $response['receiptId'],
            'CardToken' => $response['consumer']['consumerToken']
        ]);

        self::verifyResponse(302);
        // TODO: Current user welcome will only be displayed if one payment has been made,
        // but should check the actual paid amount on policy as well in the future
        $redirectUrl = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->assertTrue($this->isClientResponseRedirect($redirectUrl));
        $crawler = self::$client->followRedirect();
        self::verifyResponse(200);
    }

    public function testLeadSource()
    {
        $email = self::generateEmail('testLeadSource', $this);
        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        /** @var Lead $lead */
        $lead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(mb_strtolower($email), $lead->getEmailCanonical());
        $this->assertEquals('foo bar', $lead->getName());
    }

    public function testLeadSourceMissingParam()
    {
        $email = self::generateEmail('testLeadSourceMissingParam', $this);

        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
            ])
        );
        self::verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLeadSourceInvalidCsrf()
    {
        $email = self::generateEmail('testLeadSourceInvalidCsrf', $this);
        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = sprintf('%s.', $link->attr('data-csrf'));

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLeadSourceBadName()
    {
        $email = self::generateEmail('testLeadSourceBadName', $this);
        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo bar bar',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200);

            $dm = $this->getDocumentManager(true);
            $leadRepo = $dm->getRepository(Lead::class);
        /** @var Lead $lead */
        $lead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotNull($lead);
        $this->assertEquals(mb_strtolower($email), $lead->getEmailCanonical());
        $this->assertNull($lead->getName());
    }

    private function createPurchaseUser($user, $name, $birthday)
    {
        return $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function createPurchaseUserNew($user, $name, $birthday)
    {
        return $this->createPurchase($user->getEmail(), $name, $birthday, $user->getMobileNumber());
    }

    private function verifyPurchaseReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertContains('judopay', $form->getUri());
    }

    private function verifyPurchaseNotReady($crawler)
    {
        $form = $crawler->filterXPath('//form[@id="webpay-form"]')->form();
        $this->assertNotContains('judopay', $form->getUri());
    }

    private function verifyImei(Crawler $crawler, $imei, $serialNumber = null)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $this->assertEquals($imei, $form['purchase_form[imei]']->getValue());
        if ($serialNumber) {
            $this->assertEquals($serialNumber, $form['purchase_form[serialNumber]']->getValue());
        }
    }

    private function setPhone(
        Phone $phone,
        $imei = null,
        $crawler = null,
        $serialNumber = null,
        Policy $policy = null
    ) {
        if (!$crawler) {
            if ($policy) {
                $crawler = self::$client->request('GET', sprintf('/purchase/step-phone/%s', $policy->getId()));
            } else {
                $crawler = self::$client->request('GET', '/purchase/step-phone');
            }
        }
        //print $crawler->html();
        $form = $crawler->selectButton('purchase_form[next]')->form();

        //if (!$imei && !$useFile) {
        if (!$imei) {
            $imei = self::generateRandomImei();
        }
        $form['purchase_form[imei]'] = $imei;
        if ($phone->isApple()) {
            // use a different number in case we're testing /, -, etc
            //if (!$serialNumber && !$useFile) {
            if (!$serialNumber) {
                $serialNumber = self::generateRandomAppleSerialNumber();
            }
            $form['purchase_form[serialNumber]'] = $serialNumber;
        }
        /*
        if ($useFile) {
            $imeiFile = sprintf(
                "%s/../src/AppBundle/Tests/Resources/iPhoneSettings.png",
                self::$rootDir
            );
            $imei = new UploadedFile(
                $imeiFile,
                'imei.png',
                'image/png',
                95062
            );
            $form['purchase_form[file]']->upload($imei);
        }
        */
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function agreePledge(Crawler $crawler, $agreed = 1)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $agreedCheckboxes = ['agreedDamage', 'agreedAgeLocation', 'agreedExcess', 'agreedTerms'];
        foreach ($agreedCheckboxes as $checkbox) {
            if ($agreed) {
                try {
                    $form['purchase_form[' . $checkbox . ']'] = $agreed;
                } catch (\Exception $e) {
                    $form['purchase_form[' . $checkbox . ']'] = 'checked';
                }
            }
        }
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setPayment(Crawler $crawler, $phone)
    {
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[amount]'] = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice();
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setBacs(Crawler $crawler, Policy $policy, $accountNumber = PCAService::TEST_ACCOUNT_NUMBER_OK)
    {
        //print $crawler->html();
        $form = $crawler->selectButton('bacs_form[save]')->form();
        $form['bacs_form[accountName]'] = $policy->getUser()->getName();
        $form['bacs_form[sortCode]'] = PCAService::TEST_SORT_CODE;
        $form['bacs_form[accountNumber]'] = $accountNumber;
        $form['bacs_form[soleSignature]'] = true;
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function setBacsConfirm(Crawler $crawler)
    {
        $form = $crawler->selectButton('bacs_confirm_form[save]')->form();
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    private function changePhone($phone)
    {
        $crawler = self::$client->request('GET', sprintf('/select-phone-search/purchase-change/%s', $phone->getId()));
        $crawler = self::$client->followRedirect();

        return $crawler;
    }

    private function createPurchase($email, $name, $birthday, $mobile = null)
    {
        if (!$mobile) {
            $mobile = self::generateRandomMobile();
        }
        $crawler = self::$client->request('GET', '/purchase/');
        self::verifyResponse(200);
        $this->assertNotContains('no phone selected', $crawler->html());
        $form = $crawler->selectButton('purchase_form[next]')->form();
        $form['purchase_form[email]'] = $email;
        $form['purchase_form[name]'] = $name;
        $form['purchase_form[birthday]'] = sprintf("%s", $birthday->format('d/m/Y'));
        $form['purchase_form[mobileNumber]'] = $mobile;
        $form['purchase_form[addressLine1]'] = '123 Foo St';
        $form['purchase_form[city]'] = 'Unknown';
        $form['purchase_form[postcode]'] = 'BX1 1LT';
        $crawler = self::$client->submit($form);

        return $crawler;
    }

    public function testLeadInvalidEmail()
    {
        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => 'foo',
                'name' => 'foo',
                'csrf' => $csrfToken,
            ])
        );

        self::verifyResponse(200, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLeadInvalidName()
    {
        $email = self::generateEmail('testLeadInvalidName', $this);
        $this->getRandomPhoneAndSetSession();
        $crawler = self::$client->request('GET', '/purchase/?force_result=new');
        self::verifyResponse(200);

        $link = $crawler->filter('#step--validate');
        $csrfToken = $link->attr('data-csrf');

        $crawler = self::$client->request(
            'POST',
            '/purchase/lead/buy',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode([
                'email' => $email,
                'name' => 'foo',
                'csrf' => $csrfToken,
            ])
        );
        self::verifyResponse(200, ApiErrorCode::SUCCESS);
    }

    public function testPhoneSearchPurchasePage()
    {
        $crawler = self::$client->request('GET', '/purchase/');
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
    }

    /**
     * Tests to make sure that you can request cancellation of your policy, and that in cooloff it will automatically
     * action it.
     */
    public function testCancelPolicy()
    {
        $a = $this->createUserPolicy(true, new \DateTime("-5 months"));
        $b = $this->createUserPolicy(true, new \DateTime("-1 weeks"));
        $c = $this->createUserPolicy(true, new \DateTime("-1 weeks"));
        $a->setStatus(Policy::STATUS_ACTIVE);
        $b->setStatus(Policy::STATUS_ACTIVE);
        $c->setStatus(Policy::STATUS_ACTIVE);
        $a->getUser()->setEmail(self::generateEmailClass("aaa", "testCancelPolicy"));
        $b->getUser()->setEmail(self::generateEmailClass("bbb", "testCancelPolicy"));
        $c->getUser()->setEmail(self::generateEmailClass("ccc", "testCancelPolicy"));
        self::$dm->persist($a);
        self::$dm->persist($b);
        self::$dm->persist($c);
        self::$dm->persist($a->getUser());
        self::$dm->persist($b->getUser());
        self::$dm->persist($c->getUser());
        self::$dm->flush();
        // Normal cancellation request.
        $crawler = $this->cancelForm($a, Policy::COOLOFF_REASON_EXISTING);
        $this->expectFlashSuccess($crawler, "We have passed your request to our policy team.");
        $this->assertEquals(Policy::COOLOFF_REASON_EXISTING, $a->getRequestedCancellationReason());
        $this->assertEquals(Policy::STATUS_ACTIVE, $a->getStatus());
        // Cooloff cancellation.
        $crawler = $this->cancelForm($b, Policy::COOLOFF_REASON_PICSURE);
        $this->expectFlashSuccess($crawler, "You should receive an email confirming that your policy is now cancelled");
        $this->assertEquals(Policy::COOLOFF_REASON_PICSURE, $b->getRequestedCancellationReason());
        $this->assertEquals(Policy::STATUS_CANCELLED, $b->getStatus());
        $this->assertEquals(Policy::CANCELLED_COOLOFF, $b->getCancelledReason());
        // Cooloff cancellation with custom reason.
        $crawler = $this->cancelForm($c, Policy::COOLOFF_REASON_UNKNOWN, "my phone is cursed.");
        $this->expectFlashSuccess($crawler, "You should receive an email confirming that your policy is now cancelled");
        $this->assertEquals(Policy::COOLOFF_REASON_UNKNOWN, $c->getRequestedCancellationReason());
        $this->assertEquals("my phone is cursed.", $c->getRequestedCancellationReasonOther());
        $this->assertEquals(Policy::STATUS_CANCELLED, $c->getStatus());
        $this->assertEquals(Policy::CANCELLED_COOLOFF, $c->getCancelledReason());
        // Duplicate cancellation.
        $crawler = $this->cancelForm($a, Policy::COOLOFF_REASON_DAMAGED);
        $this->expectFlashWarning($crawler, "Cancellation has already been requested and is currently processing.");
        $this->assertEquals(Policy::COOLOFF_REASON_EXISTING, $a->getRequestedCancellationReason());
        $this->assertEquals(Policy::STATUS_ACTIVE, $a->getStatus());
    }

    /**
     * logs in opens the cancellation form and submits it with given parameters.
     * @param Policy $policy is the policy that we are logging in for.
     * @param String $reason is the reason under Policy::COOLOFF_REASON_*.
     * @return Crawler the web crawler for the page coming after cancellation occurs.
     */
    private function cancelForm($policy, $reason, $other = null)
    {
        $user = $policy->getUser();
        $this->login($user->getEmail(), 'foo');
        $crawler = self::$client->request('GET', '/purchase/cancel/'.$policy->getId());
        $form = $crawler->selectButton('cancel_form[cancel]')->form();
        $form['cancel_form[reason]'] = $reason;
        if ($other) {
            $form['cancel_form[othertxt]'] = $other;
        }
        self::$client->submit($form);
        self::$dm->refresh($policy);
        $this->verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->verifyResponse(200);
        return $crawler;
    }
}
