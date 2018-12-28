<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use AppBundle\Document\Connection\RenewalConnection;

class RenewalConnectionsAmountValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var RenewalConnectionsAmount $renewalConnectionsAmountConstraint */
        $renewalConnectionsAmountConstraint = $constraint;

        if (!is_array($value) && !($value instanceof \Doctrine\Common\Collections\ArrayCollection) &&
            !($value instanceof \Doctrine\ODM\MongoDB\PersistentCollection)) {
            throw new \Exception(sprintf('Expected array %s', get_class($value)));
        }

        $count = 0;
        $maxConnections = 0;
        foreach ($value as $connection) {
            if ($connection instanceof RenewalConnection && $connection->getRenew()) {
                $count++;
                $maxConnections = $connection->getSourcePolicy()->getMaxConnections();
            }
        }

        if ($count > $maxConnections) {
            $this->context->buildViolation($renewalConnectionsAmountConstraint->message)
                ->setParameter('%string%', $connection->getSourcePolicy()->getPolicyNumber())
                ->addViolation();
        }
    }
}
