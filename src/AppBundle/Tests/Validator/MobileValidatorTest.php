<?php

namespace AppBundle\Tests\Validator;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Validator\Constraints\Mobile;
use AppBundle\Validator\Constraints\MobileValidator;

/**
 * @group unit
 */
class MobileValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new Mobile();
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

    public function testValidatorNoCountry()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // disallowed character
        $validator->validate('07775740400', self::$constraint);
    }

    /**
     * Configure a MobileValidator.
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
        $validator = new MobileValidator();
        $validator->initialize($context);

        // return the TokenValidator
        return $validator;
    }
}
