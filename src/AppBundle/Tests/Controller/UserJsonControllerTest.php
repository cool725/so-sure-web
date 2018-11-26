<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Charge;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Tests\UserClassTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Client;
use AppBundle\Controller\UserJsonController;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserJsonControllerTest
 */
class UserJsonControllerTest extends BaseControllerTest
{
    use UserClassTrait;

    private static $userRepository;
    private static $csrfService;

    public function setUp()
    {
        static::$client = static::createClient();
        static::$container = static::$client->getContainer();
        if (static::$container) {
            /** @var DocumentManager static::$dm */
            $dm = static::$container->get('doctrine_mongodb.odm.default_document_manager');
            static::$dm = $dm;
            static::$userRepository = static::$dm->getRepository(User::class);
            static::$csrfService = static::$container->get("security.csrf.token_manager");
        } else {
            throw new \Exception("Container could not get got.");
        }
    }

    /**
     * Tests the invite email action for all error messages and success and makes sure it sends the emails and does
     * CSRF protection right etc. All of the things that are done to modify the user are undone at the end so the user
     * remains the same after the execution of the function.
     * @param int     $status    is the status we desire.
     * @param string  $code      is the ApiErrorCode we want to get or null if none.
     * @param boolean $login     is whether or not we should make the session be logged in.
     * @param string  $email     is the email in the request.
     * @param string  $csrf      is the csrf token to put in the request.
     * @param string  $addRole   gives a role to add to the user.
     * @param boolean $addPolicy tells whether to add a new valid policy to the user.
     *
     * @dataProvider inviteEmailActionProvider
     */
    public function testInviteEmailAction(
        $status,
        $code = null,
        $login = true,
        $email = null,
        $csrf = null,
        $addRole = null,
        $addPolicy = false
    ) {
        // setting up for test.
        $data = [];
        if ($csrf == "csrf") {
            $data["csrf"] = static::$csrfService->getToken("invite-email")->getValue();
        } elseif ($csrf) {
            $data["csrf"] = $csrf;
        }
        $user = null;
        $policy = null;
        if ($login) {
            $user = static::loginUser();
            if ($addRole) {
                $user->addRole($addRole);
            }
            if ($addPolicy) {
                $policy = $this->initPolicy($user, static::$dm, static::getRandomPhone(static::$dm));
                $user->addPolicy($policy);
                $policy->setPolicyNumber(static::getRandomPolicyNumber($addPolicy));
                $policy->setStatus(Policy::STATUS_ACTIVE);
            }
        }
        if ($email == "email" && $user) {
            $data["email"] = $user->getEmail();
        } elseif ($email) {
            $data["email"] = $email;
        }
        // Actual test.
        static::$client->request("POST", "/user/json/invite/email", $data);
        $this->verifyResponse($status, $code);
        // deleting all the stuff so the next test works.
        if ($user && $addRole) {
            $user->removeRole($addRole);
        }
        if ($policy) {
            $policy->setStatus(Policy::STATUS_CANCELLED);
        }
    }

    /**
     * Provides data for the invite email action test.
     */
    public function inviteEmailActionProvider()
    {
        return [
            [302, null, false],
            [422, ApiErrorCode::ERROR_MISSING_PARAM, true],
            [422, ApiErrorCode::ERROR_MISSING_PARAM, true, "dalygbarron@gmail.com"],
            [422, ApiErrorCode::ERROR_MISSING_PARAM, true, "dalygbarron@gmail.com", "junkCsrf"],
            [422, ApiErrorCode::ERROR_MISSING_PARAM, true, "dalygbarron@gmail.com", "csrf"],
            [422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION, true, "dalygbarron@gmail.com", "csrf", null, "JUNK"],
            [422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION, true, "email", "csrf", null, "TEST"],
            [200, ApiErrorCode::SUCCESS, true, "successfulinvite@gmail.com", "csrf", null, "TEST"],
            [422, ApiErrorCode::ERROR_INVITATION_DUPLICATE, true, "successfulinvite@gmail.com", "csrf", null, "TEST"]
        ];
    }

    /**
     * Tests the app sms action to make sure it emits desired values when given a given input and state.
     * @param int     $status  is the expected response status.
     * @param string  $code    is the apierrorcode that we want to get or null for none.
     * @param boolean $login   determines whether to login a user before making the request.
     * @param string  $number  is an optional mobile phone number to set on the user if there is one.
     * @param boolean $usedApp determines whether to set the user as having used the app if there is one.
     * @param boolean $presend determines whether to send a text message from the user prior to testing.
     *
     * @dataProvider appSmsActionProvider
     */
    public function testAppSmsAction(
        $status,
        $code = null,
        $login = false,
        $number = null,
        $usedApp = false,
        $presend = false
    ) {
        $user = null;
        $charge = null;
        if ($login) {
            $user = static::loginUser();
            if ($number) {
                $user->setMobileNumber($number);
            }
            if ($usedApp) {
                $user->setFirstLoginInApp(new \DateTime());
            }
            if ($presend) {
                $charge = new Charge();
                $charge->setType(Charge::TYPE_SMS_DOWNLOAD);
                $charge->setUser($user);
                static::$dm->persist($charge);
                static::$dm->flush();
            }
        }
        static::$client->request("POST", "/user/json/app/sms");
        $this->verifyResponse($status, $code);
        if ($user) {
            if ($number) {
                $user->setMobileNumber(null);
            }
            if ($usedApp) {
                $user->setFirstLoginInApp(null);
            }
            if ($charge) {
                static::$dm->remove($charge);
                static::$dm->flush();
            }
        }
    }

    /**
     * Provides data for app sms action test.
     */
    public function appSmsActionProvider()
    {
        return [
            [302],
            [422, ApiErrorCode::ERROR_MISSING_PARAM, true],
            [422, ApiErrorCode::ERROR_ACCESS_DENIED, true, "07123456789", true],
            [200, null, true, "07123456789"],
            [422, ApiErrorCode::ERROR_ACCESS_DENIED, true, "07123456789", false, true]
        ];

    }

    /**
     * Tests the policy terms action for all of it's cases.
     */
    public function testPolicyTermsAction()
    {
        // no user.
        static::$client->request("GET", "/user/json/policyterms");
        $this->verifyResponse(302);
        // file not yet generated.
        $user = static::loginUser();
        static::$client->request("GET", "/user/json/policyterms");
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
        $this->assertEquals("File not yet generated.", $data["description"]);
        // file ready.
        $policy = $user->getLatestPolicy();
        static::$container->get("app.policy")->generatePolicyTerms($policy);
        static::$dm->flush();
        static::$client->request("GET", "/user/json/policyterms");
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
        $this->assertContains("file", $data);
    }

    /**
     * Log the current session in.
     * NOTE: BaseController::login does not seem to work with CSRF service while in functional tests.
     * @return User the user that we just logged in with.
     */
    private static function loginUser()
    {
        $user = self::$userRepository->findBy([])[0];
        $session = self::$container->get("session");
        $firewall = "main";
        $token = new UsernamePasswordToken($user->getEmail(), "w3ares0sure!", $firewall, []);
        $session->set("_security_".$firewall, serialize($token));
        $session->save();
        $cookie = new Cookie($session->getName(), $session->getId());
        self::$client->getCookieJar()->set($cookie);
        return $user;
    }
}
