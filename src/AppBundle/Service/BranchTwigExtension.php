<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class BranchTwigExtension extends \Twig_Extension
{
    /** @var BranchService */
    protected $branch;

    /** @var RequestStack */
    protected $requestStack;

    /**
     * @param BranchService $branch
     * @param RequestStack  $requestStack
     */
    public function __construct(
        BranchService $branch,
        RequestStack $requestStack
    ) {
        $this->branch = $branch;
        $this->requestStack = $requestStack;
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
            return $this->branch->appleLink($data, [], $source);
        }

        return $this->branch->downloadAppleLink($source);
    }

    public function google($source)
    {
        if ($this->getSCode()) {
            $data['scode'] = $this->getSCode();
            return $this->branch->googleLink($data, [], $source);
        }

        return $this->branch->downloadGoogleLink($source);
    }

    public function getName()
    {
        return 'app_extension';
    }
}
