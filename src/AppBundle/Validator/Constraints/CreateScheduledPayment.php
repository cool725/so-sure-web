<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Class CreateScheduledPayment
 * @package AppBundle\Validator\Constraints
 * @Annotation
 */
class CreateScheduledPayment extends Constraint
{
    public $message = "Cannot schedule a payment for a bank holiday, weekend, or a date that a payment is already scheduled.";
}