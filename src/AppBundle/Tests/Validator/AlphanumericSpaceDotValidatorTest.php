<?php

namespace AppBundle\Tests\Validator;

use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Validator\Constraints\AlphanumericSpaceDot;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
        $validator->validate("My phone has dropped during work and the front screen has shattered. I am still able to use the phone but would like the screen repaired.Thanks Darren", self::$constraint);
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
        $validator = new AlphanumericSpaceDotValidator();
        $validator->initialize($context);

        // return the AlphanumericSpaceDotValidator
        return $validator;
    }
}
