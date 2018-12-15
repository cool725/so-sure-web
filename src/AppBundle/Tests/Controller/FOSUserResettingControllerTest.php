<?php

namespace AppBundle\Tests\Controller;

use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use Symfony\Component\HttpKernel\DataCollector\EventDataCollector;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\FOSUserControllerTest
 */
class FOSUserResettingControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function setUp()
    {
        parent::setUp();
        self::$redis->flushdb();
    }

    public function tearDown()
    {
    }

    public function testLogin()
    {
        $crawler = self::$client->request('GET', '/login');
        self::verifyResponse(200);
        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = LoadUserData::DEFAULT_ADMIN;
        $form['_password'] = \AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD;
        self::$client->enableProfiler();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        if (!self::$client->getProfile()) {
            throw new \Exception('Profiler must be enabled');
        }
        /** @var EventDataCollector $eventDataCollector */
        $eventDataCollector = self::$client->getProfile() ?
            self::$client->getProfile()->getCollector('events') :
            null;
        if ($eventDataCollector) {
            $listeners = $eventDataCollector->getCalledListeners();
        }
        // @codingStandardsIgnoreStart
        $this->assertTrue(isset($listeners['security.interactive_login.actual.AppBundle\Listener\SecurityListener::onActualSecurityInteractiveLogin']));
        // @codingStandardsIgnoreEnd

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['emailCanonical' => LoadUserData::DEFAULT_ADMIN]);
        $now = \DateTime::createFromFormat('U', time());
        $this->assertNotNull($user->getLatestWebIdentityLog());
        $diff = $user->getLatestWebIdentityLog()->getDate()->diff($now);
        $this->assertTrue($diff->days == 0 && $diff->h == 0 && $diff->i == 0);
    }

    public function testAdminResetWithPasswordLogging()
    {
        $email = self::generateEmail('testAdminResetWithPasswordLogging', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $user->addRole('ROLE_ADMIN');
        static::$dm->flush();
        $password1 = $user->getPassword();

        $reset = $this->resetPassword($email);
        $password2 = $reset['password'];

        // try a simple password - should fail with 200 (error displayed on page)
        $this->setPassword($reset['url'], 'foo', false);

        // allowed complex password - should succeed and redirect
        $this->setPassword($reset['url'], 'foooBarr1!', true);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $updatedUser2 */
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotEquals($updatedUser2->getPassword(), $password1);
        $this->assertNotEquals($updatedUser2->getPassword(), $password2);
        $this->assertTrue(count($updatedUser2->getPreviousPasswords()) > 0);

        // 2nd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr2!', true);

        // try first password should fail
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', false);

        // 3rd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr3!', true);

        // 4th complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr4!', true);

        // 5th complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr5!', true);

        // now 1st complex password - should be re-allowed and succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', true);
    }

    public function testClaimsResetWithPasswordLogging()
    {
        $email = self::generateEmail('testClaimsResetWithPasswordLogging', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $user->addRole('ROLE_CLAIMS');
        static::$dm->flush();
        $password1 = $user->getPassword();

        $reset = $this->resetPassword($email);
        $password2 = $reset['password'];

        // try a simple password - should fail with 200 (error displayed on page)
        $this->setPassword($reset['url'], 'foo', false);

        // allowed complex password - should succeed and redirect
        $this->setPassword($reset['url'], 'foooBarr1!', true);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $updatedUser2 */
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotEquals($updatedUser2->getPassword(), $password1);
        $this->assertNotEquals($updatedUser2->getPassword(), $password2);
        $this->assertTrue(count($updatedUser2->getPreviousPasswords()) > 0);

        // 2nd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr2!', true);

        // try first password should fail
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', false);

        // 3rd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr3!', true);

        // 4th complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr4!', true);

        // 5th complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr5!', true);

        // now 1st complex password - should be re-allowed and succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', true);
    }

    public function testUserResetWithPasswordLogging()
    {
        $email = self::generateEmail('testUserResetWithPasswordLogging', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        static::$dm->flush();
        $password1 = $user->getPassword();

        $reset = $this->resetPassword($email);
        $password2 = $reset['password'];

        // try a simple password - should fail with 200 (error displayed on page)
        $this->setPassword($reset['url'], 'foo', false);

        // allowed complex password - should succeed and redirect
        $this->setPassword($reset['url'], 'foooBarr1!', true);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $updatedUser2 */
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $this->assertNotEquals($updatedUser2->getPassword(), $password1);
        $this->assertNotEquals($updatedUser2->getPassword(), $password2);
        $this->assertTrue(count($updatedUser2->getPreviousPasswords()) > 0);

        // 2nd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr2!', true);

        // try first password should fail
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', false);

        // 3rd complex password - should succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr3!', true);

        // now 1st complex password - should be re-allowed and succeed and redirect
        sleep(1);
        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', true);
    }

    public function testPasswordResetLockedUser()
    {
        $email = self::generateEmail('testPasswordResetLockedUser', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $user->setLocked(true);
        static::$dm->flush();

        // can't login
        $this->login($email, 'foo', 'login');

        $this->resetPassword($email, false);

        // can't login
        $this->login($email, 'foooBarr1!', 'login');
    }

    public function testPasswordResetDisabledUser()
    {
        $email = self::generateEmail('testPasswordResetDisabledUser', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $user->setEnabled(false);
        static::$dm->flush();

        // can't login
        $this->login($email, 'foo', 'login');

        $reset = $this->resetPassword($email);

        // cannot change password
        $this->setPassword($reset['url'], 'foooBarr1!', false, 403);

        // can't login
        $this->login($email, 'foo', 'login');
    }

    public function testPasswordResetCredentialsExpired()
    {
        $email = self::generateEmail('testPasswordResetCredentialsExpired', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $ninetyDaysAgo = \DateTime::createFromFormat('U', time());
        $ninetyDaysAgo = $ninetyDaysAgo->sub(new \DateInterval('P90D'));
        $user->passwordChange('foo', 'bar', $ninetyDaysAgo);
        $user->addRole('ROLE_ADMIN');
        static::$dm->flush();

        // can't login
        $this->login($email, 'foo', 'login');

        $reset = $this->resetPassword($email);
        $this->setPassword($reset['url'], 'foooBarr1!', true);

        // now can login
        $this->login($email, 'foooBarr1!', 'admin');
    }

    private function resetPassword($email, $expectSuccess = true)
    {
        $crawler = self::$client->request('GET', '/resetting/request');
        self::verifyResponse(200);
        $form = $crawler->filter('.fos_user_resetting_request')->form();
        $form['username'] = $email;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::$client->followRedirects(false);
        if ($expectSuccess) {
            $this->assertEquals(
                sprintf('http://localhost/resetting/check-email?username=%s', urlencode($email)),
                self::$client->getHistory()->current()->getUri()
            );
        } else {
            $this->assertEquals(
                'http://localhost/resetting/request',
                self::$client->getHistory()->current()->getUri()
            );
        }

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if ($expectSuccess) {
            $this->assertTrue(
                mb_strlen($updatedUser->getConfirmationToken()) > 10,
                sprintf('Unable to find reset token: %s', $crawler->html())
            );
        } else {
            $this->assertTrue(
                mb_strlen($updatedUser->getConfirmationToken()) == 0,
                sprintf('Found unexpected reset token: %s', $crawler->html())
            );
        }

        return [
            'password' => $updatedUser->getPassword(),
            'url' => $expectSuccess ? sprintf('/resetting/reset/%s', $updatedUser->getConfirmationToken()) : null,
            'updatedUser' => $updatedUser,
        ];
    }

    public function testPCILoginLock()
    {
        $email = self::generateEmail('testPCILoginLock', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        $user->addRole('ROLE_ADMIN');
        static::$dm->flush();

        $this->login($email, 'foo', 'admin');

        // can't login
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');

        $this->login($email, 'foo', 'admin');

        // can't login
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');

        // locked
        $this->login($email, 'foo', 'login');
    }

    public function testPCILoginUserNoLock()
    {
        $email = self::generateEmail('testPCILoginUserNoLock', $this);
        $user = static::createUser(
            self::$userManager,
            $email,
            'foo'
        );
        static::$dm->flush();

        $this->login($email, 'foo', 'user/invalid');

        // can't login
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');

        $this->login($email, 'foo', 'user/invalid');

        // can't login
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');
        $this->login($email, 'bar', 'login');

        // not locked
        $this->login($email, 'foo', 'user/invalid');
    }

    private function setPassword($url, $password, $expectedSuccess = true, $initialRequestCode = 200)
    {
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse($initialRequestCode, null, $crawler);
        if ($initialRequestCode > 200) {
            return;
        }

        $form = $crawler->filter('.fos_user_resetting_reset')->form();
        $form['fos_user_resetting_form[plainPassword][first]'] = $password;
        $form['fos_user_resetting_form[plainPassword][second]'] = $password;
        $crawler = self::$client->submit($form);
        if ($expectedSuccess) {
            self::verifyResponse(302, null, $crawler);
        } else {
            self::verifyResponse(200, null, $crawler);
        }
    }
}
