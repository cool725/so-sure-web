<?php

namespace AppBundle\Tests\Validator;

use AppBundle\Validator\Constraints\Token;
use AppBundle\Validator\Constraints\TokenValidator;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @group unit
 */
class TokenValidatorTest extends \PHPUnit\Framework\TestCase
{
    protected static $constraint;

    public static function setUpBeforeClass()
    {
         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$constraint = new Token();
    }

    public function tearDown()
    {
    }

    public function testValidatorExpected()
    {
        $validator = $this->configureValidator();

        // standard alphanumeric
        $validator->validate(' aA1-.,;:+():_£&@*!^#"%/', self::$constraint);
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
        $validator = new TokenValidator();
        $validator->initialize($context);

        // return the TokenValidator
        return $validator;
    }
}
