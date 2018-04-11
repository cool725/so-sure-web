<?php

namespace AppBundle\Security;

use Staffim\RollbarBundle\Voter\ReportVoterInterface;

class UntrustedHostUnexpectedValueExceptionVoter implements ReportVoterInterface
{
    private function support($exception)
    {
        if ($exception instanceof \UnexpectedValueException &&
            mb_stripos($exception->getMessage(), "Untrusted Host") !== false) {
            return true;
        }

        return false;
    }

    /**
     * @return boolean false to exclude from sending to rollbar; true will allow other voters to decide
     */
    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
