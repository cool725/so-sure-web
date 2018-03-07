<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\IdentityLog;

/**
 * @group unit
 */
class IdentityLogTest extends \PHPUnit\Framework\TestCase
{

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testIsSessionDataPresent()
    {
        $identityLog = new IdentityLog();
        $this->assertFalse($identityLog->isSessionDataPresent());

        $identityLog = new IdentityLog();
        $identityLog->setCognitoId(1);
        $this->assertTrue($identityLog->isSessionDataPresent());

        $identityLog = new IdentityLog();
        $identityLog->setIp('1.1.1.1');
        $this->assertTrue($identityLog->isSessionDataPresent());
    }

    public function testIsSamePhone()
    {
        $identityLog = new IdentityLog();
        $phone = new Phone();
        $phone->setId(1);
        $diffPhone = new Phone();
        $diffPhone->setId(2);
        $this->assertNull($identityLog->isSamePhone());
        $this->assertNull($identityLog->isSamePhone($phone));

        $identityLog->setPhone($phone);
        $this->assertTrue($identityLog->isSamePhone($phone));
        $this->assertFalse($identityLog->isSamePhone($diffPhone));
    }
}
