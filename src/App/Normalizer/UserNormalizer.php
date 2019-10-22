<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerAwareTrait;

class UserNormalizer implements NormalizerInterface, SerializerAwareInterface
{
    use SerializerAwareTrait;

    /** @var UrlGeneratorInterface */
    private $router;

    public function __construct(UrlGeneratorInterface $router)
    {
        $this->router = $router;
    }

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
        if (in_array(Oauth2Scopes::USER_STARLING_SUMMARY, $context['groups'])
            || in_array(Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY, $context['groups'])) {
            /** @var PhonePolicy $policy */
            $policy = $object->getPolicies()
                ->filter(function (Policy $policy) {
                    return $policy->isActive();
                })
                ->first()
            ;

            $userHomepage = $this->router->generate('user_home', [], UrlGeneratorInterface::ABSOLUTE_URL);

            if ($policy == false) {
                return [
                    'widgets' => [
                        [
                            'type' => 'TEXT',
                            "title" => "SO-SURE insurance policy",
                            'text' => "You don't have a SO-SURE Policy",
                            'launchUrl' => $userHomepage,
                        ]
                    ],
                ];
            }

            $policyNumber = $policy->getPolicyNumber();
            $phone = $policy->getPhone();
            $expiresDate = $policy->getEnd()->format('M jS Y');   // Dec 25th 2018
            $connections = $policy->getConnections()->count();
            $pot = $policy->getPotValue();

            return [
                'widgets' => [
                    [
                        'type' => 'TEXT',
                        'title' => 'Covered Device',
                        'text' => (string) $phone,
                        'launchUrl' => $userHomepage,
                    ],
                    [
                        'type' => 'TEXT',
                        'title' => 'Policy Reference',
                        'text' => $policyNumber,
                        'launchUrl' => $userHomepage,
                    ],
                    [
                        'type' => 'TEXT',
                        'title' => 'Expiry Date',
                        'text' => $expiresDate,
                        'launchUrl' => $userHomepage,
                    ],
                    [
                        'type' => 'TEXT',
                        'title' => 'Connections',
                        'text' => "$connections",
                        'launchUrl' => $userHomepage,
                    ],
                    [
                        'type' => 'TEXT',
                        'title' => 'Reward Pot Value',
                        'text' => "£{$pot}",
                        'launchUrl' => $userHomepage,
                    ],
                ],
            ];
        }

        return [];
    }
}
