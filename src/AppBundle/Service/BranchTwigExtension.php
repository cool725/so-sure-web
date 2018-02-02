<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\User;

class BranchTwigExtension extends \Twig_Extension
{
    /** @var BranchService */
    protected $branch;

    /** @var RequestService */
    protected $requestService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param BranchService   $branch
     * @param LoggerInterface $logger
     * @param RequestService  $requestService
     */
    public function __construct(
        BranchService $branch,
        LoggerInterface $logger,
        RequestService $requestService
    ) {
        $this->branch = $branch;
        $this->logger = $logger;
        $this->requestService = $requestService;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('branch_download', array($this, 'branch')),
            new \Twig_SimpleFunction('google_download', array($this, 'linkToGoogleDownload')),
            new \Twig_SimpleFunction('apple_download', array($this, 'linkToAppleDownload')),
        );
    }

    private function getData()
    {
        $data = [
            'referer' => $this->requestService->getReferer(),
        ];
        if ($scode = $this->requestService->getSCode()) {
            $data['scode'] = $scode;
            $data['$deeplink_path'] = sprintf('invite/scode/%s', $scode);
        }
        if ($trackingId = $this->requestService->getTrackingId()) {
            $data['sosure_tracking_id'] = $trackingId;
        }

        // Mainly for new users, but won't hurt for existing users
        $user = $this->requestService->getUser();
        if ($user && $user instanceof User) {
            $data['email'] = $user->getEmail();
            if ($user->getMobileNumber()) {
                $data['mobile'] = $user->getMobileNumber();
                if (!isset($data['$deeplink_path'])) {
                    $data['$deeplink_path'] = 'open/login/sms';
                }
            }
        }

        return $data;
    }

    private function getSource()
    {
        $utm = $this->requestService->getUtm();
        $referer = $this->requestService->getReferer();
        if ($utm) {
            return $utm['source'];
        } elseif ($referer) {
            return $referer;
        } else {
            return 'wearesosure.com';
        }
    }

    private function getMedium($medium)
    {
        $utm = $this->requestService->getUtm();
        $referer = $this->requestService->getReferer();
        if ($utm) {
            return $utm['medium'];
        } elseif ($referer) {
            return 'organic';
        } else {
            return $medium;
        }
    }

    private function getCampaign()
    {
        $utm = $this->requestService->getUtm();
        if ($utm) {
            return $utm['campaign'];
        } else {
            return 'downloadapp';
        }
    }

    public function branch($medium)
    {
        return $this->branch->link(
            $this->getData(),
            $this->getSource(),
            $this->getMedium($medium),
            $this->getCampaign()
        );
    }

    public function linkToAppleDownload($medium)
    {
        return $this->branch->linkToAppleDownload($medium);
    }

    public function apple($medium)
    {
        try {
            return $this->branch->appleLink(
                $this->getData(),
                $this->getSource(),
                $this->getMedium($medium),
                $this->getCampaign()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed generating apple scode link', ['exception' => $e]);
        }

        return $this->branch->downloadAppleLink(
            $this->getSource(),
            $this->getMedium($medium),
            $this->getCampaign()
        );
    }

    public function linkToGoogleDownload($medium)
    {
        return $this->branch->linkToGoogleDownload($medium);
    }

    public function google($medium)
    {
        try {
            return $this->branch->googleLink(
                $this->getData(),
                $this->getSource(),
                $this->getMedium($medium),
                $this->getCampaign()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed generating google scode link', ['exception' => $e]);
        }

        return $this->branch->downloadGoogleLink(
            $this->getSource(),
            $this->getMedium($medium),
            $this->getCampaign()
        );
    }

    public function getName()
    {
        return 'app_twig_branch';
    }
}
