<?php
namespace AppBundle\Service;

use AppBundle\Document\Policy;
use Aws\S3\S3Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use Symfony\Component\Routing\Router;

class PolicyTwigExtension extends \Twig_Extension
{
    /** @var DocumentManager */
    protected $dm;

    /**
     * PolicyTwigExtension constructor.
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('get_policy', [$this, 'getPolicy']),
        );
    }

    public function getPolicy($id)
    {
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->find($id);
    }

    public function getName()
    {
        return 'app_twig_policy';
    }
}
