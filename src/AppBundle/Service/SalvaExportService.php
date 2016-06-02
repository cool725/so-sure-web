<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\JudoPayment;
use AppBundle\Classes\Salva;
use AppBundle\Document\CurrencyTrait;

class SalvaExportService
{
    use CurrencyTrait;

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
                $this->toTwoDp(Salva::YEARLY_BROKER_FEE),
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

    public function transformPayment(JudoPayment $payment = null)
    {
        if ($payment) {
            if (!$payment->isSuccess()) {
                throw new \Exception('Invalid payment');
            }
            $data = [
                $payment->getPolicy()->getPolicyNumber(),
                $payment->getDate()->format(\DateTime::ISO8601),
                $this->toTwoDp($payment->getAmount()),
            ];
        } else {
            $data = [
                'PolicyNumber',
                'PaymentDate',
                'PaymentAmount',
            ];
        }

        return implode(',', $data);
    }

    public function transformClaim(Claim $claim = null)
    {
        if ($claim) {
            $data = [
                $claim->getPolicy()->getPolicyNumber(),
                $claim->getNumber(),
                $claim->getDaviesStatus(),
                $claim->getNotificationDate() ?
                    $claim->getNotificationDate()->format(\DateTime::ISO8601) :
                    '',
                $claim->getLossDate() ?
                    $claim->getLossDate()->format(\DateTime::ISO8601) :
                    '',
                $claim->getDescription(),
                $this->toTwoDp($claim->getExcess()),
                $this->toTwoDp($claim->getReservedValue()),
                $claim->isOpen() ? '' : $this->toTwoDp($claim->getIncurred()),
                $claim->isOpen() ? '' : $this->toTwoDp($claim->getClaimHandlingFees()),
                $claim->getReplacementReceivedDate() ?
                    $claim->getReplacementReceivedDate()->format(\DateTime::ISO8601) :
                    '',
                $claim->getReplacementPhone() ?
                    $claim->getReplacementPhone()->getMake() :
                    '',
                $claim->getReplacementPhone() ?
                    $claim->getReplacementPhone()->getModel() :
                    '',
                $claim->getReplacementImei(),
            ];
        } else {
            $data = [
                'PolicyNumber',
                'ClaimNumber',
                'Status',
                'NotificationDate',
                'EventDate',
                'EventDescription',
                'Excess',
                'ReservedAmount',
                'CostOfClaim',
                'HandlingCost',
                'ReplacementDeliveryDate',
                'ReplacementMake',
                'ReplacementModel',
                'ReplacementImei',
            ];
        }

        return implode(',', $data);
    }

    public function exportPayments($year, $month)
    {
        $repo = $this->dm->getRepository(JudoPayment::class);
        print sprintf("%s\n", $this->transformPayment(null));
        foreach ($repo->getAllPaymentsForExport($year, $month) as $payment) {
            print sprintf("%s\n", $this->transformPayment($payment));
        }
    }

    public function exportClaims(\DateTime $date)
    {
        $repo = $this->dm->getRepository(Claim::class);
        print sprintf("%s\n", $this->transformClaim(null));
        foreach ($repo->getAllClaimsForExport($date) as $claim) {
            print sprintf("%s\n", $this->transformClaim($claim));
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
