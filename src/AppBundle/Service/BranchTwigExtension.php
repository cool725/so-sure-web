<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

class BranchTwigExtension extends \Twig_Extension
{
    /** @var BranchService */
    protected $branch;

    /** @var RequestStack */
    protected $requestStack;

    /** @var LoggerInterface */
    protected $logger;

    protected $tokenStorage;

    /**
     * @param BranchService   $branch
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     */
    public function __construct(
        BranchService $branch,
        RequestStack $requestStack,
        LoggerInterface $logger,
        $tokenStorage
    ) {
        $this->branch = $branch;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
        $this->tokenStorage = $tokenStorage;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('branch_download', array($this, 'branch')),
            new \Twig_SimpleFunction('google_download', array($this, 'google')),
            new \Twig_SimpleFunction('apple_download', array($this, 'apple')),
        );
    }

    private function getReferer()
    {
        return $this->getSession('referer');
    }

    private function getUtm()
    {
        $utm = $this->getSession('utm');
        if ($utm) {
            return unserialize($utm);
        }

        return null;
    }

    private function getSCode()
    {
        return $this->getSession('scode');
    }

    private function getSession($var)
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();
        if ($session->isStarted()) {
            return $session->get($var);
        }

        return null;
    }

    private function getUser()
    {
        return $this->tokenStorage->getToken()->getUser();
    }

    private function getData()
    {
        $data = [
            'referer' => $this->getReferer(),
        ];
        $scode = $this->getSCode();
        if ($scode) {
            $data['scode'] = $scode;
            $data['$deeplink_path'] = sprintf('invite/scode/%s', $scode);
        }

        // Mainly for new users, but won't hurt for existing users
        $user = $this->getUser();
        $this->logger->info(sprintf('BranchTwig User: %s', $user ? $user->getId() : 'null'));
        if ($user) {
            $data['email'] = $user->getEmail();
            if ($user->getMobileNumber()) {
                $data['mobile'] = $user->getMobileNumber();
                if (!isset($data['$deeplink_path'])) {
                    $data['$deeplink_path'] = 'open/login/mobile';
                }
            }
        }

        return $data;
    }

    private function getSource($source)
    {
        $utm = $this->getUtm();
        if ($utm) {
            return $utm['source'];
        } else {
            return $source;
        }
    }

    private function getMedium()
    {
        $utm = $this->getUtm();
        if ($utm) {
            return $utm['medium'];
        } else {
            return 'web';
        }
    }

    private function getCampaign()
    {
        $utm = $this->getUtm();
        if ($utm) {
            return $utm['campaign'];
        } else {
            return 'downloadapp';
        }
    }

    public function branch($source)
    {
        return $this->branch->link(
            $this->getData(),
            $this->getSource($source),
            $this->getMedium(),
            $this->getCampaign()
        );
    }

    public function apple($source)
    {
        try {
            return $this->branch->appleLink(
                $this->getData(),
                $this->getSource($source),
                $this->getMedium(),
                $this->getCampaign()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed generating apple scode link', ['exception' => $e]);
        }

        return $this->branch->downloadAppleLink(
            $this->getSource($source),
            $this->getMedium(),
            $this->getCampaign()
        );
    }

    public function google($source)
    {
        try {
            return $this->branch->googleLink(
                $this->getData(),
                $this->getSource($source),
                $this->getMedium(),
                $this->getCampaign()
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed generating google scode link', ['exception' => $e]);
        }

        return $this->branch->downloadGoogleLink(
            $this->getSource($source),
            $this->getMedium(),
            $this->getCampaign()
        );
    }

    public function getName()
    {
        return 'app_twig_branch';
    }
}
