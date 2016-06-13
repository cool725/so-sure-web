<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Tests\UserClassTrait;

/**
 * @group unit
 */
class ConnectionTest extends \PHPUnit_Framework_TestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testConnection()
    {
        $connection = new Connection();
        $this->assertEquals(0, $connection->getValue());
        $this->assertEquals(0, $connection->getTotalValue());

        $connection->setValue(10);
        $this->assertEquals(10, $connection->getValue());
        $this->assertEquals(10, $connection->getTotalValue());

        $connection->setPromoValue(5);
        $this->assertEquals(5, $connection->getPromoValue());
        $this->assertEquals(15, $connection->getTotalValue());
    }
}
