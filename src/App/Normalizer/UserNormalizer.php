<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class UserNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    /**
     * @codingStandardsIgnoreStart(Generic.CodeAnalysis.UnusedFunctionParameter)
     * phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function supportsNormalization($data, $format = null)
    {
        return is_object($data) && $data instanceof User;
    }

    /**
     * @param User   $object
     * @param string $format
     * @param array  $context
     *
     * @return array
     *
     * @codingStandardsIgnoreStart(Generic.CodeAnalysis.UnusedFunctionParameter)
     * phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (in_array(Oauth2Scopes::USER_STARLING_SUMMARY, $context['groups'])) {
            /** @var PhonePolicy $policy */
            $policy = $object->getPolicies()
                ->filter(function (Policy $policy) {
                    return $policy->isActive();
                })
                ->first();

            $policyNumber = $policy->getPolicyNumber();
            $phone = $policy->getPhone();
            $expiresDate = $policy->getEnd()->format('M jS Y');   // Dec 25th 2018
            $connections = $policy->getConnections()->count();
            $pot = $policy->getPotValue();

            // @codingStandardsIgnoreStart
            $text = "Expires on {$expiresDate}. You currently have {$connections} connections & your reward pot is worth £{$pot}";
            // @codingStandardsIgnoreEnd

            return [
                'widgets' => [
                    [
                        'type' => 'TEXT',
                        "title" => "So-Sure Policy {$policyNumber} for your {$phone}",
                        'text' => $text,
                        #'launchUrl' => 'https://yourapi.com/specific/path',
                    ]
                ],
                #'name' => $object->getName(),
                #'policies' => $policies,
            ];
        }

        return [];
    }
}
