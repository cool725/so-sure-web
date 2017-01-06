<?php

namespace AppBundle\Security;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Staffim\RollbarBundle\Voter\ReportVoterInterface;

class NotFoundHttpExceptionVoter implements ReportVoterInterface
{
    private function support($exception)
    {
        return $exception instanceof NotFoundHttpException;
    }

    /**
     * @return boolean false to exclude from sending to rollbar; true will allow other voters to decide
     */
    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
