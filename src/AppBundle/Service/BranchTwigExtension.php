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

    /**
     * @param BranchService   $branch
     * @param RequestStack    $requestStack
     * @param LoggerInterface $logger
     */
    public function __construct(
        BranchService $branch,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->branch = $branch;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('branch_download', array($this, 'branch')),
            new \Twig_SimpleFunction('google_download', array($this, 'google')),
            new \Twig_SimpleFunction('apple_download', array($this, 'apple')),
        );
    }

    private function getSCode()
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request->getSession();
        if ($session->isStarted()) {
            return $session->get('scode');
        }

        return null;
    }
    
    public function branch($source)
    {
        $data = [];
        if ($this->getSCode()) {
            $data['scode'] = $this->getSCode();
        }

        return $this->branch->link($data, [], $source);
    }

    public function apple($source)
    {
        if ($this->getSCode()) {
            $data['scode'] = $this->getSCode();
            try {
                return $this->branch->appleLink($data, [], $source);
            } catch (\Exception $e) {
                $this->logger->error('Failed generating apple scode link', ['exception' => $e]);
            }
        }

        return $this->branch->downloadAppleLink($source);
    }

    public function google($source)
    {
        if ($this->getSCode()) {
            $data['scode'] = $this->getSCode();
            try {
                return $this->branch->googleLink($data, [], $source);
            } catch (\Exception $e) {
                $this->logger->error('Failed generating google scode link', ['exception' => $e]);
            }
        }

        return $this->branch->downloadGoogleLink($source);
    }

    public function getName()
    {
        return 'app_extension';
    }
}
