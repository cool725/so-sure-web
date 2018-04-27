<?php

namespace AppBundle\Tests\Validator;

use AppBundle\Document\User;
use AppBundle\Validator\Constraints\BankAccountName;
use AppBundle\Validator\Constraints\BankAccountNameValidator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @group unit
 */
class BankAccountNameValidatorTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $constraint;
    protected static $requestService;
    protected static $logger;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$requestService = self::$container->get('app.request');
        self::$logger = self::$container->get('logger');

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new BankAccountName();
    }

    public function tearDown()
    {
    }

    public function testBankAccountNameValidatorNoUser()
    {
        $validator = $this->configureValidator();

        $validator->validate('foo bar', self::$constraint);
    }

    public function testIsAccountName()
    {
        $validator = $this->configureValidator();
        $this->assertNull($validator->isAccountName('foo', null));

        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');

        $validator->setLogger($this->configureLoggerWarning($this->never()));
        $this->assertTrue($validator->isAccountName('foo bar', $user));

        $validator->setLogger($this->configureLoggerWarning($this->once()));
        $this->assertTrue($validator->isAccountName('f bar', $user));

        $validator->setLogger($this->configureLoggerWarning($this->once()));
        $this->assertTrue($validator->isAccountName('bar', $user));

        $validator->setLogger($this->configureLoggerWarning($this->never()));
        $this->assertFalse($validator->isAccountName('f', $user));

        $validator->setLogger($this->configureLoggerWarning($this->never()));
        $this->assertFalse($validator->isAccountName('foobar', $user));

        $validator->setLogger($this->configureLoggerWarning($this->never()));
        $this->assertFalse($validator->isAccountName('f barf', $user));
    }

    public function configureLoggerWarning($number)
    {
        // mock the violation builder
        $builder = $this->getMockBuilder(LoggerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(array('warning', 'error', 'emergency', 'critical', 'notice', 'info', 'alert', 'debug', 'log'))
            ->getMock()
        ;

        $builder->expects($number)
            ->method('warning')
        ;

        return $builder;
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
        $validator = new BankAccountNameValidator(static::$requestService, static::$logger);
        $validator->initialize($context);

        // return the TokenValidator
        return $validator;
    }
}
