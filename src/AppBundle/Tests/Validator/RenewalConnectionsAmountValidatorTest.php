<?php

namespace AppBundle\Tests\Validator;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Validator\Constraints\RenewalConnectionsAmount;
use AppBundle\Validator\Constraints\RenewalConnectionsAmountValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @group unit
 *
 * AppBundle\\Tests\\Validator\\RenewalConnectionsAmountValidatorTest
 */
class RenewalConnectionsAmountValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new RenewalConnectionsAmount();
    }

    public function tearDown()
    {
    }

    public function testValidatorExpected()
    {
        $validator = $this->configureValidator();

        $premium = new PhonePremium();
        $premium->setGwp(10);
        $premium->setIpt(1);
        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(Policy::STATUS_PENDING_RENEWAL);
        $policy->setPremium($premium);
        $user = new User();
        $policy->setUser($user);
        for ($i = 1; $i < 5; $i++) {
            $policyB = new HelvetiaPhonePolicy();
            $user = new User();
            $policyB->setUser($user);
            $connection = new RenewalConnection();
            $connection->setLinkedPolicy($policyB);
            $connection->setRenew(true);
            $policy->addRenewalConnection($connection);
        }
        $validator->validate($policy->getRenewalConnections(), self::$constraint);
    }

    public function testValidatorDisallowed()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        $premium = new PhonePremium();
        $premium->setGwp(10);
        $premium->setIpt(1);
        $policy = new HelvetiaPhonePolicy();
        $policy->setStatus(Policy::STATUS_PENDING_RENEWAL);
        $policy->setPremium($premium);
        $user = new User();
        $policy->setUser($user);
        for ($i = 1; $i < 15; $i++) {
            $policyB = new HelvetiaPhonePolicy();
            $user = new User();
            $policyB->setUser($user);
            $connection = new RenewalConnection();
            $connection->setLinkedPolicy($policyB);
            $connection->setRenew(true);
            $policy->addRenewalConnection($connection);
        }
        $validator->validate($policy->getRenewalConnections(), self::$constraint);
    }

    public function configureValidator($expectedMessage = null)
    {
        // mock the violation builder
        $builder = $this->getMockBuilder('Symfony\Component\Validator\Violation\ConstraintViolationBuilder')
            ->disableOriginalConstructor()
            ->setMethods(array('addViolation'))
            ->getMock()
        ;

        // mock the validator context
        /** @var ExecutionContextInterface $context */
        $context = $this->getMockBuilder('Symfony\Component\Validator\Context\ExecutionContext')
            ->disableOriginalConstructor()
            ->setMethods(array('buildViolation'))
            ->getMock()
        ;
        /** @var MockObject $mockContext */
        $mockContext = $context;

        if ($expectedMessage) {
            $builder->expects($this->once())
                ->method('addViolation')
            ;

            $mockContext->expects($this->once())
                ->method('buildViolation')
                ->with($this->equalTo($expectedMessage))
                ->will($this->returnValue($builder))
            ;
        } else {
            $mockContext->expects($this->never())
                ->method('buildViolation')
            ;
        }

        // initialize the validator with the mocked context
        $validator = new RenewalConnectionsAmountValidator();
        $validator->initialize($context);

        // return the validator
        return $validator;
    }
}
