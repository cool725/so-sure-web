<?php
namespace App\Tests\Normalizer;

use App\Normalizer\UserPolicySummary;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Tests\Controller\BaseControllerTest;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\UserBundle\Model\UserManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserPolicySummaryTest extends BaseControllerTest #WebTestCase
{
    use \AppBundle\Tests\UserClassTrait;

    static private $userRepo;
    static private $policyRepo;

    /**
     * A test user has one policy.
     */
    public function testOnePolicySummary()
    {
        $email = $this->generateUserWithPolicy();
        $user = self::$userManager->findUserByEmail($email);

        $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');
        $summary = $userPolicySummary->shortPolicySummary($user);

        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary);

        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('policies', $summary);
        $this->assertCount(1, $summary['policies']);

        $policy = current($summary['policies']);
        $this->assertArrayHasKey('policyNumber', $policy);
        $this->assertArrayHasKey('endDate', $policy);
        $this->assertArrayHasKey('phoneName', $policy);
        $this->assertArrayHasKey('connections', $policy);
        $this->assertArrayHasKey('rewardPot', $policy);
        $this->assertArrayHasKey('rewardPotCurrency', $policy);

        ## Not yet adding connections, or adding to the rewardPot
        #$this->assertSame(1, $policy['connections']);
        #$this->assertSame(10, $policy['rewardPot']);

        $this->assertSame('GBP', $policy['rewardPotCurrency']);
    }

    /**
     * Make a user, with a policy
     *
     * @see \AppBundle\Tests\Controller\UserControllerTest::testUserInvite();
     */
    public function generateUserWithPolicy(): string
    {
        $email = self::generateEmail('testUserInvite-inviter'.microtime(true), $this);
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

        return $email;
    }

    /**
     * @param $email
     * @param $password
     * @param $inviteeEmail
     */
    private function acceptInvitationToAddToRewardPot($email, $password, $inviteeEmail)
    {
        $this->login($email, $password, 'user/');

        $crawler = self::$client->request('GET', '/user/');
        self::verifyResponse(200);
        $form = $crawler->selectButton('email[submit]')->form();
        $form['email[email]'] = $inviteeEmail;

        self::$client->submit($form);

        $this->login($inviteeEmail, $password, 'user/');
        $crawler = self::$client->request('GET', '/user/');
        $form = $crawler->selectButton('Accept')->form();
        self::$client->submit($form);

        $crawler = self::$client->request('GET', '/user/');

        #$this->validateRewardPot($crawler, 10);
        $this->assertEquals(
            10,
            $crawler->filterXPath('//div[@id="reward-pot-chart"]')->attr('data-pot-value')
        );

        unset($crawler);
    }
}
