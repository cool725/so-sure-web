<?php

namespace AppBundle\Tests\Validator;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Validator\Constraints\UkMobile;
use AppBundle\Validator\Constraints\UkMobileValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @group unit
 */
class UkMobileValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new UkMobile();
    }

    public function tearDown()
    {
    }

    public function testValidatorExpected00()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('00447775740400', self::$constraint);
    }

    public function testValidatorExpectedPlus()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('+447775740400', self::$constraint);
    }

    public function testValidatorExpected07()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('07775740400', self::$constraint);
    }

    public function testValidatorShort()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // disallowed character
        $validator->validate('+44777574040', self::$constraint);
    }

    public function testValidatorLong()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // disallowed character
        $validator->validate('+4477757404000', self::$constraint);
    }

    public function testConform()
    {
        $validator = new UkMobileValidator();
        $this->assertEquals('', $validator->conform('+447775 740400'));
        $this->assertEquals('+447775740400', $validator->conform('+447775740400'));
        $this->assertEquals('+447775740400', $validator->conform('+447775740400123'));
        $this->assertEquals('', $validator->conform('+44777574040'));

        $this->assertEquals('', $validator->conform('00447775 740400'));
        $this->assertEquals('00447775740400', $validator->conform('00447775740400'));
        $this->assertEquals('00447775740400', $validator->conform('00447775740400123'));
        $this->assertEquals('', $validator->conform('0044777574040'));

        $this->assertEquals('', $validator->conform('07775 740400'));
        $this->assertEquals('07775740400', $validator->conform('07775740400'));
        $this->assertEquals('07775740400', $validator->conform('07775740400123'));
        $this->assertEquals('', $validator->conform('0777574040'));
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
        $validator = new UkMobileValidator();
        $validator->initialize($context);

        // return the TokenValidator
        return $validator;
    }
}
