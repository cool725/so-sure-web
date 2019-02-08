<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Service\RequestService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use AppBundle\Document\User;

class BankAccountNameValidator extends ConstraintValidator
{
    /** @var RequestService $requestService */
    protected $requestService;

    /** @var LoggerInterface $logger */
    protected $logger;

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __construct(RequestService $requestService, LoggerInterface $logger)
    {
        $this->requestService = $requestService;
        $this->logger = $logger;
    }

    public function validate($value, Constraint $constraint)
    {
        /** @var BankAccountName $bankAccountNameConstraint */
        $bankAccountNameConstraint = $constraint;

        $user = $this->requestService->getUser();
        if ($this->isAccountName($value, $user) !== false) {
            return;
        }

        $this->context->buildViolation($bankAccountNameConstraint->message)
            ->setParameter('%string%', $value)
            ->setParameter('%name%', $user ? $user->getName() : 'Unknown')
            ->addViolation();
    }

    public function isAccountName($name, User $user = null)
    {
        // if the user isn't present, probably a non-session access - eg. backend task, so we don't want to trigger
        if (!$user) {
            return null;
        } elseif ($user->hasEmployeeRole()) {
            // in order to upload addacs files which may invalidate the mandates, we need to be able to avoid
            // triggering this validator as will be updating an account that isn't theirs
            return null;
        }

        // last name must be in the account name
        if (preg_match('/\b('.$user->getLastName().')\b/i', $name)) {
            // manually verify cases where the first name isn't present
            // may want to check initials, etc in the future
            if (!preg_match('/\b('.$user->getFirstName().')\b/i', $name)) {
                $this->logger->warning(sprintf(
                    'Validate Bank Account Name %s for User %s / %s',
                    $name,
                    $user->getName(),
                    $user->getId()
                ));
            }
            return true;
        }

        $this->logger->debug(sprintf(
            'Validate Bank Account Failed Name %s for User %s / %s',
            $name,
            $user->getName(),
            $user->getId()
        ));

        return false;
    }
}
