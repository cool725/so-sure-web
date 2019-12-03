<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\Oauth\Client;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Service\DaviesService;
use AppBundle\Service\DirectGroupService;
use AppBundle\Service\DirectGroupServiceExcel;
use AppBundle\Tests\Service\DaviesServiceTest;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ODM\MongoDB\DocumentManager;
use FOS\OAuthServerBundle\Model\ClientManager;
use SebastianBergmann\ObjectReflector\TestFixture\ClassWithIntegerAttributeName;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineClaimListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\ClaimEvent;
use AppBundle\Document\Claim;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Listener\\DoctrineClaimListenerTest
 */
class DoctrineClaimListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $testUser;
    /** @var DirectGroupService */
    protected static $directGroupService;
    /** @var DaviesService */
    protected static $daviesService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var DirectGroupService $directGroupService */
        $directGroupService = self::$container->get('app.directgroup');
        self::$directGroupService = $directGroupService;

        /** @var DaviesService $daviesService */
        $daviesService = self::$container->get('app.davies');
        self::$daviesService = $daviesService;
    }

    public function tearDown()
    {
    }

    public function testPreUpdateOther()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->never(), []);

        $changeSet = ['type' => [null, 'foo']];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateApproved()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_APPROVED]);

        $changeSet = ['status' => [null, Claim::STATUS_APPROVED]];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateSettled()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_SETTLED]);

        $changeSet = ['status' => [null, Claim::STATUS_SETTLED]];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPostPersistCreated()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_CREATED]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    public function testPostPersistApproved()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $listener = $this->createClaimEventListener($claim, $this->any(), [
            ClaimEvent::EVENT_CREATED,
            ClaimEvent::EVENT_APPROVED
        ]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    public function testPostPersistSettled()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $listener = $this->createClaimEventListener($claim, $this->any(), [
            ClaimEvent::EVENT_CREATED,
            ClaimEvent::EVENT_SETTLED
        ]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    public function testClaimsListenerActualDGDiff()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testClaimsListenerActualDGDiff', $this));
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setExcess(50);
        $claim->setIncurred(368.93);
        $claim->setPhoneReplacementCost(403.67);
        $claim->setClaimHandlingFees(15);
        $claim->setReservedValue(10);
        $claim->setNumber(rand(1, 999999));
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        $expectedUpdated = \DateTime::createFromFormat('U', time());
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        // Change inside the Claim::setPolicy does a doctrine update as well, which triggers the last updated
        $this->within($expectedUpdated->getTimestamp(), $claim->getUnderwriterLastUpdated()->getTimestamp(), 3);
        // $this->assertNull($claim->getUnderwriterLastUpdated());

        $dg = new DirectGroupHandlerClaim();
        $dg->insuredName = $policy->getUser()->getName();
        $dg->policyNumber = $policy->getPolicyNumber();
        $dg->incurred = $claim->getIncurred();
        $dg->claimNumber = $claim->getNumber();
        $dg->excess = $claim->getExcess();
        $dg->phoneReplacementCost = $claim->getPhoneReplacementCost();
        $dg->handlingFees = $claim->getClaimHandlingFees();
        $dg->reserved = $claim->getReservedValue();
        $dg->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $save = self::$directGroupService->saveClaim($dg, true);
        $this->assertTrue($save);

        $expectedUpdated = \DateTime::createFromFormat('U', time());

        static::$container->get('logger')->debug('--- Changed field ---');
        static::$container->get('logger')->debug('--- for compare ---');

        sleep(1);

        $dgNew = clone $dg;
        $dgNew->totalIncurred = $dgNew->totalIncurred + 0.0001;

        $save = self::$directGroupService->saveClaim($dgNew, true);
        $this->assertTrue($save);

        $updatedClaim = $this->loadClaim($claim);
        $this->assertNotNull($updatedClaim->getUnderwriterLastUpdated());

        $this->within($expectedUpdated->getTimestamp(), $claim->getUnderwriterLastUpdated()->getTimestamp(), 3);
    }

    public function testClaimsListenerActualDGSame()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testClaimsListenerActualDGSame', $this));
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setExcess(50);
        $claim->setIncurred(368.93);
        $claim->setPhoneReplacementCost(403.67);
        $claim->setClaimHandlingFees(15);
        $claim->setReservedValue(10);
        $claim->setNumber(rand(1, 999999));
        $claim->setHandlingTeam(Claim::TEAM_DIRECT_GROUP);
        $policy->addClaim($claim);
        $expectedUpdated = \DateTime::createFromFormat('U', time());
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        // Change inside the Claim::setPolicy does a doctrine update as well, which triggers the last updated
        $this->within($expectedUpdated->getTimestamp(), $claim->getUnderwriterLastUpdated()->getTimestamp(), 3);

        $dg = new DirectGroupHandlerClaim();
        $dg->insuredName = $policy->getUser()->getName();
        $dg->policyNumber = $policy->getPolicyNumber();
        $dg->incurred = $claim->getIncurred();
        $dg->claimNumber = $claim->getNumber();
        $dg->excess = $claim->getExcess();
        $dg->phoneReplacementCost = $claim->getPhoneReplacementCost();
        $dg->handlingFees = $claim->getClaimHandlingFees();
        $dg->reserved = $claim->getReservedValue();
        $dg->lossType = DirectGroupHandlerClaim::TYPE_LOSS;
        $save = self::$directGroupService->saveClaim($dg, true);
        $this->assertTrue($save);

        $expectedUpdated = \DateTime::createFromFormat('U', time());

        static::$container->get('logger')->debug('--- Changed field ---');
        static::$container->get('logger')->debug('--- for compare ---');

        sleep(1);

        $dgNew = clone $dg;
        $dgNew->incurred = $dgNew->incurred + 0.000001;

        $save = self::$directGroupService->saveClaim($dgNew, true);
        $this->assertTrue($save);

        $updatedClaim = $this->loadClaim($claim);
        $this->assertNotNull($updatedClaim->getUnderwriterLastUpdated());

        $this->within($expectedUpdated->getTimestamp(), $claim->getUnderwriterLastUpdated()->getTimestamp(), 3);
    }

    public function testClaimsListenerActualDaviesDiff()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testClaimsListenerActualDaviesDiff', $this));
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setExcess(50);
        $claim->setIncurred(368.93);
        $claim->setPhoneReplacementCost(403.67);
        $claim->setTransactionFees(0.24);
        $claim->setClaimHandlingFees(15);
        $claim->setReservedValue(10);
        $claim->setNumber(rand(1, 999999));
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        $davies = new DaviesHandlerClaim();
        $davies->status = 'open';
        $davies->insuredName = $policy->getUser()->getName();
        $davies->policyNumber = $policy->getPolicyNumber();
        $davies->incurred = $claim->getIncurred();
        $davies->claimNumber = $claim->getNumber();
        $davies->excess = $claim->getExcess();
        $davies->phoneReplacementCost = $claim->getPhoneReplacementCost();
        $davies->handlingFees = $claim->getClaimHandlingFees();
        $davies->reserved = $claim->getReservedValue();
        $davies->transactionFees = $claim->getTransactionFees();
        $davies->lossType = 'loss';
        $save = self::$daviesService->saveClaim($davies, true);
        $this->assertTrue($save);

        $expectedUpdated = \DateTime::createFromFormat('U', time());

        static::$container->get('logger')->debug('--- Changed field ---');
        static::$container->get('logger')->debug('--- for compare ---');

        sleep(1);

        $daviesNew = clone $davies;
        $daviesNew->incurred = $davies->incurred + 0.0001;

        $save = self::$daviesService->saveClaim($daviesNew, true);
        $this->assertTrue($save);

        $updatedClaim = $this->loadClaim($claim);
        $this->assertNotNull($updatedClaim->getUnderwriterLastUpdated());

        $this->assertNotEquals($expectedUpdated, $updatedClaim->getUnderwriterLastUpdated(), '', 0);
    }

    public function testClaimsListenerActualDaviesSame()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testClaimsListenerActualDaviesSame', $this));
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setExcess(50);
        $claim->setIncurred(368.93);
        $claim->setPhoneReplacementCost(403.67);
        $claim->setTransactionFees(0.24);
        $claim->setClaimHandlingFees(15);
        $claim->setReservedValue(10);
        $claim->setNumber(rand(1, 999999));
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->persist($claim);
        static::$dm->flush();

        $davies = new DaviesHandlerClaim();
        $davies->status = 'open';
        $davies->insuredName = $policy->getUser()->getName();
        $davies->policyNumber = $policy->getPolicyNumber();
        $davies->incurred = $claim->getIncurred();
        $davies->claimNumber = $claim->getNumber();
        $davies->excess = $claim->getExcess();
        $davies->phoneReplacementCost = $claim->getPhoneReplacementCost();
        $davies->handlingFees = $claim->getClaimHandlingFees();
        $davies->reserved = $claim->getReservedValue();
        $davies->transactionFees = $claim->getTransactionFees();
        $davies->lossType = 'loss';
        $save = self::$daviesService->saveClaim($davies, true);
        $this->assertTrue($save);

        $expectedUpdated = \DateTime::createFromFormat('U', time());

        static::$container->get('logger')->debug('--- Changed field ---');
        static::$container->get('logger')->debug('--- for compare ---');

        sleep(1);

        $daviesNew = clone $davies;

        $save = self::$daviesService->saveClaim($daviesNew, true);
        $this->assertTrue($save);

        $updatedClaim = $this->loadClaim($claim);
        $this->assertNotNull($updatedClaim->getUnderwriterLastUpdated());

        $this->within($expectedUpdated->getTimestamp(), $claim->getUnderwriterLastUpdated()->getTimestamp(), 3);
    }

    /**
     * @param Claim $claim
     * @return Claim
     */
    private function loadClaim(Claim $claim)
    {
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $updatedClaim */
        $updatedClaim = $repo->find($claim->getId());

        return $updatedClaim;
    }

    private function createClaimEventListener(Claim $claim, $count, $eventTypes)
    {
        $event = new ClaimEvent($claim);
        $dispatcher = $this->getMockBuilder(EventDispatcher::class)
                         ->setMethods(array('dispatch'))
                         ->getMock();

        if ($count != $this->never()) {
            $loop = 0;
            foreach ($eventTypes as $eventType) {
                $dispatcher->expects($this->at($loop))
                             ->method('dispatch')
                             ->with($eventType, $event);
                $loop++;
            }
        } else {
            $dispatcher->expects($count)
                         ->method('dispatch');
        }

        $listener = new DoctrineClaimListener($dispatcher);
        $reader = new AnnotationReader();
        $listener->setReader($reader);

        return $listener;
    }

    /**
     * Checks that two values are within a range of one another.
     * @param number $a     is the first value.
     * @param number $b     is the second value.
     * @param number $range is how close they must be or closer.
     */
    private function within($a, $b, $range)
    {
        $this->assertLessThanOrEqual($range, abs($a - $b));
    }
}
