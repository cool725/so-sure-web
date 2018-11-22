<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Tests\UserClassTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Client;
use AppBundle\Controller\UserJsonController;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserJsonControllerTest
 */
class UserJsonControllerTest extends WebTestCase
{
    use UserClassTrait;

    private static $client;
    private static $container;
    private static $userRepository;
    private static $csrfService;

    public function setUp()
    {
        static::$client = static::createClient();
        static::$container = static::$client->getContainer();
        static::$dm = static::$container->get('doctrine_mongodb.odm.default_document_manager');
        static::$userRepository = static::$dm->getRepository(User::class);
        static::$csrfService = static::$container->get("security.csrf.token_manager");
    }

    /**
     * Tests the invite email action for all error messages and success and makes sure it sends the emails and does
     * CSRF protection right etc. All of the things that are done to modify the user are undone at the end so the user
     * remains the same after the execution of the function.
     * @param int       $status    is the status we desire.
     * @param string    $content   is the content we desire.
     * @param boolean   $login     is whether or not we should make the session be logged in.
     * @param string    $email     is the email in the request.
     * @param string    $csrf      is the csrf token to put in the request.
     * @param string    $addRole   gives a role to add to the user.
     * @param boolean   $addPolicy tells whether to add a new valid policy to the user.
     *
     * @dataProvider inviteEmailActionProvider
     */
    public function testInviteEmailAction(
        $status,
        $content = null,
        $login = true,
        $email = null,
        $csrf = null,
        $addRole = null,
        $addPolicy = false
    ) {
        $data = [];
        if ($email) {
            $data["email"] = $email;
        }
        if ($csrf == "csrf") {
            $data["csrf"] = static::$csrfService->getToken("invite-email")->getValue();
        } elseif($csrf) {
            $data["csrf"] = $csrf;
        }
        $user = null;
        $policy = null;
        if ($login) {
            $user = static::login();
            if ($addRole) {
                $user->addRole($addRole);
            }
            if ($addPolicy) {
                $policy = $this->initPolicy($user, static::$dm);
                $policy->setStatus(Policy::STATUS_ACTIVE);
                $user->addPolicy($policy, "07123456789", null, false, true);
                $policy->setPolicyNumber("TEST");
                static::$dm->flush();
            }
        }
        static::$client->request("POST", "/user/json/invite/email", $data);
        $this->assertEquals($status, static::$client->getResponse()->getStatusCode());
        if ($content) {
            $this->assertEquals($content, static::$client->getResponse()->getContent());
        }
        if ($user && $addRole) {
            $user->removeRole(0);
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
            [400, "{\"message\":\"no-email\"}", true],
            [400, "{\"message\":\"invalid-csrf\"}", true, "dalygbarron@gmail.com"],
            [400, "{\"message\":\"invalid-csrf\"}", true, "dalygbarron@gmail.com", "junkCsrf"],
            [400, "{\"message\":\"no-policy\"}", true, "dalygbarron@gmail.com", "csrf"],
            [400, "{\"message\":\"invalid-policy\"}", true, "dalygbarron@gmail.com", "csrf", null, true],
            [400, "{\"message\":\"access-denied\"}", true, "dalygbarron@gmail.com", "csrf", "junkRole", true],
            [400, "{\"message\":\"self-invite\"}", true, "daly@so-sure.com", "csrf", null, true],
            [200, "{\"message\":\"\"}", true, "dalygbarron@gmail.com", "csrf", null, true],
            [400, "{\"message\":\"duplicate\"}", true, "dalygbarron@gmail.com", "csrf", null, true]
        ];
    }

    /**
     * Tests the app sms action to make sure it emits desired values when given a given input and state.
     * @param int       $status    is the expected response status.
     * @param string    $content   is the expected response content.
     * @param boolean   $login     determines whether to login a user before making the request.
     * @param string    $number    is an optional mobile phone number to set on the user if there is one.
     * @param boolean   $usedApp   determines whether to set the user as having used the app if there is one.
     * @param boolean   $presend   determines whether to send a text message from the user prior to testing.
     *
     * @dataProvider appSmsActionProvider
     */
    public function testAppSmsAction(
        $status,
        $content = null,
        $login = false,
        $number = null,
        $usedApp = false,
        $presend = false
    ) {
        $user = null;
        if ($login) {
            $user = static::login();
            if ($number) {
                $user->setMobileNumber($number);
            }
            if ($usedApp) {
                $user->setFirstLoginInApp(new \DateTime());
            }
            if ($presend) {
                // pre send the junk
            }
        }
        static::$client->request("POST", "/user/json/app/sms");
        $this->assertEquals($status, static::$client->getResponse()->getStatusCode());
        if ($content) {
            $this->assertEquals($content, static::$client->getResponse()->getContent());
        }
        if ($login) {
            if ($number) {
                $user->setMobileNumber(null);
            }
            if ($usedApp) {
                $user->setFirstLoginInApp(null);
            }
            if ($presend) {
                // unsend the junk
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
            [400, "{\"message\":\"no-number\"}", true],
            [400, "{\"message\":\"has-app\"}", true, "07123456789"],
            [400, "{\"message\":\"has-app\"}", true, "junkNumber"],
            [200, null, true, "07123456789"],
            [400, "{\"message\":\"already-sent\"}", true, "07123456789"]
        ];

    }

    /**
     * Tests the policy terms action for all of it's cases.
     */
    public function testPolicyTermsAction()
    {
        // no user.

        // no policy.

        // file not yet generated.

        // file ready.
    }

    /**
     * Log the current session in.
     * @return User the user that we just logged in with.
     */
    private static function login()
    {
        self::$client->restart();
        $session = self::$container->get("session");
        $firewall = "main";
        $token = new UsernamePasswordToken("daly@so-sure.com", "w3ares0sure!", $firewall, []);
        $session->set("_security_".$firewall, serialize($token));
        $session->save();
        $cookie = new Cookie($session->getName(), $session->getId());
        self::$client->getCookieJar()->set($cookie);
        $user = self::$userRepository->findBy(["username" => $token->getUser()])[0];
        return $user;
    }
}
