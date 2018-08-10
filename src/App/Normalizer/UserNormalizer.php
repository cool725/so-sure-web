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

    public function supportsNormalization($data, $format = null)
    {
        $format = $format;

        return is_object($data) && $data instanceof User;
    }

    /**
     * @param User   $object
     * @param string $format
     * @param array  $context
     *
     * @return array
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (in_array(Oauth2Scopes::USER_STARLING_SUMMARY, $context['groups'])) {
            $policies = $this->serializer->normalize($object->getPolicies(), $format, $context);
            $policies = array_filter($policies);

            return [
                'name' => $object->getName(),
                'policies' => $policies,
            ];
        }

        return [];
    }
}
