<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;

class FeatureTwigExtension extends \Twig_Extension
{
    protected $featureService;

    /**
     */
    public function __construct($featureService)
    {
        $this->featureService = $featureService;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('feature_enabled', [$this, 'isEnabled']),
        );
    }

    public function isEnabled($featureName)
    {
        return $this->featureService->isEnabled($featureName);
    }

    public function getName()
    {
        return 'app_twig_feature';
    }
}
