<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\MultiPay;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Listener\UserListener;
use AppBundle\Service\RateLimitService;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @group functional-net
 */
class ApiAuthControllerUserEmailTest extends BaseApiControllerTest
{
    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';
    const LOSTSTOLEN_IMEI = '351451208401216';
    const MISMATCH_SERIALNUMBER = '111111';

    protected static $testUser;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
    }

    public function setUp()
    {
    }

    public function testUpdateUserChangeEmail()
    {
        $this->expectUserChangeEvent();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserChangeEmail', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'email' => self::generateEmail('testUpdateUserChangeEmail-updated', $this),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals(
            mb_strtolower(self::generateEmail('testUpdateUserChangeEmail-updated', $this)),
            $result['email']
        );
        /** @var EventDataCollector $eventDataCollector */
        //$eventDataCollector = self::$client->getProfile()->getCollector('events');
       // print_r($eventDataCollector);
    }

    public function testUpdateUserNoChangeEmail()
    {
        $this->expectNoUserEmailChangeEvent();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserNoChangeEmail', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'email' => self::generateEmail('testUpdateUserNoChangeEmail', $this),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(self::generateEmail('testUpdateUserNoChangeEmail', $this)), $result['email']);
        /** @var EventDataCollector $eventDataCollector */
        //$eventDataCollector = self::$client->getProfile()->getCollector('events');
       // print_r($eventDataCollector);
    }

    public function testUserCreateNoChangeEmail()
    {
        $inviter = self::createUser(
            self::$userManager,
            self::generateEmail('testUserCreateNoChangeEmail-invitee', $this),
            'foo'
        );

        $invitation = new EmailInvitation();
        $invitation->setInviter($inviter);
        $invitation->setEmail(mb_strtolower(self::generateEmail('testUserCreateNoChangeEmail', $this)));
        self::$dm->persist($invitation);
        self::$dm->flush();

        $this->expectNoUserEmailChangeEvent();
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => self::generateEmail('testUserCreateNoChangeEmail', $this),
        ));
        $this->assertEquals(
            200,
            $this->getClientResponseStatusCode(),
            'Possible underlying indication that the UserChanged Event was fired'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(self::generateEmail('testUserCreateNoChangeEmail', $this)), $data['email']);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => self::generateEmail('testUserCreateNoChangeEmail', $this)]);
        $this->assertTrue($user !== null);

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s', $data['id']);
        $data = [
            'email' => self::generateEmail('testUserCreateNoChangeEmail', $this),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
    }
}
