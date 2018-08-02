<?php
namespace App\Normalizer;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class PhoneNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return is_object($data) && $data instanceof Phone;
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
            /*'policyNumber' => $object->getPolicyNumber(),
            'endDate' => $object->getEnd(),*/
            'phoneName' => $object->getPhone()->__toString(),
            /*'connections' => count($object->getConnections()),
            'rewardPot' => $object->getPotValue(),
            'rewardPotCurrency' =>'GBP' ,*/
        ];
    }
}
