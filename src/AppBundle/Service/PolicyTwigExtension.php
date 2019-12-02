<?php

namespace AppBundle\Service;

use AppBundle\Document\Policy;
use AppBundle\Classes\Salva;
use AppBundle\Classes\Helvetia;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use Aws\S3\S3Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Router;

/**
 * Provides policy related extensions to twig.
 */
class PolicyTwigExtension extends \Twig_Extension
{
    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $checkoutSalvaApiKey;

    /** @var string */
    protected $checkoutHelvetiaApiKey;

    /**
     * PolicyTwigExtension constructor.
     * @param DocumentManager $dm                     is used to query policies from the database.
     * @param string          $checkoutSalvaApiKey    is the checkout api public key to use on salva policies.
     * @param string          $checkoutHelvetiaApiKey is the checkout api public key to use on helvetia policies.
     */
    public function __construct(DocumentManager $dm, $checkoutSalvaApiKey, $checkoutHelvetiaApiKey)
    {
        $this->dm = $dm;
        $this->checkoutSalvaApiKey = $checkoutSalvaApiKey;
        $this->checkoutHelvetiaApiKey = $checkoutHelvetiaApiKey;
    }

    /**
     * @inheritDoc
     */
    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('get_policy', [$this, 'getPolicy']),
            new \Twig_SimpleFunction('checkoutApiKey', [$this, 'checkoutApiKey'])
        ];
    }

    /**
     * Returns a policy given a policy id.
     * @param string $id is the id by which to seek the policy.
     * @return Policy|null the found policy if it was found.
     */
    public function getPolicy($id)
    {
        $repo = $this->dm->getRepository(Policy::class);
        return $repo->find($id);
    }

    /**
     * Gets the checkout public api key that a given policy should use based on whether it is a Salva or Helvetia
     * policy. If there is no appropropriate api key then an invalid argument exception is thrown but there should not
     * really be a situation in which this would occur.
     * @param Policy $policy is the policy to check about.
     * @return string the appropriate api key.
     */
    public function checkoutApiKey($policy)
    {
        $underwriter = $policy->getUnderwriterName();
        if ($underwriter == Salva::NAME) {
            return $this->checkoutSalvaApiKey;
        } elseif ($underwriter == Helvetia::NAME) {
            return $this->checkoutHelvetiaApiKey;
        }
        throw new \InvalidArgumentException(sprintf(
            'policy %s does not have valid checkout api key',
            $policy->getId()
        ));
    }

    /**
     * Gives the name of this extension.
     * @return string the name.
     */
    public function getName()
    {
        return 'app_twig_policy';
    }
}
