<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\BrowserKit\Cookie;
use AppBundle\Controller\UserJsonController;


/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\UserJsonControllerTest
 */
class UserJsonControllerTest extends WebTestCase
{

    private $client = null;
    private $user = null;

    public static function setUpBeforeClass() {}

    /**
     * Tests the invite email action for all error messages and success and makes sure it sends the emails and does
     * CSRF protection right etc. All of the things that are done to modify the user are undone at the end so the user
     * remains the same after the execution of the function.
     * @param Client    $client    is the http client to use for testing.
     * @param Container $container is the container thing.
     * @param int       $status    is the status we desire.
     * @param string    $content   is the content we desire.
     * @param boolean   $login     is whether or not we should make the session be logged in.
     * @param string    $email     is the email in the request.
     * @param string    $csrf      is the csrf token to put in the request.
     * @param string    $addRole   gives a role to add to the user.
     * @param boolean   $addPolicy tells whether to add a new valid policy to the user.
     * @dataProvider inviteEmailActionProvider
     */
    public function testInviteEmailAction(
        $client,
        $container,
        $status,
        $content = null,
        $login = true,
        $email = null,
        $csrf = null,
        $addRole = null,
        $addPolicy = false
    ) {
        $user = null;
        $policy = null;
        if ($login) {
            $user = $this->login($client);
        }
        $data = [];
        if ($email) {
            $data["email"] = $email;
        }
        if ($csrf) {
            $data["csrf"] = $csrf;
        }
        if ($addRole && $user) {
            $user->addRole($addRole);
        }
        if ($addPolicy && $user) {
            // create policy hell yeah.
        }
        $client->request("POST", "/user/json/invite/email");
        $this->assertEquals($status, $client->getResponse()->getStatusCode());
        if ($content) {
            $this->assertEquals($content, $client->getResponse()->getContent());
        }
        if ($addRole && $user) {
            $user->removeRole(0);
        }
        if ($user && $policy) {
            $user->removePolicy($policy);
        }
        if ($user) {
            $client->getContainer()->get("session")->restart();
        }
    }

    /**
     * Provides data for the invite email action test.
     */
    public function inviteEmailActionProvider()
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $csrf = $client->getContainer()->get("security.csrf.token_manager")->getToken("invite-email");
        return [
            [$client, $container, 302, null, false],
            [$client, $container, 400, "{\"message\":\"no-email\"}"],
            [$client, $container, 400, "{\"message\":\"invalid-csrf\"}", true, "dalygbarron@gmail.com"],
            [$client, $container, 400, "{\"message\":\"invalid-csrf\"}", true, "dalygbarron@gmail.com", "junkCsrf"],
            [$client, $container, 400, "{\"message\":\"access-denied\"}", true, "dalygbarron@gmail.com", $csrf, "junkRole"],
            [$client, $container, 400, "{\"message\":\"no-policy\"}", true, "dalygbarron@gmail.com", $csrf],
            [$client, $container, 400, "{\"message\":\"self-invite\"}", true, "daly@so-sure.com", $csrf, null, true],
            [$client, $container, 200, "{\"message\":\"\"}", true, "dalygbarron@gmail.com", $csrf, null, true],
            [$client, $container, 400, "{\"message\":\"duplicate\"}", true, "dalygbarron@gmail.com", $csrf, null, true]
        ];
    }

    /**
     * Tests the app sms action to make sure it emits the right error messages when appropriate and sends the smses.
     */
    public function testAppSmsAction()
    {
        // un

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
     * @param Client    $client    is the http client that we are logging in with.
     * @param Container $container is the container thingo.
     * @return User the user that we just logged in with.
     */
    private function login($client, $container)
    {
        $session = $client->getContainer()->get("session");
        $firewall = "main";
        $token = new UsernamePasswordToken("daly@so-sure.com", "w3ares0sure!", $firewall, []);
        $session->set("_security_".$firewall, serialize($token));
        $session->save();
        $cookie = new Cookie($session->getName(), $session->getId());
        $client->getCookieJar()->set($cookie);
        return $token->getUser();
    }
}
