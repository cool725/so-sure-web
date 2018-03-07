<?php

namespace AppBundle\Tests\Validator;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Validator\Constraints\PhoneNumber;
use AppBundle\Validator\Constraints\PhoneNumberValidator;

/**
 * @group unit
 */
class PhoneNumberValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new PhoneNumber();
    }

    public function tearDown()
    {
    }

    public function testValidatorExpectedBeforeMin()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // standard alphanumeric
        $validator->validate('1234567', self::$constraint);
    }

    public function testValidatorExpectedMin()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('12345678', self::$constraint);
    }

    public function testValidatorExpectedMax()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('12345678901234567890', self::$constraint);
    }

    public function testValidatorExpectedAfterMax()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // standard alphanumeric
        $validator->validate('123456789012345678901', self::$constraint);
    }

    public function testValidatorIngoreChars()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate('+12(34)5-678 90123.4567890', self::$constraint);
    }
    
    /**
     * Configure a Validator.
     *
     * @param string $expectedMessage The expected message on a validation violation, if any.
     *
     * @return AcmeBundle\Validator\Constraints\MobileValidator
     */
    public function configureValidator($expectedMessage = null)
    {
        // mock the violation builder
        $builder = $this->getMockBuilder('Symfony\Component\Validator\Violation\ConstraintViolationBuilder')
            ->disableOriginalConstructor()
            ->setMethods(array('addViolation'))
            ->getMock()
        ;

        // mock the validator context
        $context = $this->getMockBuilder('Symfony\Component\Validator\Context\ExecutionContext')
            ->disableOriginalConstructor()
            ->setMethods(array('buildViolation'))
            ->getMock()
        ;

        if ($expectedMessage) {
            $builder->expects($this->once())
                ->method('addViolation')
            ;

            $context->expects($this->once())
                ->method('buildViolation')
                ->with($this->equalTo($expectedMessage))
                ->will($this->returnValue($builder))
            ;
        } else {
            $context->expects($this->never())
                ->method('buildViolation')
            ;
        }

        // initialize the validator with the mocked context
        $validator = new PhoneNumberValidator();
        $validator->initialize($context);

        // return the TokenValidator
        return $validator;
    }
}
