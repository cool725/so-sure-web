<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\User;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class UserNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return is_object($data) && $data instanceof User;
    }

    /**
     * {@inheritdoc}
     *
     * @param User $object
     * @param string $format
     * @param array $context
     *
     * @return array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (in_array(Oauth2Scopes::USER_STARLING_SUMMARY, $context['groups'])) {
            return [
                'name' => $object->getName(),
                'policies' => $this->serializer->normalize($object->getPolicies(), $format, $context),
            ];
        }

        return [];
    }
}
