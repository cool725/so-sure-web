<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Calculates the right pricing for things.
 */
class PriceService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger, DocumentManager $dm)
    {
        $this->logger = $logger;
        $this->dm = $dm;
    }

    /**
     * Gets the right price for a given phone policy.
     * @param PhonePolicy $policy is the policy we are getting the price for.
     * @return PhonePrice the price that should be paid.
     */
    public function phonePrice($policy)
    {
        $user = $policy->getUser();
        $phone = $policy->getPhone();
        if (!$user) {
            throw new \IllegalArgumentException(sprintf(
                "Trying to get price for phone policy '%s' lacking user",
                $policy->getId()
            ));
        }
        if (!$phone) {
            throw new \InvalidArgumentException(sprintf(
                "Trying to get price for phone policy '%s' lacking phone",
                $policy->getId()
            ));
        }
        // needs to be able to handle (in order of precedence):
        //  - renewal special logic
        //  - current offer price
        //  - current normal price
    }
}
