<?php
namespace App\Normalizer;

use App\Oauth2Scopes;
use AppBundle\Document\User;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Summary of the Policy, suitable to display on a 3rd party site (to an authenticated user)
 */
class UserPolicySummary
{
    /** @var Serializer|SerializerInterface */
    private $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * return the summary suitable to show an authenticated user, or service
     */
    public function shortPolicySummary(User $user) //: array
    {
        return $this->serializer->normalize(
            $user,
            null,
            ['groups' => [Oauth2Scopes::USER_STARLING_SUMMARY, Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY]]
        );
    }
}
