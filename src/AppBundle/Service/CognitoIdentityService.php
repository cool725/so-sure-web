<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use \GoCardlessPro\Environment;

class CognitoIdentityService
{
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $requestContent
     *
     * @return array|null
     */
    public function parseIdentity($requestContent)
    {
        $this->logger->warning(sprintf("Raw: %s", $requestContent));
        try {
            $data = json_decode($requestContent, true);

            $str = $data['identity'];
            $str = str_replace(',', '&', $str);
            $str = str_replace('{', '', $str);
            $str = str_replace('}', '', $str);
            $str = str_replace(' ', '', $str);
            parse_str($str, $identity);

            $this->logger->warning(sprintf("Data: %s", print_r($data, true)));
            $this->logger->warning(sprintf("Identity: %s", print_r($identity, true)));

            return $identity;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error processing identity: %s', $e->getMessage()));
        }

        return null;
    }
}
