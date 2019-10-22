<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class PolicyNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    public function supportsNormalization($data, $format = null)
    {
        $format = $format;
        return is_object($data) && $data instanceof Policy;
    }

    /**
     * @param PhonePolicy|SalvaPhonePolicy $object
     * @param string                       $format
     * @param array                        $context
     *
     * @return array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $format = $format;
        $groups = array_flip($context["groups"]);

        if (isset($groups[Oauth2Scopes::USER_STARLING_SUMMARY]) && $object->isActive()) {
            return [
                'policyNumber' => $object->getPolicyNumber(),
                'endDate' => $object->getEnd()->format('Y-m-d'),
                'insuredPhone' => $this->serializer->normalize($object->getPhone(), $format, $context),
                'connections' => count($object->getConnections()),
                'rewardPot' => (float) $object->getPotValue(),
                'rewardPotCurrency' =>'GBP' ,
            ];
        }

        if (isset($groups[Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY]) && $object->isActive()) {
            return [
                'policyNumber' => $object->getPolicyNumber(),
                'endDate' => $object->getEnd()->format('Y-m-d'),
                'insuredPhone' => $this->serializer->normalize($object->getPhone(), $format, $context),
                'connections' => count($object->getConnections()),
                'rewardPot' => (float) $object->getPotValue(),
                'rewardPotCurrency' =>'GBP' ,
            ];
        }

        return [];
    }
}
