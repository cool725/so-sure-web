<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Service\RequestService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class BankAccountNameValidator extends ConstraintValidator
{
    /** @var RequestService $requestService */
    protected $requestService;

    /** @var LoggerInterface $logger */
    protected $logger;

    public function __construct(RequestService $requestService, LoggerInterface $logger)
    {
        $this->requestService = $requestService;
        $this->logger = $logger;
    }

    public function validate($value, Constraint $constraint)
    {
        $user = $this->requestService->getUser();
        if ($this->isAccountName($user, $value) !== false) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('%string%', $value)
            ->addViolation();
    }

    public function isAccountName(User $user, $name)
    {
        // if the user isn't present, probably a non-session access - eg. backend task, so we don't want to trigger
        if (!$user) {
            return null;
        }

        // last name must be in the account name
        if (stripos($value, strtolower($user->getLastName())) !== false) {
            // manually verify cases where the first name isn't present
            // may want to check initials, etc in the future
            if (stripos($value, strtolower($user->getFirstName())) === false) {
                $this->logger->warning(sprintf(
                    'Validate Bank Account Name %s for User %s / %s',
                    $value,
                    $user->getName(),
                    $user->getId()
                ));
            }
            return true;
        }

        return false;
    }
}
