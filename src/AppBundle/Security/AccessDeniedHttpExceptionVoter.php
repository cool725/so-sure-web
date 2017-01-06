<?php

namespace AppBundle\Security;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RequestStack;
use Staffim\RollbarBundle\Voter\ReportVoterInterface;
use Symfony\Component\HttpFoundation\IpUtils;

class AccessDeniedHttpExceptionVoter implements ReportVoterInterface
{
    protected $ips;

    /** @var RequestStack */
    protected $requestStack;

    public function __construct(RequestStack $requestStack, array $ips)
    {
        $this->requestStack = $requestStack;
        $this->ips = $ips;
    }

    private function support($exception)
    {
        // Getting too many warnings that I don't investigate, so just ignore all access denied for now
        return $exception instanceof AccessDeniedHttpException;
        /*
        if (!$exception instanceof AccessDeniedHttpException) {
            return false;
        }
        
        $clientIp = $this->requestStack-> getCurrentRequest() ?
            $this->requestStack-> getCurrentRequest()->getClientIp() :
            '0.0.0.0';

        return !IpUtils::checkIp($clientIp, $this->ips);
        */
    }

    /**
     * @return boolean false to exclude from sending to rollbar; true will allow other voters to decide
     */
    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
