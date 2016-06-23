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

    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
