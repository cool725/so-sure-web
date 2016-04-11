<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;

class SalvaExportService
{
    const SCHEMA_POLICY_IMPORT = 'policy/import/policyImportV1.xsd';

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $rootDir;

    /**
     * @param LoggerInterface $logger
     * @param string          $baseUrl
     * @param string          $username
     * @param string          $password
     * @param string          $rootDir
     */
    public function __construct(LoggerInterface $logger, $baseUrl, $username, $password, $rootDir)
    {
        $this->logger = $logger;
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->rootDir = $rootDir;
    }

    public function export(Policy $policy)
    {
        
    }

    public function validate($xml, $schemaType)
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS); // Or load if filename required
        $schema = sprintf(
            "%s/../src/AppBundle/Resources/salva/service-schemas/%s",
            $this->rootDir,
            $schemaType
        );

        return $dom->schemaValidate($schema);
    }
    
    public function send($xml)
    {
        try {
            $client = new Client();
            $url = sprintf("%s/service/xmlService", $this->baseUrl);
            $res = $client->request('POST', $url, [
                'body' => $xml,
                'auth' => [$this->username, $this->password]
            ]);
            $body = (string) $res->getBody();
            print_r($body);

            if (!$this->validate($body, self::SCHEMA_POLICY_IMPORT)) {
                throw new \InvalidArgumentException("unable to validate response");
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in salva send: %s', $e->getMessage()));

            return false;
        }

        return true;
    }
}
