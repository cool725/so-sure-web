<?php

namespace AppBundle\Security;

use Staffim\RollbarBundle\Voter\ReportVoterInterface;

class UntrustedHostUnexpectedValueExceptionVoter implements ReportVoterInterface
{
    private function support($exception)
    {
        if ($exception instanceof \UnexpectedValueException &&
            stripos($exception->getMessage(), "Untrusted Host") !== false) {
            return true;
        }

        return false;
    }

    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
