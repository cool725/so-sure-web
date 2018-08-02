<?php
namespace App\Normalizer;

use AppBundle\Document\Policy;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class PolicyNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return is_object($data) && $data instanceof Policy;
    }

    /**
     * {@inheritdoc}
     *
     * @param PhonePolicy|SalvaPhonePolicy $object
     * @param string $format
     * @param array $context
     *
     * @return array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        return [
            'policyNumber' => $object->getPolicyNumber(),
            'endDate' => $object->getEnd()->format('Y-m-d'),
            'phoneName' => $object->getPhone()->__toString(),
            'connections' => count($object->getConnections()),
            'rewardPot' => (float)$object->getPotValue(),
            'rewardPotCurrency' =>'GBP' ,
        ];
    }
}
