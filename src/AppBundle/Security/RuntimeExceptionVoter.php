<?php

namespace AppBundle\Security;

use Staffim\RollbarBundle\Voter\ReportVoterInterface;

class RuntimeExceptionVoter implements ReportVoterInterface
{
    private function support($exception)
    {
        // Don't sent HWI OAuth No resource owner
        // Verify: GET -UsEd https://wearesosure.com/login/LoginForm.jsp
        if ($exception instanceof \RuntimeException &&
            mb_stripos($exception->getMessage(), "No resource owner") !== false) {
            return true;
        }

        return false;
    }

    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
