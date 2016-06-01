<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Classes\Salva;

class SalvaExportService
{
    const SCHEMA_POLICY_IMPORT = 'policy/import/policyImportV1.xsd';

    /** @var DocumentManager */
    protected $dm;

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
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $baseUrl
     * @param string          $username
     * @param string          $password
     * @param string          $rootDir
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $baseUrl,
        $username,
        $password,
        $rootDir
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->rootDir = $rootDir;
    }

    public function export(Policy $policy)
    {
    }

    public function transformPolicy(PhonePolicy $policy = null)
    {
        if ($policy) {
            if (!$policy->getNumberOfInstallments()) {
                throw new \Exception('Invalid policy payment');
            }
            $data = [
                $policy->getPolicyNumber(),
                $policy->getStatus(),
                $policy->getStart()->format(\DateTime::ISO8601),
                $policy->getEnd()->format(\DateTime::ISO8601),
                $policy->getUser()->getId(),
                $policy->getUser()->getFirstName(),
                $policy->getUser()->getLastName(),
                $policy->getPhone()->getMake(),
                $policy->getPhone()->getModel(),
                $policy->getImei(),
                $policy->getPhone()->getInitialPrice(),
                $policy->getNumberOfInstallments(),
                $policy->getInstallmentAmount(),
                $policy->getPremium()->getYearlyPremiumPrice(),
                $policy->getPremiumPaid(),
                $policy->getPremium()->getTotalIpt(),
                Salva::YEARLY_BROKER_FEE,
                $policy->getBrokerFeePaid(),
                count($policy->getConnections()),
                $policy->getPotValue(),
            ];
        } else {
            $data = [
                'PolicyNumber',
                'Status',
                'InceptionDate',
                'EndDate',
                'CustomerId',
                'FirstName',
                'LastName',
                'Make',
                'Model',
                'Imei',
                'EstimatedPhonePrice',
                'NumberInstallments',
                'InstallmentAmount',
                'TotalPremium',
                'PaidPremium',
                'TotalIpt',
                'TotalBrokerFee',
                'PaidBrokerFee',
                'NumberConnections',
                'PotValue',
            ];
        }
        
        return implode(',', $data);
    }

    public function exportPolicies()
    {
        $repo = $this->dm->getRepository(PhonePolicy::class);
        print sprintf("%s\n", $this->transformPolicy(null));
        foreach ($repo->getAllPoliciesForExport() as $policy) {
            print sprintf("%s\n", $this->transformPolicy($policy));
        }
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
            //print_r($body);

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
