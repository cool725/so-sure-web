<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha256;

class JWTService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $secret;

    /** @var Sha256 */
    protected $signer;

    /**
     * @param LoggerInterface $logger
     * @param string          $secret
     */
    public function __construct(LoggerInterface $logger, $secret)
    {
        $this->logger = $logger;
        $this->signer = new Sha256();

        $this->setSecret($secret);
    }

    public function setSecret($secret, $transform = true)
    {
        if ($transform) {
            $this->secret = $this->base64UrlEncode($this->transformSecret($secret));
        } else {
            $this->secret = $secret;
        }
    }

    protected function transformSecret($secret)
    {
        return sha1($secret);
    }
    
    protected function base64UrlEncode($data) {
      return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function validate($cognitoId, $jwt, $additionalValidations = null)
    {
        $token = (new Parser())->parse((string) $jwt);
        if (!$token->verify($this->signer, $this->secret)) {
            $this->logger->error(sprintf('Failed to validate %s', $jwt));

            throw new \InvalidArgumentException("JWT signature is invalid");
        }

        if ($token->getClaim('cognitoId') != $cognitoId) {
            $this->logger->error(sprintf(
                'Failed to validate cognito Id %s %s',
                $cognitoId,
                print_r($token->getClaims(), true)
            ));

            throw new \InvalidArgumentException("JWT Token cognito id does not match");
        }

        if ($additionalValidations) {
            foreach ($additionalValidations as $key => $value) {
                if ($token->getClaim($key) != $value) {
                    $this->logger->error(sprintf(
                        'Failed to validate data %s => %s %s',
                        $key,
                        $value,
                        print_r($token->getClaims(), true)
                    ));

                    throw new \InvalidArgumentException(sprintf("JWT Token %s does not match", $key));
                }
            }
        }

        return $token->getClaims();
    }

    public function create($cognitoId, $data)
    {
        $token = (new Builder())
            ->set('cognitoId', $cognitoId);
        
        foreach ($data as $key => $value) {
            $token->set($key, $value);
        }

        return (string) $token->sign($this->signer, $this->secret)
            ->getToken();
    }
}
