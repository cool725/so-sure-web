<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use AppBundle\Document\User;

use AppBundle\Classes\DaviesClaim;

/**
 * @group functional-nonet
 */
class DaviesServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $daviesService;
    protected static $phoneA;
    protected static $phoneB;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$daviesService = self::$container->get('app.davies');

        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phoneA = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = $phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testUpdatePolicy()
    {
        $imeiOld = self::generateRandomImei();
        $imeiNew = self::generateRandomImei();

        $policy = new PhonePolicy();
        $policy->setId('1');
        $policy->setImei($imeiOld);
        $policy->setPhone(self::$phoneA);

        $claim = new Claim();
        $policy->addClaim($claim);
        
        $davies = new DaviesClaim();

        self::$daviesService->updatePolicy($claim, $davies);
        $this->assertEquals($imeiOld, $policy->getImei());
        $this->assertEquals(self::$phoneA->getId(), $policy->getPhone()->getId());

        $claimB = new Claim();
        $claimB->setReplacementPhone(self::$phoneB);
        $claimB->setReplacementImei($imeiNew);
        $policy->addClaim($claimB);

        $daviesB = new DaviesClaim();
        $daviesB->replacementMake = 'Apple';
        $daviesB->replacementModel = 'iPhone 4';

        self::$daviesService->updatePolicy($claimB, $daviesB);
        $this->assertEquals($imeiNew, $policy->getImei());
        $this->assertEquals(self::$phoneB->getId(), $policy->getPhone()->getId());
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateClaimDetailsPolicyNumber()
    {
        $policy = new PhonePolicy();
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber(1);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 2;

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateClaimDetailsName()
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $user->addPolicy($policy);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber(3);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 3;
        $daviesClaim->insuredName = 'Mr Bar Foo';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
    }

    public function testValidateClaimDetails()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setPostCode('AAA');
        $user = new User();
        $user->setBillingAddress($address);
        $user->setFirstName('foo');
        $user->setLastName('bar');
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $claim = new Claim();
        $policy->addClaim($claim);
        $policy->setPolicyNumber(1);

        $daviesClaim = new DaviesClaim();
        $daviesClaim->policyNumber = 1;
        $daviesClaim->insuredName = 'Mr foo bar';
        $daviesClaim->riskPostCode = 'AAA';

        self::$daviesService->validateClaimDetails($claim, $daviesClaim);
    }
}
