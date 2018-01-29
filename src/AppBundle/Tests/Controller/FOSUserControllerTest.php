<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-net
 */
class FOSUserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function tearDown()
    {
    }

    public function testLogin()
    {
        $crawler = self::$client->request('GET', '/login');
        self::verifyResponse(200);
        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = 'patrick@so-sure.com';
        $form['_password'] = \AppBundle\DataFixtures\MongoDB\b\User\LoadUserData::DEFAULT_PASSWORD;
        self::$client->enableProfiler();
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);
        /** @var EventDataCollector $eventDataCollector */
        $eventDataCollector = self::$client->getProfile()->getCollector('events');
        $listeners = $eventDataCollector->getCalledListeners();
        // @codingStandardsIgnoreStart
        $this->assertTrue(isset($listeners['security.interactive_login.actual.AppBundle\Listener\SecurityListener::onActualSecurityInteractiveLogin']));
        // @codingStandardsIgnoreEnd

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['emailCanonical' => 'patrick@so-sure.com']);
        $now = new \DateTime();
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

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);
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

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);
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

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $updatedUser2 = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);
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

    private function resetPassword($email)
    {
        $crawler = self::$client->request('GET', '/resetting/request');
        self::verifyResponse(200);
        $form = $crawler->filter('.fos_user_resetting_request')->form();
        $form['username'] = $email;
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $userRepo = $dm->getRepository(User::class);
        $updatedUser = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);

        return [
            'password' => $updatedUser->getPassword(),
            'url' => sprintf('/resetting/reset/%s', $updatedUser->getConfirmationToken()),
            'updatedUser' => $updatedUser,
        ];
    }

    private function setPassword($url, $password, $expectedSuccess = true)
    {
        $crawler = self::$client->request('GET', $url);
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
