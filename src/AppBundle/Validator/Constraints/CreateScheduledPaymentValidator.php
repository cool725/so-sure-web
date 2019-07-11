<?php


namespace AppBundle\Validator\Constraints;


use AppBundle\Document\DateTrait;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class CreateScheduledPaymentValidator extends ConstraintValidator
{
    use DateTrait;

    /**
     * @param mixed $value
     * @param Constraint $constraint
     * @return bool
     * @throws \Exception
     */
    public function validate($value, Constraint $constraint)
    {
        if(!$constraint instanceof CreateScheduledPayment) {
            throw new UnexpectedTypeException($constraint, CreateScheduledPayment::class);
        }
        if (!$value instanceof \DateTime) {
            $value = new \DateTime($value);
        }
        if ($this->isWeekendOrBankHoliday($value)) {
            return false;
        }
        return true;
    }
}