<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\Phone;
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
        $format = $format;

        return is_object($data) && $data instanceof Phone;
    }

    /**
     * {@inheritdoc}
     *
     * @param Phone  $object
     * @param string $format
     * @param array  $context
     *
     * @return string|array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $format = $format;

        if (in_array(Oauth2Scopes::USER_STARLING_SUMMARY, $context['groups'])) {
            if ($object instanceof Phone) {
                return $object->__toString();
            }
        }

        if (in_array(Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY, $context['groups'])) {
            if ($object instanceof Phone) {
                return $object->__toString();
            }
        }

        return [];
    }
}
