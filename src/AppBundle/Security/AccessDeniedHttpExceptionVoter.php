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
        if (!$exception instanceof AccessDeniedHttpException) {
            return false;
        }
        
        $clientIp = $this->requestStack-> getCurrentRequest() ?
            $this->requestStack-> getCurrentRequest()->getClientIp() :
            '0.0.0.0';

        return !IpUtils::checkIp($clientIp, $this->ips);
    }

    public function vote($exception)
    {
        return !$this->support($exception);
    }
}
