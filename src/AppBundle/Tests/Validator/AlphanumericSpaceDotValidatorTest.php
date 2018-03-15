<?php

namespace AppBundle\Tests\Validator;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Validator\Constraints\AlphanumericSpaceDot;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;

/**
 * @group unit
 */
class AlphanumericSpaceDotValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new AlphanumericSpaceDot();
    }

    public function tearDown()
    {
    }

    public function testValidatorExpected()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate(' aA1-.,;:+():_£&@*!^#"%/’[]', self::$constraint);
        $validator->validate("'", self::$constraint);
    }

    public function testValidatorAccented()
    {
        $validator = $this->configureValidator();

        // a few random latin1 accented
        $validator->validate('ÀÁÂÇÆÒåëñüÿ', self::$constraint);
    }

    public function testValidatorDisallowed()
    {
        $validator = $this->configureValidator(self::$constraint->message);

        // disallowed character
        $validator->validate('$', self::$constraint);
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
        $validator = new AlphanumericSpaceDotValidator();
        $validator->initialize($context);

        // return the AlphanumericSpaceDotValidator
        return $validator;
    }
}
