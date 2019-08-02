<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Classes\Salva;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Feature;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\FeatureService;
use AppBundle\Tests\Service\CheckoutServiceTest;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserControllerTest
 */
class UserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;
    use DateTrait;

    protected static $container;
    protected static $dm;

    /** @var FeatureService */
    protected static $featureService;

    /** @var CheckoutService */
    protected static $checkoutService;

    /** @var PolicyService $policyService */
    protected static $policyService;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        // set up kernel
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();

        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$container->get('fos_user.user_manager');


        /** @var FeatureService $featureService */
        $featureService = self::$container->get('app.feature');
        self::$featureService = $featureService;

        /** @var  CheckoutService $checkoutService */
        $checkoutService = self::$container->get('app.checkout');
        self::$checkoutService = $checkoutService;

        /** @var PolicyService $policyService */
        $policyService = self::$container->get('app.policy');
        self::$policyService = $policyService;
    }

    public function setUp()
    {
        parent::setUp();
        self::$redis->flushdb();

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, false);
        $this->assertFalse(self::$featureService->isEnabled(Feature::FEATURE_CHECKOUT));
    }

    public function tearDown()
    {
    }

    /**
     * @group general
     */
    public function testUserOk()
    {
        $email = self::generateEmail('testUserOk', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);

        $crawler = self::$client->request('GET', '/user');

        $this->validateBonus($crawler, 14, 14);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    /**
     * @group general
     */
    public function testUserOk2ndCliff()
    {
        $email = self::generateEmail('testUserOk2ndCliff', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = \DateTime::createFromFormat('U', time());
        $cliffDate = $cliffDate->sub(new \DateInterval('P14D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT2S'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user');

        $this->validateBonus($crawler, 46, 46);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }


    /**
     * @group general
     */
    public function testUserOkFinal()
    {
        $email = self::generateEmail('testUserOkFinal', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = \DateTime::createFromFormat('U', time());
        $cliffDate = $cliffDate->sub(new \DateInterval('P60D'));
        $cliffDate = $cliffDate->sub(new \DateInterval('PT1H'));
        $policy = self::initPolicy($user, self::$dm, $phone, $cliffDate, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user');

        $this->validateBonus($crawler, [304, 305, 306], [304, 305, 306]);
        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, true);
    }

    /**
     * @group claim
     */
    public function testUserClaimed()
    {
        $email = self::generateEmail('testUserClaimed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $cliffDate = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        // print_r($policy->getCurrentConnectionValues());
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setNumber(rand(1, 999999));
        $claimsService = self::$container->get('app.claims');
        $claimsService->addClaim($policy, $claim);

        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user');

        $this->validateRewardPot($crawler, 0);
        $this->validateInviteAllowed($crawler, false);
    }

    /**
     * @group general
     */
    public function testUserInvite()
    {
        $email = self::generateEmail('testUserInvite-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserInvite-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user');
        $this->validateInviteAllowed($crawler, true);

        $csrf = $crawler->filterXPath('//input[@id="email-csrf"]')->attr('value');
        static::$client->request("POST", "/user/json/invite/email", [
            'email' => $inviteeEmail,
            'csrf' => $csrf,

        ]);
        self::verifyResponse(200);

        $this->login($inviteeEmail, $password, 'user');
        $crawler = self::$client->request('GET', '/user');
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user');
        $this->validateRewardPot($crawler, 10);
    }

    /**
     * @group general
     */
    public function testUserInviteOptOut()
    {
        $email = self::generateEmail('testUserInviteOptOut', $this);
        $inviteeEmail = 'foo@so-sure.com';
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', '/user');
        self::verifyResponse(200);

        $this->validateInviteAllowed($crawler, true);

        $csrf = $crawler->filterXPath('//input[@id="email-csrf"]')->attr('value');
        static::$client->request("POST", "/user/json/invite/email", [
            'email' => $inviteeEmail,
            'csrf' => $csrf,

        ]);
        self::verifyResponse(422, 1);
    }

    /**
     * @group claim
     */
    public function testUserInviteClaimed()
    {
        $email = self::generateEmail('testUserInviteClaimed', $this);
        $inviteeEmail = self::generateEmail('testUserInviteClaimed-claimed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $inviteePolicy->addClaim($claim);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->hasMonetaryClaimed());

        $this->login($email, $password, 'user');

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policy->getId()));
        self::verifyResponse(200);

        $this->validateInviteAllowed($crawler, true);

        $csrf = $crawler->filterXPath('//input[@id="email-csrf"]')->attr('value');
        static::$client->request("POST", "/user/json/invite/email", [
            'email' => $inviteeEmail,
            'csrf' => $csrf,

        ]);
        self::verifyResponse(200);

        $this->logout();

        $this->login($inviteeEmail, $password, 'user');

        $crawler = self::$client->request('GET', sprintf('/user/%s', $inviteePolicy->getId()));
        self::verifyResponse(200);
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        //print $crawler->html();
        $this->expectFlashWarning($crawler, 'have a claim');
    }

    /**
     * @group general
     */
    public function testUserSCode()
    {
        $email = self::generateEmail('testUserSCode-inviter', $this);
        $inviteeEmail = self::generateEmail('testUserSCode-invitee', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $invitee = self::createUser(
            self::$userManager,
            $inviteeEmail,
            $password,
            $phone,
            self::$dm
        );
        $inviteePolicy = self::initPolicy($invitee, self::$dm, $phone, null, true, true);
        $inviteePolicy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->assertTrue($inviteePolicy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', sprintf('/scode/%s', $inviteePolicy->getStandardSCode()->getCode()));
        self::verifyResponse(200);
        self::$client->followRedirects(false);

        $form = $crawler->selectButton('scode[submit]')->form();
        $this->assertEquals($inviteePolicy->getStandardSCode()->getCode(), $form['scode[scode]']->getValue());
        $crawler = self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user');
        $crawler = self::$client->request('GET', '/user');
        // print $crawler->html();
        $form = $crawler->selectButton('Accept')->form();
        $crawler = self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user');
        $this->validateRewardPot($crawler, 10);
    }

    /**
     * @group general
     */
    public function testUserChangeEmailDuplicate()
    {
        $email = self::generateEmail('testUserChangeEmailDuplicate', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $dupUser = self::createUser(
            self::$userManager,
            self::generateEmail('testUserChangeEmailDuplicate-dup', $this),
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200);

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = $dupUser->getEmail();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        $this->expectFlashError($crawler, 'email already exists in our system');
    }

    /**
     * @group general
     */
    public function testUserChangeEmailActual()
    {
        $email = self::generateEmail('testUserChangeEmailActual', $this);
        $password = 'fooBar123!';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        self::$client = self::createClient();

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200, null, null, '/user/contact-details');

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = self::generateEmail('testUserChangeEmailActual-new', $this);
        $crawler = self::$client->submit($form);
        self::verifyResponse(200, null, null, 'Submit email update./');
        $this->expectFlashSuccess($crawler, 'email address is updated');
    }

    /**
     * @group general
     */
    public function testUserChangeEmailInvalidEmail()
    {
        $email = self::generateEmail('testUserChangeEmailInvalidEmail', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/contact-details');
        self::verifyResponse(200);

        $form = $crawler->selectButton('user_email_form[update]')->form();
        $form['user_email_form[email]'] = 'testUserChangeEmailInvalidEmail-dup@foo';
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        //print_r($crawler->html());
    }

    /**
     * @group checkout
     */
    public function testUserPaymentDetails()
    {
        $email = self::generateEmail('testUserPaymentDetails', $this);
        $password = 'fooBar123!';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        self::$client = self::createClient();

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');

        $this->validateCheckoutForm($crawler, true);
    }

    /**
     * @group checkout
     */
    public function testUserPaymentDetailsCheckout()
    {
        $email = self::generateEmail('testUserPaymentDetailsCheckout', $this);
        $password = 'fooBar123!';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        self::$client = self::createClient();

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');

        $this->validateCheckoutForm($crawler, true);

        $cardDetails = $crawler->filter('#payment_card');
        $this->assertNotContains(CheckoutServiceTest::$CHECKOUT_TEST_CARD_LAST_FOUR, $cardDetails->html());

        $paymentForm = $crawler->filter('.payment-form')->getNode(0);
        $this->assertNotNull($paymentForm);
        $csrf = null;
        $pennies = null;
        if ($paymentForm) {
            $csrf = $paymentForm->getAttribute('data-csrf');
            $pennies = $paymentForm->getAttribute('data-value');
        }
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/update', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user/payment-details');
        $this->expectFlashSuccess($crawler, 'successfully updated');
        $cardDetails = $crawler->filter('#payment_card');
        $this->assertContains(CheckoutServiceTest::$CHECKOUT_TEST_CARD_LAST_FOUR, $cardDetails->html());
    }

    /**
     * @group checkout
     */
    public function testUserPaymentDetailsCheckoutOtherUser()
    {
        $email = self::generateEmail('testUserPaymentDetailsCheckoutOtherUser', $this);
        $email2 = self::generateEmail('testUserPaymentDetailsCheckoutOtherUser-2', $this);
        $password = 'fooBar123!';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        self::$client = self::createClient();

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $this->login($email, $password, 'user');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');

        $this->validateCheckoutForm($crawler, true);

        $cardDetails = $crawler->filter('#payment_card');
        $this->assertNotContains(CheckoutServiceTest::$CHECKOUT_TEST_CARD_LAST_FOUR, $cardDetails->html());

        $paymentForm = $crawler->filter('.payment-form')->getNode(0);
        $this->assertNotNull($paymentForm);
        $csrf = null;
        $pennies = null;
        if ($paymentForm) {
            $csrf = $paymentForm->getAttribute('data-csrf');
            $pennies = $paymentForm->getAttribute('data-value');
        }
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/update', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user/payment-details');
        $this->expectFlashSuccess($crawler, 'successfully updated');
        $cardDetails = $crawler->filter('#payment_card');
        $this->assertContains(CheckoutServiceTest::$CHECKOUT_TEST_CARD_LAST_FOUR, $cardDetails->html());

        $this->logout();
        $this->login($email2, $password);

        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/update', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(422);
    }

    private function validateInviteAllowed($crawler, $allowed)
    {
        $count = 0;
        if ($allowed) {
            $count = 1;
        }
        //print $crawler->html();
        $this->assertEquals($count, $crawler->evaluate('count(//form[@id="invite_form"])')[0]);
    }

    private function validateRewardPot($crawler, $amount)
    {
        $this->assertEquals(
            $amount,
            $crawler->filterXPath('//div[@id="reward_pot_chart"]')->attr('data-pot-value')
        );
    }

    private function validateBonus($crawler, $daysRemaining, $daysTotal)
    {
        $chart = $crawler->filterXPath('//div[@id="connection_bonus_chart"]');
        $actualRemaining = $chart->attr('data-bonus-days-remaining');
        $actualTotal = $chart->attr('data-bonus-days-total');
        if (is_array($daysRemaining)) {
            $this->assertContains($actualRemaining, $daysRemaining);
        } else {
            $this->assertEquals($daysRemaining, $actualRemaining);
        }
        if (is_array($daysTotal)) {
            $this->assertContains($actualTotal, $daysTotal);
        } else {
            $this->assertEquals($daysTotal, $actualTotal);
        }
    }

    private function validateRenewalAllowed($crawler, $exists)
    {
        $this->validateXPathCount($crawler, '//i[@class="fal fa-star-exclamation fa-fw"]', $exists);
    }

    private function validateCheckoutForm($crawler, $exists)
    {
        $this->validateXPathCount($crawler, '//form[@class="payment-form"]', $exists);
    }

    private function validateUnpaidRescheduleBacsForm($crawler, $exists)
    {
        $this->validateXPathCount($crawler, '//form[@id="reschedule-bacs-form"]', $exists);
    }

    private function validateUnpaidBacsSetupLink($crawler, $exists)
    {
        $this->validateXPathCount($crawler, '//a[@id="setup-bacs-link"]', $exists);
    }

    private function validateUnpaidBacsUpdateLink($crawler, $exists)
    {
        $this->validateXPathCount($crawler, '//a[@id="update-bacs-link"]', $exists);
    }

    private function validateXPathCount($crawler, $xpath, $exists)
    {
        $count = 0;
        if ($exists) {
            $count = 1;
        }

        $this->assertEquals($count, $crawler->evaluate(sprintf('count(%s)', $xpath))[0], $xpath);
    }

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyPaid()
    {
        $email = self::generateEmail('testUserUnpaidPolicyPaid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_PAID, $policy->getUnpaidReason());

        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Policy paid up to date', $crawler->html());

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);
    }
     */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsMandatePending()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsMandatePending', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, true);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_PENDING_INIT);
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_MANDATE_PENDING, $policy->getUnpaidReason());

        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Pending Direct Debit Setup', $crawler->html());
    }
     */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsMandateInvalid()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsMandateInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        //$oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P5D'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        //$twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P5D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, false);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_FAILURE);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo, false, 12);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_MANDATE_INVALID, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->assertFalse($policy->canBacsPaymentBeMadeInTime());
        //show_judo and webpay_action and webpay_reference and amount

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Invalid Direct Debit', $crawler->html());

        $payment = static::addBacsPayPayment($policy, $oneMonthAgo, true);
        self::$dm->flush();

        $this->assertTrue($policy->canBacsPaymentBeMadeInTime());
        $this->assertTrue($policy->hasPolicyOrUserBacsPaymentMethod());
        $this->assertFalse($policy->isPolicyPaidToDate());

        $crawler = self::$client->request('GET', '/user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, true);
        $this->assertContains('Invalid Direct Debit', $crawler->html());
    }
     */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsPaymentPending()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsPaymentPending', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, true);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        $payment->setStatus(BacsPayment::STATUS_PENDING);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_PENDING, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Payment is processing', $crawler->html());
    }
     */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsPaymentFailed()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsPaymentFailed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P5D'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P5D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, false);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo, false, 12);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        $payment->setStatus(BacsPayment::STATUS_FAILURE);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_FAILED, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Payment failed', $crawler->html());

        $newPayment = static::addBacsPayPayment($policy, $oneMonthAgo, true);
        $newPayment->setStatus(BacsPayment::STATUS_FAILURE);
        self::$dm->flush();

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        /*
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_FAILED, $updatedPolicy->getUnpaidReason());
        $this->logout();
        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, true);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Payment failed', $crawler->html());

        $form = $crawler->selectButton('form[reschedule]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('Payment is processing', $crawler->html());

        $updatedPolicy = $this->assertPolicyExists($this->getContainer(true), $policy);

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_PENDING, $updatedPolicy->getUnpaidReason());
        $scheduledPayment = $updatedPolicy->getNextScheduledPayment();
        $this->assertNotNull($scheduledPayment);
        if ($scheduledPayment) {
            $this->assertEquals(ScheduledPayment::STATUS_SCHEDULED, $scheduledPayment->getStatus());
            $this->assertNotNull($scheduledPayment->getIdentityLog());
            $this->assertEquals(IdentityLog::SDK_WEB, $scheduledPayment->getIdentityLog()->getSdk());
            $this->assertNotNull($scheduledPayment->getIdentityLog()->getIp());
        }
    }
    */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsPaymentMissing()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsPaymentMissing', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P5D'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P5D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, false);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo, false, 12);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        $payment->setStatus(BacsPayment::STATUS_SUCCESS);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_MISSING, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Payment missing', $crawler->html());

        $newPayment = static::addBacsPayPayment($policy, $oneMonthAgo, true);
        $newPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        self::$dm->flush();

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        /*
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_MISSING, $updatedPolicy->getUnpaidReason());
        $this->logout();

        $this->assertTrue($policy->canBacsPaymentBeMadeInTime());
        $this->assertTrue($policy->hasPolicyOrUserBacsPaymentMethod());
        $this->assertFalse($policy->isPolicyPaidToDate());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, true);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, true);
        $this->assertContains('Payment missing', $crawler->html());

        $form = $crawler->selectButton('form[reschedule]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        $this->assertContains('Payment is processing', $crawler->html());

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        /*
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_PENDING, $updatedPolicy->getUnpaidReason());
    }
     */

    /**
     * @group bacs
     *
    public function testUserUnpaidPolicyBacsPendingNoPaymentDetailsUpdate()
    {
        $email = self::generateEmail('testUserUnpaidPolicyBacsPendingNoPaymentDetailsUpdate', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P5D'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P5D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, false, false);
        self::setBacsPaymentMethodForPolicy($policy, BankAccount::MANDATE_SUCCESS);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo, false, 12);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        $payment = static::addBacsPayPayment($policy, $twoMonthsAgo, true);
        $payment->setStatus(BacsPayment::STATUS_SUCCESS);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_MISSING, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Payment missing', $crawler->html());

        $newPayment = static::addBacsPayPayment($policy, $oneMonthAgo, true);
        $newPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        self::$dm->flush();

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        /*
        $updatedPolicy = $repo->find($policy->getId());

        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_MISSING, $updatedPolicy->getUnpaidReason());
        $this->logout();

        $this->assertTrue($policy->canBacsPaymentBeMadeInTime());
        $this->assertTrue($policy->hasPolicyOrUserBacsPaymentMethod());
        $this->assertFalse($policy->isPolicyPaidToDate());

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, false);
        $this->validateUnpaidRescheduleBacsForm($crawler, true);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, true);
        $this->assertContains('Payment missing', $crawler->html());

        $form = $crawler->selectButton('form[reschedule]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();

        /** @var Policy $updatedPolicy */
        /*
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        /*
        $updatedPolicy = $repo->find($policy->getId());
        $this->assertEquals(Policy::UNPAID_BACS_PAYMENT_PENDING, $updatedPolicy->getUnpaidReason());

        $this->assertContains('Payment is processing', $crawler->html());

        $crawler = self::$client->request('GET', '/user/payment-details');
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/user/unpaid'));

        $updatedPolicy->setStatus(Policy::STATUS_ACTIVE);
        $dm->flush();

        $crawler = self::$client->request('GET', '/user/payment-details');
        $this->assertNotContains('Change to Credit/Debit Card', $crawler->html());
    }
     */

    /**
     * @group bacs
     * @group checkout
     *
    public function testUserUnpaidPolicyCheckoutPaymentMissingNoBacsLink()
    {
        $email = self::generateEmail('testUserUnpaidPolicyCheckoutPaymentMissingNoBacsLink', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, true, false);
        self::setPaymentMethodForPolicy($policy);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_CARD_PAYMENT_MISSING, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Unpaid Policy', $crawler->html());

        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/unpaid', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
         ]);
         $data = self::verifyResponse(200);

         $crawler = self::$client->request('GET', '/user/unpaid');
         $this->expectFlashSuccess($crawler, 'successfully completed');
         $this->assertContains('paid up to date', $crawler->html());
    }
     */

    /**
     * @group bacs
     * @group checkout
     *
    public function testUserUnpaidPolicyCheckoutPaymentFailedNoBacsLink()
    {
        $email = self::generateEmail('testUserUnpaidPolicyCheckoutPaymentFailedNoBacsLink', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, true, false);
        self::setCheckoutPaymentMethodForPolicy($policy);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        static::addCheckoutPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            $oneMonthAgo,
            CheckoutPayment::RESULT_DECLINED
        );
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_CARD_PAYMENT_FAILED, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->assertFalse($policy->canBacsPaymentBeMadeInTime());

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Unpaid Policy', $crawler->html());

        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/unpaid', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user/unpaid');
        $this->expectFlashSuccess($crawler, 'successfully completed');
        $this->assertContains('paid up to date', $crawler->html());
    }
     */

    /**
     * @group bacs
     * @group checkout
     *
    public function testUserUnpaidPolicyCheckoutCardExpiredNoBacsLink()
    {
        $email = self::generateEmail('testUserUnpaidPolicyCheckoutCardExpiredNoBacsLink', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthAgo = \DateTime::createFromFormat('U', time());
        $oneMonthAgo = $oneMonthAgo->sub(new \DateInterval('P1M'));
        $twoMonthsAgo = \DateTime::createFromFormat('U', time());
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthsAgo, true, false);

        self::setCheckoutPaymentMethodForPolicy($policy, '0101');
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $twoMonthsAgo);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_CARD_EXPIRED, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->assertFalse($policy->canBacsPaymentBeMadeInTime());

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, false);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Card Expired', $crawler->html());

        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/unpaid', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user/unpaid');
        $this->expectFlashSuccess($crawler, 'successfully completed');
        $this->assertContains('paid up to date', $crawler->html());
    }
     */

    /**
     * @group bacs
     * @group checkout
     *
    public function testUserUnpaidPolicyCheckoutCardExpiredBacsLink()
    {
        $email = self::generateEmail('testUserUnpaidPolicyCheckoutCardExpiredBacsLink', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $oneMonthTwoWeeksAgo = \DateTime::createFromFormat('U', time());
        $oneMonthTwoWeeksAgo = $oneMonthTwoWeeksAgo->sub(new \DateInterval('P40D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $oneMonthTwoWeeksAgo, true, true);
        self::setCheckoutPaymentMethodForPolicy($policy, '0101');
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();

        $this->assertEquals(Policy::UNPAID_CARD_EXPIRED, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());

        // bacs feature flag conditions
        $this->assertFalse(count($policy->getUser()->getValidPolicies(true)) > 1);
        $this->assertTrue($policy->getPremiumPlan() == Policy::PLAN_MONTHLY);
        /** @var FeatureService $featureService */
        /*
        $featureService = $this->getContainer(true)->get('app.feature');
        $this->assertTrue($featureService->isEnabled(Feature::FEATURE_BACS));

        $this->assertTrue($policy->canBacsPaymentBeMadeInTime());

        $featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $crawler = $this->login($email, $password, 'user/unpaid');

        $this->validateCheckoutForm($crawler, true);
        $this->validateUnpaidRescheduleBacsForm($crawler, false);
        $this->validateUnpaidBacsSetupLink($crawler, true);
        $this->validateUnpaidBacsUpdateLink($crawler, false);
        $this->assertContains('Card Expired', $crawler->html());

        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/unpaid', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/user/unpaid');
        $this->expectFlashSuccess($crawler, 'successfully completed');
        $this->assertContains('paid up to date', $crawler->html());
    }
     */

    /**
     * @group general
     */
    public function testUserUnpaidPolicyPaymentDetails()
    {
        $this->logout();
        $email = self::generateEmail('testUserUnpaidPolicyPaymentDetails', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, new \DateTime("-2 month"), true, true);
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid', '/user/payment-details');

        self::$client->followRedirects();
        $crawler = self::$client->request('GET', '/user/payment-details');
        self::$client->followRedirects(false);
        $this->assertEquals(
            sprintf('http://localhost/user/unpaid'),
            self::$client->getHistory()->current()->getUri()
        );
    }

    /**
     * Tests to make sure that when a user pays their policy up to date with a manual web payment, it automatically
     * cancels any rescheduled scheduled payments that were trying to take out this amount.
     * @group checkout
     */
    public function testUserUnpaidRescheduledPaymentScheduled()
    {
        $email = self::generateEmail('testUserUnpaidRescheduledPaymentScheduled', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(self::$userManager, $email, $password, $phone, self::$dm);
        $startDate = $this->addDays(new \DateTime(), -40);
        $failDate = $this->addDays(new \DateTime(), -4);
        $rescheduleDate = $this->addDays(new \DateTime(), 5);
        $scheduleDate = $this->addDays(new \DateTime(), 30);
        $policy = self::initPolicy($user, self::$dm, $phone, $startDate, true, false);
        self::setCheckoutPaymentMethodForPolicy($policy);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $startDate);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(Policy::STATUS_UNPAID);
        static::addCheckoutPayment(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            Salva::MONTHLY_TOTAL_COMMISSION,
            null,
            $failDate,
            CheckoutPayment::RESULT_DECLINED
        );
        $rescheduledPayment = new ScheduledPayment();
        $rescheduledPayment->setScheduled($rescheduleDate);
        $rescheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $rescheduledPayment->setType(ScheduledPayment::TYPE_RESCHEDULED);
        $rescheduledPayment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $policy->addScheduledPayment($rescheduledPayment);
        self::$dm->persist($rescheduledPayment);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setScheduled($scheduleDate);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $policy->addScheduledPayment($scheduledPayment);
        self::$dm->persist($scheduledPayment);
        $unrelatedPayment = new ScheduledPayment();
        $unrelatedPayment->setScheduled($scheduleDate);
        $unrelatedPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $unrelatedPayment->setType(ScheduledPayment::TYPE_RESCHEDULED);
        $unrelatedPayment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        self::$dm->persist($unrelatedPayment);
        self::$dm->flush();
        $this->assertEquals(Policy::UNPAID_CARD_PAYMENT_FAILED, $policy->getUnpaidReason());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);
        $crawler = $this->login($email, $password, 'user/unpaid');
        $this->validateCheckoutForm($crawler, true);
        $this->assertContains('Unpaid Policy', $crawler->html());
        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');
        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/unpaid', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);
        $crawler = self::$client->request('GET', '/user/unpaid');
        $crawler = self::$client->followRedirect();
        $this->expectFlashSuccess($crawler, 'successfully completed');
        self::$dm->flush();
        self::$dm->clear();
        // check that the scheduled payment has been cancelled.
        /** @var ScheduledPaymentRepository */
        $scheduledPaymentRepo = self::$dm->getRepository(ScheduledPayment::class);
        /** @var ScheduledPayment */
        $cancelledPayment = $scheduledPaymentRepo->find($rescheduledPayment->getId());
        $this->assertEquals(ScheduledPayment::STATUS_CANCELLED, $cancelledPayment->getStatus());
        $this->assertEquals(". Cancelled rescheduled payment as web payment made", $cancelledPayment->getNotes());
        /** @var ScheduledPayment */
        $scheduledPayment = $scheduledPaymentRepo->find($scheduledPayment->getId());
        $this->assertEquals(ScheduledPayment::STATUS_SCHEDULED, $scheduledPayment->getStatus());
        /** @var ScheduledPayment */
        $unrelatedPayment = $scheduledPaymentRepo->find($unrelatedPayment->getId());
        $this->assertEquals(ScheduledPayment::STATUS_SCHEDULED, $scheduledPayment->getStatus());
    }

    /**
     * @group general
     */
    public function testUserInvalidPolicy()
    {
        $email = self::generateEmail('testUserInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::$dm->flush();
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(200);
    }

    /**
     * @group checkout
     */
    public function testUserPolicyCancelledAndPaymentOwed()
    {
        $email = self::generateEmail('testUserPolicyCancelledAndPaymentOwed', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->isCancelledAndPaymentOwed());
        $crawler = $this->login($email, $password, sprintf('user/remainder/%s', $policy->getId()));
        $this->validateCheckoutForm($crawler, true);
    }

    /**
     * @group checkout
     */
    public function testUserPolicyCancelledAndPaymentOwedCheckout()
    {
        $email = self::generateEmail('testUserPolicyCancelledAndPaymentOwedCheckout', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->isCancelledAndPaymentOwed());

        self::$featureService->setEnabled(Feature::FEATURE_CHECKOUT, true);

        $remainderPath = sprintf('user/remainder/%s', $policy->getId());
        $crawler = $this->login($email, $password, $remainderPath);

        $this->validateCheckoutForm($crawler, true);
        $csrf = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-csrf');
        $pennies = $crawler->filter('.payment-form')->getNode(0)->getAttribute('data-value');

        $token = self::$checkoutService->createCardToken(
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_NUM,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_EXP,
            CheckoutServiceTest::$CHECKOUT_TEST_CARD_PIN
        );
        $url = sprintf('/purchase/checkout/%s/remainder', $policy->getId());
        $crawler = self::$client->request('POST', $url, [
            'token' => $token->getId(),
            'pennies' => $pennies,
            'csrf' => $csrf,
        ]);
        $data = self::verifyResponse(200);

        $crawler = self::$client->request('GET', '/' . $remainderPath);
        $this->expectFlashSuccess($crawler, 'successfully completed');
        $this->assertContains('fully paid', $crawler->html());
    }

    /**
     * @group general
     */
    public function testUserAccessDenied()
    {
        $emailA = self::generateEmail('testUserAccessDenied-A', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $policyA = self::initPolicy($userA, self::$dm, $phone, null, true, true);
        $policyA->setStatus(Policy::STATUS_ACTIVE);

        $emailB = self::generateEmail('testUserAccessDenied-B', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $policyB = self::initPolicy($userB, self::$dm, $phone, null, true, true);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $this->assertTrue($policyA->getUser()->hasActivePolicy());
        $this->assertTrue($policyB->getUser()->hasActivePolicy());
        $this->login($emailA, $password, 'user');

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyA->getId()));
        self::verifyResponse(200);

        $crawler = self::$client->request('GET', sprintf('/user/%s', $policyB->getId()));
        self::verifyResponse(403);
    }

    /**
     * @group renewal
     */
    public function testUserRenewSimple()
    {
        $email = self::generateEmail('testUserRenewSimple', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(self::$userManager, $email, $password, $phone, self::$dm);
        $date = new \DateTime("-350 day");
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', '/user/renew');

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedRenewalPolicy */
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    /**
     * @group renewal
     */
    public function testUserRenewCustomMonthly()
    {
        $email = self::generateEmail('testUserRenewCustomMonthly', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = \DateTime::createFromFormat('U', time());
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('renew_form[renew]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedRenewalPolicy */
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicy->getStatus());
    }

    /**
     * @group renewal
     */
    public function testUserRenewCustomMonthlyDecline()
    {
        $email = self::generateEmail('testUserRenewCustomMonthlyDecline', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $date = \DateTime::createFromFormat('U', time());
        $date = $date->sub(new \DateInterval('P350D'));
        $policy = self::initPolicy($user, self::$dm, $phone, $date, true, true, false);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policy->isRenewed());

        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicy = $this->getRenewalPolicy($policy, false, $tomorrow);
        static::$dm->persist($renewalPolicy);
        static::$dm->flush();

        $crawler = $this->login($email, $password, 'user');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policy->getId()));

        $form = $crawler->selectButton('decline_form[decline]')->form();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedRenewalPolicy */
        $updatedRenewalPolicy = $repo->find($renewalPolicy->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicy->getStatus());
        $this->assertNull($updatedRenewalPolicy->getPreviousPolicy()->getCashback());
    }

    /**
     * @group renewal
     */
    public function testUserRenewCashbackCustomMonthly()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomMonthlyA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomMonthlyB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = \DateTime::createFromFormat('U', time());
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());

        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);

        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('renew_cashback_form[renew]')->form();
        $form['renew_cashback_form[accountName]'] = 'foo bar';
        $form['renew_cashback_form[sortCode]'] = '123456';
        $form['renew_cashback_form[accountNumber]'] = '12345678';
        $form['renew_cashback_form[encodedAmount]'] =
            sprintf('%0.2f|12|0', $renewalPolicyA->getPremium()->getYearlyPremiumPrice());
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedRenewalPolicyA */
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    /**
     * @group renewal
     */
    public function testUserRenewCashbackCustomDeclined()
    {
        $emailA = self::generateEmail('testUserRenewCashbackCustomDeclinedA', $this);
        $emailB = self::generateEmail('testUserRenewCashbackCustomDeclinedB', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $userA = self::createUser(
            self::$userManager,
            $emailA,
            $password,
            $phone,
            self::$dm
        );
        $userB = self::createUser(
            self::$userManager,
            $emailB,
            $password,
            $phone,
            self::$dm
        );
        $date = \DateTime::createFromFormat('U', time());
        $date = $date->sub(new \DateInterval('P350D'));
        $policyA = self::initPolicy($userA, self::$dm, $phone, $date, true, true, false);
        $policyB = self::initPolicy($userB, self::$dm, $phone, $date, true, true, false);
        $policyA->setStatus(Policy::STATUS_ACTIVE);
        $policyB->setStatus(Policy::STATUS_ACTIVE);
        static::$dm->flush();

        $this->assertFalse($policyA->isRenewed());
        $this->assertFalse($policyB->isRenewed());

        self::connectPolicies(self::$invitationService, $policyA, $policyB, $date);
        $tomorrow = \DateTime::createFromFormat('U', time());
        $tomorrow = $tomorrow->add(new \DateInterval('P1D'));
        $renewalPolicyA = $this->getRenewalPolicy($policyA, false, $tomorrow);
        $renewalPolicyB = $this->getRenewalPolicy($policyB, false, $tomorrow);
        static::$dm->persist($renewalPolicyA);
        static::$dm->persist($renewalPolicyB);
        static::$dm->flush();

        $this->assertEquals(10, $policyA->getPotValue());
        $this->assertEquals(10, $policyB->getPotValue());

        $crawler = $this->login($emailA, $password, 'user');

        $this->validateRenewalAllowed($crawler, 1);

        $crawler = self::$client->request('GET', sprintf('/user/renew/%s/custom', $policyA->getId()));

        $form = $crawler->selectButton('cashback_form[cashback]')->form();
        $form['cashback_form[accountName]'] = 'foo bar';
        $form['cashback_form[sortCode]'] = '123456';
        $form['cashback_form[accountNumber]'] = '12345678';
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedRenewalPolicyA */
        $updatedRenewalPolicyA = $repo->find($renewalPolicyA->getId());
        $this->assertEquals(Policy::STATUS_DECLINED_RENEWAL, $updatedRenewalPolicyA->getStatus());
        $this->assertNotNull($updatedRenewalPolicyA->getPreviousPolicy()->getCashback());
    }

    /**
     * @group general
     */
    public function testUserFormRateLimit()
    {
        $this->clearRateLimit();

        $email = self::generateEmail('testUserRateLimit', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $key = "PeerJUserSecurityBundle::login_failed::ip::127.0.0.1";
        $keyUsername = sprintf("PeerJUserSecurityBundle::%s::username::%s", 'login_failed', $email);

        $this->assertFalse(self::$redis->exists($key) == 1);
        $this->login($email, 'bar', 'login');
        $this->assertTrue(self::$redis->exists($key) == 1);

        $now = \DateTime::createFromFormat('U', time());
        $now = $now->sub(new \DateInterval(('PT1S')));
        for ($i = 1; $i <= 25; $i++) {
            self::$redis->zadd($key, [serialize(array($email, $now->getTimestamp())) => $now->getTimestamp()]);
            $now = $now->sub(new \DateInterval(('PT1S')));
        }

        //$this->login($email, 'bar', 'login', null, null);

        // expect a locked account
        $this->login($email, 'bar', 'login', null, 503);
    }

    /**
     * @group general
     */
    public function testUserWelcomePage()
    {
        $email = self::generateEmail('testUserWelcomePage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        $welcomePage = sprintf('/user/welcome/%s', $policy->getId());
        // initial flag is false
        $this->login($email, $password);
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': false",
            $this->getClientResponseContent()
        );
        // set after first show to true
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            $this->getClientResponseContent()
        );
        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            "'has_visited_welcome_page': true",
            $this->getClientResponseContent()
        );
    }

    /**
     * @group general
     */
    public function testUserWelcomePageMultiPolicyShowLatest()
    {
        $email = self::generateEmail('testUserWelcomePageMultiPolicyShowLatest', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        // setting up 3 policies, middle onw being setuo is the latest by start date
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();
        $policy2 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStart(new \DateTime('2018-1-11'));
        self::$dm->flush();
        $policy3 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy3->setStatus(Policy::STATUS_ACTIVE);
        $policy3->setStart(new \DateTime('2016-1-11'));
        self::$dm->flush();

        // latest policy should be policy number 2
        $this->assertEquals($user->getLatestPolicy(), $policy2);


        //testing user welcome page without policy id
        $welcomePage = self::$router->generate('user_welcome');

        $this->login($email, $password);
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);

        //always expecting latest policy to be policy number2
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            $this->getClientResponseContent()
        );
        // initial flag is false
        $this->assertContains(
            "'has_visited_welcome_page': false",
            $this->getClientResponseContent()
        );

        // set after first show to true
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            $this->getClientResponseContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            $this->getClientResponseContent()
        );

        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertContains(
            $user->getLatestPolicy()->getId(),
            $this->getClientResponseContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            $this->getClientResponseContent()
        );

        // consistent after repeated show
        self::$client->request('GET', $welcomePage);
        self::verifyResponse(200);
        $this->assertNotContains(
            $user->getFirstPolicy()->getId(),
            $this->getClientResponseContent()
        );
        $this->assertContains(
            "'has_visited_welcome_page': true",
            $this->getClientResponseContent()
        );

    }

    /**
     * @group general
     */
    public function testUserWelcomePageNotOwnedPolicy()
    {
        $email = self::generateEmail('testUserWelcomePageNotOwnedPolicy1', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $email2 = self::generateEmail('testUserWelcomePageNotOwnedPolicy2', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );

        //visiting
        $policy = self::initPolicy($user2, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $policy2 = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $welcomePage = self::$router->generate('user_welcome_policy_id', ['id' => $policy->getId()]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $welcomePage);
        self::verifyResponse(403);
    }

    /**
     * @group general
     */
    public function testUserWelcomePageInvalidPolicy()
    {
        $email = self::generateEmail('testUserWelcomePageInvalidPolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart(new \DateTime('2017-10-11'));
        self::$dm->flush();

        $welcomePage = self::$router->generate('user_welcome_policy_id', ['id' => rand(0, 10000000)]);
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $welcomePage);
        self::verifyResponse(404);
    }

    /**
     * @group claim
     */
    public function testUserClaimWithNoActivePolicy()
    {
        $email = self::generateEmail('testUserClaimWithNoActivePolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setStart($now);
        self::$dm->flush();

        $claimPage = self::$router->generate('user_claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(404);
    }

    /**
     * @group claim
     */
    public function testUserClaimWithActivePolicyOpenedClaim()
    {
        $email = self::generateEmail('testUserClaimWithActivePolicyOpenedClaim', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setStart($now);

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        $claimPage = self::$router->generate('user_claim');
        $this->login($email, $password);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(404);
    }

    /**
     * @group claim
     */
    public function testUserClaimFnol()
    {
        $email = self::generateEmail('testUserClaimFnol', $this);
        $password = 'bingBingWahoo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);
        self::$dm->flush();
        $this->login($email, $password);
        $this->submitFnolForm($policy, $now, Claim::TYPE_DAMAGE);
    }

    /**
     * @group claim
     */
    public function testUserClaimFnolTheftPay()
    {
        $email = self::generateEmail('testUserClaimFnolTheftPay', $this);
        $password = 'banognno';
        $phone = self::getRandomPhone(self::$dm);
        $highlighted = $phone->isHighlight();
        $phone->setHighlight(true);
        $user = self::createUser(self::$userManager, $email, $password, $phone, self::$dm);
        $now = new \DateTime();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);
        self::$dm->persist($phone);
        self::$dm->flush();
        $this->login($email, $password);
        $this->submitFnolForm($policy, $now, Claim::TYPE_LOSS);
    }

    /**
     * @group claim
     */
    public function testUserClaimFnolNoAdditionalLoss()
    {
        $email = self::generateEmail('testUserClaimFnolNoAdditionalLoss', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);
        self::$dm->flush();

        $claim1 = $this->createClaim($policy, Claim::TYPE_LOSS, $now, Claim::STATUS_APPROVED);
        $claim2 = $this->createClaim($policy, Claim::TYPE_LOSS, $now, Claim::STATUS_APPROVED);

        $this->login($email, $password);
        $this->submitFnolForm($policy, $now, Claim::TYPE_LOSS, true);
    }

    /**
     * @group claim
     */
    public function testUserClaimDamage()
    {
        $email = self::generateEmail('testUserClaimDamage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_DAMAGE, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());
        $this->assertTrue($claim->needPictureOfPhone());

        $this->login($email, $password);
        $this->submitDamageForm($policy, $now, true, true, true, true);
        $this->submitDamageForm($policy, $now, true, true, true, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimDamageNoPictureOfPhone()
    {
        $email = self::generateEmail('testUserClaimDamageNoPictureOfPhone', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $claim = $this->createClaim($policy, Claim::TYPE_DAMAGE, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());
        $this->assertFalse($claim->needPictureOfPhone());

        $this->login($email, $password);

        $this->submitDamageForm($policy, $now, true, false, false, true);
        $this->submitDamageForm($policy, $now, true, false, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimDamageNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimDamageNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimDamageNoProofOfUsage-2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_DAMAGE, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());
        $this->assertFalse($claim->needPictureOfPhone());

        $this->login($email, $password);

        $this->submitDamageForm($policy, $now, false, false, false, true);
        $this->submitDamageForm($policy, $now, false, false, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimLoss()
    {
        $email = self::generateEmail('testUserClaimLoss', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_LOSS, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, true, true);
        $this->submitLossTheftForm($policy, $now, true, true, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimLossNoProofOfPurchase()
    {
        $email = self::generateEmail('testUserClaimLossNoProofOfPurchase', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $claim = $this->createClaim($policy, Claim::TYPE_LOSS, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, false, true);
        $this->submitLossTheftForm($policy, $now, true, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimLossNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimLossNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimLossNoProofOfUsage2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_LOSS, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, false, false, true);
        $this->submitLossTheftForm($policy, $now, false, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimTheft()
    {
        $email = self::generateEmail('testUserClaimTheft', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_THEFT, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, true, true);
        $this->submitLossTheftForm($policy, $now, true, true, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimTheftNoProofOfPurchase()
    {
        $email = self::generateEmail('testUserClaimTheftNoProofOfPurchase', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $claim = $this->createClaim($policy, Claim::TYPE_THEFT, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, false, true);
        $this->submitLossTheftForm($policy, $now, true, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimTheftNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimTheftNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimTheftNoProofOfUsage2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_THEFT, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, false, false, true);
        $this->submitLossTheftForm($policy, $now, false, false, false, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateTheft()
    {
        $email = self::generateEmail('testUserClaimUpdateTheft', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_THEFT, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, true, true);
        $this->submitLossTheftForm($policy, $now, true, true, false, 1);

        $this->updateLossTheftForm($policy, $now, true, true, 2);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateTheftNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimUpdateTheftNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimUpdateTheftNoProofOfUsage2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_THEFT, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, false, false, true);
        $this->submitLossTheftForm($policy, $now, false, false, false, 1);

        $this->updateLossTheftForm($policy, $now, false, false, 2);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateLoss()
    {
        $email = self::generateEmail('testUserClaimUpdateLoss', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_LOSS, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, true, true, true);
        $this->submitLossTheftForm($policy, $now, true, true, false, 1);

        $this->updateLossTheftForm($policy, $now, true, true, 2);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateLossNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimUpdateLossNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimUpdateLossNoProofOfUsage2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_LOSS, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());

        $this->login($email, $password);
        $this->submitLossTheftForm($policy, $now, false, false, true);
        $this->submitLossTheftForm($policy, $now, false, false, false, 1);

        $this->updateLossTheftForm($policy, $now, false, false, 2);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateDamage()
    {
        $email = self::generateEmail('testUserClaimUpdateDamage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setStart($now);

        $claim = $this->createClaim($policy, Claim::TYPE_DAMAGE, $now);

        $this->assertTrue($claim->needProofOfUsage());
        $this->assertTrue($claim->needProofOfPurchase());
        $this->assertTrue($claim->needPictureOfPhone());

        $this->login($email, $password);
        $this->submitDamageForm($policy, $now, true, true, true);

        $this->updateDamageForm($policy, $now, true, true, true, 1);
    }

    /**
     * @group claim
     */
    public function testUserClaimUpdateDamageNoProofOfUsage()
    {
        $email = self::generateEmail('testUserClaimUpdateDamageNoProofOfUsage', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $now = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = \DateTime::createFromFormat('U', time());
        $twoMonthAgo = $twoMonthAgo->sub(new \DateInterval('P2M'));
        $policy = self::initPolicy($user, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $email2 = self::generateEmail('testUserClaimUpdateDamageNoProofOfUsage2', $this);
        $user2 = self::createUser(
            self::$userManager,
            $email2,
            $password,
            $phone,
            self::$dm
        );
        $policy2 = self::initPolicy($user2, self::$dm, $phone, $twoMonthAgo, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$invitationService->connect($policy, $policy2);

        $claim = $this->createClaim($policy, Claim::TYPE_DAMAGE, $now);

        $this->assertFalse($claim->needProofOfUsage());
        $this->assertFalse($claim->needProofOfPurchase());
        $this->assertFalse($claim->needPictureOfPhone());

        $this->login($email, $password);
        $this->submitDamageForm($policy, $now, false, false, false);

        $this->updateDamageForm($policy, $now, false, false, false, 1);
    }

    /**
     * Tests to make sure that you can request cancellation of your policy, and that in cooloff it will automatically
     * action it.
     * @group general
     */
    public function testCancelPolicy()
    {
        $userA = $this->createUser(self::$userManager, "a@gmail.com", "foo", null, self::$dm);
        $userB = $this->createUser(self::$userManager, "b@gmail.com", "foo", null, self::$dm);
        $userC = $this->createUser(self::$userManager, "c@gmail.com", "foo", null, self::$dm);
        $phoneA = self::getRandomPhone(self::$dm);
        $phoneB = self::getRandomPhone(self::$dm);
        $phoneC = self::getRandomPhone(self::$dm);
        $a = self::initPolicy($userA, self::$dm, $phoneA, new \DateTime("-5 months"), true, true);
        $b = self::initPolicy($userB, self::$dm, $phoneB, new \DateTime("-1 weeks"), true, true);
        $c = self::initPolicy($userC, self::$dm, $phoneC, new \DateTime("-1 weeks"), true, true);
        $a->setStatus(Policy::STATUS_ACTIVE);
        $b->setStatus(Policy::STATUS_ACTIVE);
        $c->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->persist($a);
        self::$dm->persist($b);
        self::$dm->persist($c);
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
     * @param string $reason is the reason under Policy::COOLOFF_REASON_*.
     * @param string $other  is the explanation text if reason other is chosen.
     * @return Crawler the web crawler for the page coming after cancellation occurs.
     */
    private function cancelForm($policy, $reason, $other = null)
    {
        $user = $policy->getUser();
        $crawler = $this->login($user->getEmail(), 'foo');
        $crawler = self::$client->request('GET', '/user/cancel/'.$policy->getId());
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

    private function createClaim(Policy $policy, $type, \DateTime $date, $status = Claim::STATUS_FNOL)
    {
        $claim = new Claim();
        $claim->setIncidentDate($date);
        $claim->setIncidentTime('2 am');
        $claim->setLocation('camel farm');
        $claim->setTimeToReach('2 pm');
        $claim->setPhoneToReach(self::generateRandomMobile());
        $claim->setSignature('foo bar');
        $claim->setType($type);
        $claim->setNetwork(Claim::NETWORK_O2);
        $claim->setDescription('I put my camera inside the mouth of the camel and then uhh... camera attach to phone');
        $claim->setStatus($status);
        $claim->setPolicy($policy);
        $policy->addClaim($claim);
        self::$dm->flush();

        return $claim;
    }

    private function submitFnolForm(PhonePolicy $policy, \DateTime $now, $type, $expectNoAdditionalClaimAllowed = false)
    {
        $serializer = new Serializer(array(new DateTimeNormalizer()));
        $mobileNumber = self::generateRandomMobile();

        $claimPage = self::$router->generate('user_claim');
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $form = $crawler->selectButton('claim_form[submit]')->form();
        $form['claim_form[email]'] = $policy->getUser()->getEmail();
        $form['claim_form[name]'] = 'foo bar';
        $form['claim_form[phone]'] = $mobileNumber;
        $form['claim_form[when]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_form[time]'] = '02:00';
        $form['claim_form[where]'] = 'so-sure offices';
        $form['claim_form[timeToReach]'] = '2 pm';
        $form['claim_form[signature]'] = 'foo bar';
        $form['claim_form[type]'] = $type;
        $form['claim_form[network]'] = Claim::NETWORK_O2;
        // @codingStandardsIgnoreStart
        $form['claim_form[message]'] = 'bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla bla';
        $form['claim_form[policyNumber]'] = $policy->getId();
        $crawler = self::$client->submit($form);
        if ($policy->fullPremiumToBePaidForClaim($now, $type)) {
            self::verifyResponse(302);
            $crawler = self::$client->followRedirect();
            $this->assertContains('you must pay', $this->getClientResponseContent());
            return;
        }
        self::verifyResponse(200);
        if ($expectNoAdditionalClaimAllowed) {
            $this->assertNotContains(
                'data-active="claimfnol-confirm"',
                $this->getClientResponseContent()
            );
            $this->expectFlashError($crawler, 'unable to accept an additional claim');

            return;
        }

        $this->assertContains(
            'data-active="claimfnol-confirm"',
            $this->getClientResponseContent()
        );

        $form = $crawler->selectButton('claim_confirm_form[submit]')->form();
        $form['claim_confirm_form[email]'] = $policy->getUser()->getEmail();
        $form['claim_confirm_form[name]'] = 'foo bar';
        $form['claim_confirm_form[phone]'] = $mobileNumber;
        $form['claim_confirm_form[when]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'Y/m/d')
        );
        $form['claim_confirm_form[time]'] = '2 am';
        $form['claim_confirm_form[where]'] = 'so-sure offices';
        $form['claim_confirm_form[timeToReach]'] = '2 pm';
        $form['claim_confirm_form[signature]'] = 'foo bar';
        $form['claim_confirm_form[type]'] = $type;
        $form['claim_confirm_form[network]'] = Claim::NETWORK_O2;
        $form['claim_confirm_form[message]'] = 'bla bla bla bla bla bla bla bla bla bla bla bla bla bla blba bla bla';
        $form['claim_confirm_form[policyNumber]'] = $policy->getId();
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertEquals(Claim::STATUS_FNOL, $updatedClaim->getStatus());
    }

    private function getUploadFile($originalName)
    {
        $filename = sprintf(
            "%s/../src/AppBundle/Tests/Resources/Blank.JPG",
            self::$rootDir
        );
        $uploadFile = new UploadedFile(
            $filename,
            $originalName,
            'image/jpeg',
            631
        );

        return $uploadFile;
    }

    private function submitLossTheftForm(
        Policy $policy,
        \DateTime $now,
        $requireProofOfUsage,
        $requireProofOfPurchase,
        $partial = false,
        $previousRunCount = 0
    ) {
        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsage = $this->getUploadFile('proofOfUsage.jpg');
        $proofOfBarring = $this->getUploadFile('proofOfBarring.jpg');
        $proofOfPurchase = $this->getUploadFile('proofOfPurchase.jpg');

        $claimPage = self::$router->generate('claimed_theftloss_policy', ['policyId' => $policy->getId()]);
        /** @var Crawler $crawler */
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        if ($partial) {
            $form = $crawler->selectButton('claim_theftloss_form[save]')->form();
        } else {
            $form = $crawler->selectButton('claim_theftloss_form[confirm]')->form();
        }
        if ($partial) {
            $form['claim_theftloss_form[hasContacted]'] = true;
        }
        $form['claim_theftloss_form[contactedPlace]'] = 'so-sure offices';
        $form['claim_theftloss_form[blockedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportedDate]'] = $serializer->normalize(
            $now,
            null,
            array(DateTimeNormalizer::FORMAT_KEY => 'd/m/Y')
        );
        $form['claim_theftloss_form[reportType]'] = Claim::REPORT_POLICE_STATION;
        $form['claim_theftloss_form[force]'] = 'britishtransportpolice';
        $form['claim_theftloss_form[crimeReferenceNumber]'] = '1234567890';
        if ($requireProofOfUsage) {
            $this->assertTrue(isset($form['claim_theftloss_form[proofOfUsage]']));
            $form['claim_theftloss_form[proofOfUsage]']->upload($proofOfUsage);
        } else {
            $this->assertFalse(isset($form['claim_theftloss_form[proofOfUsage]']));
        }
        $form['claim_theftloss_form[proofOfBarring]']->upload($proofOfBarring);
        if ($requireProofOfPurchase) {
            $this->assertTrue(isset($form['claim_theftloss_form[proofOfPurchase]']));
            $form['claim_theftloss_form[proofOfPurchase]']->upload($proofOfPurchase);
        } else {
            $this->assertFalse(isset($form['claim_theftloss_form[proofOfPurchase]']));
        }
        $crawler = self::$client->submit($form);

        if ($partial) {
            //print $crawler->html();
            self::verifyResponse(200);

            return;
        }
        //print $crawler->html();

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            $this->getClientResponseContent()
        );

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        /** @var Claim $updatedClaim */
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertNotNull($updatedClaim);
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
        $this->assertTrue($updatedClaim->getHasContacted());
        $this->assertEquals('so-sure offices', $updatedClaim->getContactedPlace());
        $today = $this->startOfDay($now);
        $this->assertEquals($today, $updatedClaim->getBlockedDate());
        $this->assertEquals($today, $updatedClaim->getReportedDate());
        $this->assertEquals(Claim::REPORT_POLICE_STATION, $updatedClaim->getReportType());
        $this->assertEquals('britishtransportpolice', $updatedClaim->getForce());
        $this->assertEquals('1234567890', $updatedClaim->getCrimeRef());

        $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfBarringFiles()));
        if ($requireProofOfUsage) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfUsageFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfUsageFiles()));
        }
        if ($requireProofOfPurchase) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfPurchaseFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfPurchaseFiles()));
        }
    }

    private function submitDamageForm(
        Policy $policy,
        \DateTime $now,
        $requireProofOfUsage,
        $requireProofOfPurchase,
        $requirePicture,
        $partial = false,
        $previousRunCount = 0
    ) {
        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsage = $this->getUploadFile('proofOfUsage.jpg');
        $proofOfPurchase = $this->getUploadFile('proofOfPurchase.jpg');
        $damagePicture = $this->getUploadFile('damagePicture.jpg');

        $claimPage = self::$router->generate('claimed_damage_policy', ['policyId' => $policy->getId()]);
        /** @var Crawler $crawler */
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);
        if ($partial) {
            $form = $crawler->selectButton('claim_damage_form[save]')->form();
        } else {
            $form = $crawler->selectButton('claim_damage_form[confirm]')->form();
        }
        $form['claim_damage_form[typeDetails]'] = Claim::DAMAGE_BROKEN_SCREEN;
        $form['claim_damage_form[typeDetailsOther]'] = '';
        $form['claim_damage_form[monthOfPurchase]'] = 'December';
        $form['claim_damage_form[yearOfPurchase]'] = '2018';
        $form['claim_damage_form[phoneStatus]'] = Claim::PHONE_STATUS_NEW;

        if ($requireProofOfUsage) {
            $this->assertTrue(isset($form['claim_damage_form[proofOfUsage]']));
            $form['claim_damage_form[proofOfUsage]']->upload($proofOfUsage);
        } else {
            $this->assertFalse(isset($form['claim_damage_form[proofOfUsage]']));
        }
        if ($requireProofOfPurchase) {
            $this->assertTrue(isset($form['claim_damage_form[proofOfPurchase]']));
            $form['claim_damage_form[proofOfPurchase]']->upload($proofOfPurchase);
        } else {
            $this->assertFalse(isset($form['claim_damage_form[proofOfPurchase]']));
        }
        if ($requirePicture) {
            $this->assertTrue(isset($form['claim_damage_form[pictureOfPhone]']));
            $form['claim_damage_form[pictureOfPhone]']->upload($damagePicture);
        } else {
            $this->assertFalse(isset($form['claim_damage_form[pictureOfPhone]']));
        }
        $crawler = self::$client->submit($form);

        if ($partial) {
            //print $crawler->html();
            self::verifyResponse(200);

            return;
        }
        //print $crawler->html();

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->assertContains(
            "Thank you for submitting your claim",
            $this->getClientResponseContent()
        );

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        /** @var Claim $updatedClaim */
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertNotNull($updatedClaim);
        $this->assertEquals(Claim::DAMAGE_BROKEN_SCREEN, $updatedClaim->getTypeDetails());

        if ($requireProofOfUsage) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfUsageFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfUsageFiles()));
        }
        if ($requireProofOfPurchase) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfPurchaseFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfPurchaseFiles()));
        }
        if ($requirePicture) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getDamagePictureFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getDamagePictureFiles()));
        }
    }

    private function updateLossTheftForm(
        Policy $policy,
        \DateTime $now,
        $requireProofOfUsage,
        $requireProofOfPurchase,
        $previousRunCount = 0
    ) {
        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsage = $this->getUploadFile('proofOfUsage.jpg');
        $proofOfBarring = $this->getUploadFile('proofOfBarring.jpg');
        $proofOfPurchase = $this->getUploadFile('proofOfPurchase.jpg');

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $form = $crawler->selectButton('claim_update_form[confirm]')->form();

        if ($requireProofOfUsage) {
            $this->assertTrue(isset($form['claim_update_form[proofOfUsage]']));
            $form['claim_update_form[proofOfUsage]']->upload($proofOfUsage);
        } else {
            $this->assertFalse(isset($form['claim_update_form[proofOfUsage]']));
        }
        $form['claim_update_form[proofOfBarring]']->upload($proofOfBarring);
        if ($requireProofOfPurchase) {
            $this->assertTrue(isset($form['claim_update_form[proofOfPurchase]']));
            $form['claim_update_form[proofOfPurchase]']->upload($proofOfPurchase);
        } else {
            $this->assertFalse(isset($form['claim_update_form[proofOfPurchase]']));
        }
        $this->assertFalse(isset($form['claim_update_form[pictureOfPhone]']));
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->expectFlashSuccess($crawler, 'Your claim has been updated');

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        /** @var Claim $updatedClaim */
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertNotNull($updatedClaim);
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
        $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfBarringFiles()));
        if ($requireProofOfUsage) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfUsageFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfUsageFiles()));
        }
        if ($requireProofOfPurchase) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfPurchaseFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfPurchaseFiles()));
        }
    }

    private function updateDamageForm(
        Policy $policy,
        \DateTime $now,
        $requireProofOfUsage,
        $requireProofOfPurchase,
        $requirePicture,
        $previousRunCount = 0
    ) {
        $serializer = new Serializer(array(new DateTimeNormalizer()));

        $proofOfUsage = $this->getUploadFile('proofOfUsage.jpg');
        $proofOfPurchase = $this->getUploadFile('proofOfPurchase.jpg');
        $damagePicture = $this->getUploadFile('damagePicture.jpg');

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $form = $crawler->selectButton('claim_update_form[confirm]')->form();

        if ($requireProofOfUsage) {
            $this->assertTrue(isset($form['claim_update_form[proofOfUsage]']));
            $form['claim_update_form[proofOfUsage]']->upload($proofOfUsage);
        } else {
            $this->assertFalse(isset($form['claim_update_form[proofOfUsage]']));
        }
        //print $crawler->html();
        if ($requireProofOfPurchase) {
            $this->assertTrue(isset($form['claim_update_form[proofOfPurchase]']));
            $form['claim_update_form[proofOfPurchase]']->upload($proofOfPurchase);
        } else {
            $this->assertFalse(isset($form['claim_update_form[proofOfPurchase]']));
        }
        $this->assertFalse(isset($form['claim_update_form[proofOfBarring]']));
        if ($requirePicture) {
            $this->assertTrue(isset($form['claim_update_form[pictureOfPhone]']));
            $form['claim_update_form[pictureOfPhone]']->upload($damagePicture);
        } else {
            $this->assertFalse(isset($form['claim_update_form[pictureOfPhone]']));
        }
        $crawler = self::$client->submit($form);

        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect(sprintf('/user/claim/%s', $policy->getId())));

        $claimPage = self::$router->generate('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        $crawler = self::$client->request('GET', $claimPage);
        self::verifyResponse(200);

        $this->expectFlashSuccess($crawler, 'Your claim has been updated');

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policy->getId());
        /** @var Claim $updatedClaim */
        $updatedClaim = $updatedPolicy->getLatestClaim();
        $this->assertNotNull($updatedClaim);
        $this->assertEquals(Claim::STATUS_SUBMITTED, $updatedClaim->getStatus());
        $this->assertEquals(0, count($updatedClaim->getProofOfBarringFiles()));
        if ($requireProofOfUsage) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfUsageFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfUsageFiles()));
        }
        if ($requireProofOfPurchase) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getProofOfPurchaseFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getProofOfPurchaseFiles()));
        }
        if ($requirePicture) {
            $this->assertEquals(1 + $previousRunCount, count($updatedClaim->getDamagePictureFiles()));
        } else {
            $this->assertEquals(0, count($updatedClaim->getDamagePictureFiles()));
        }
    }
}
