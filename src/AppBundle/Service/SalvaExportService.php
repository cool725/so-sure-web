<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use DOMDocument;
use DOMXPath;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\JudoPayment;
use AppBundle\Classes\Salva;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\User;

class SalvaExportService
{
    use CurrencyTrait;

    const SCHEMA_POLICY_IMPORT = 'policy/import/policyImportV1.xsd';
    const SCHEMA_POLICY_TERMINATE = 'policy/termination/policyTerminationV1.xsd';

    const CANCELLED_REPLACE = 'new_cover_to_be_issued';
    const CANCELLED_UNPAID = 'debt';
    const CANCELLED_FRAUD = 'annulment';
    const CANCELLED_GOODWILL = 'withdrawal_client';
    const CANCELLED_COOLOFF = 'withdrawal';
    const CANCELLED_BADRISK = 'claim';

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

    public function transformPolicy(PhonePolicy $policy = null, $version = null)
    {
        if ($policy) {
            if (!$policy->getNumberOfInstallments()) {
                throw new \Exception('Invalid policy payment');
            }
            $terminationDate = null;
            if ($version) {
                $terminationDate = new \DateTime($policy->getSalvaPolicyNumbers()[$version]);
                $payments = $policy->getPaymentsForSalvaVersions()[$version];

                $status = PhonePolicy::STATUS_CANCELLED;
                $totalPremium = $policy->getTotalPremiumPrice($payments);
                $premiumPaid = $policy->getPremiumPaid($payments);
                $totalIpt = $policy->getTotalIpt($payments);
                $totalBroker = $policy->getTotalBrokerFee($payments);
                $brokerPaid = $policy->getBrokerFeePaid($payments);
                $connections = 0;
                $potValue = 0;
            } else {
                $allPayments = $policy->getPaymentsForSalvaVersions(false);

                $status = $policy->getStatus();
                $totalPremium = $policy->getRemainingTotalPremiumPrice($allPayments);
                $premiumPaid = $policy->getRemainingPremiumPaid($allPayments);
                $totalIpt = $policy->getRemainingTotalIpt($allPayments);
                $totalBroker = $policy->getRemainingTotalBrokerFee($allPayments);
                $brokerPaid = $policy->getRemainingBrokerFeePaid($allPayments);
                $connections = count($policy->getConnections());
                $potValue = $policy->getPotValue();
            }
            $data = [
                $policy->getSalvaPolicyNumber($version),
                $status,
                $this->adjustDate($policy->getStart()),
                $this->adjustDate($policy->getEnd()),
                $terminationDate ? $this->adjustDate($terminationDate) : '',
                $policy->getUser()->getId(),
                $policy->getUser()->getFirstName(),
                $policy->getUser()->getLastName(),
                $policy->getPhone()->getMake(),
                $policy->getPhone()->getModel(),
                $policy->getPhone()->getMemory(),
                $policy->getImei(),
                $policy->getPhone()->getInitialPrice(),
                $policy->getNumberOfInstallments(),
                $policy->getInstallmentAmount(),
                $totalPremium,
                $premiumPaid,
                $totalIpt,
                $totalBroker,
                $brokerPaid,
                $connections,
                $potValue,
            ];
        } else {
            $data = [
                'PolicyNumber',
                'Status',
                'InceptionDate',
                'EndDate',
                'TerminationDate',
                'CustomerId',
                'FirstName',
                'LastName',
                'Make',
                'Model',
                'Memory',
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
        
        return sprintf('"%s"', implode('","', $data));
    }

    public function exportPolicies()
    {
        $lines = [];
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $lines[] = sprintf("%s\n", $this->transformPolicy(null));
        foreach ($repo->getAllPoliciesForExport() as $policy) {
            foreach ($policy->getSalvaPolicyNumbers() as $version => $date) {
                $lines[] =  sprintf("%s\n", $this->transformPolicy($policy, $version));
            }
            $lines[] =  sprintf("%s\n", $this->transformPolicy($policy));
        }

        return $lines;
    }

    public function transformPayment(JudoPayment $payment = null, $version = null)
    {
        if ($payment) {
            if (!$payment->isSuccess()) {
                throw new \Exception('Invalid payment');
            }
            $data = [
                $payment->getPolicy()->getSalvaPolicyNumber($version),
                $this->adjustDate($payment->getDate()),
                $this->toTwoDp($payment->getAmount()),
            ];
        } else {
            $data = [
                'PolicyNumber',
                'PaymentDate',
                'PaymentAmount',
            ];
        }

        return sprintf('"%s"', implode('","', $data));
    }

    public function transformClaim(Claim $claim = null, $version = null)
    {
        if ($claim) {
            $data = [
                $claim->getPolicy()->getSalvaPolicyNumber($version),
                $claim->getNumber(),
                $claim->getDaviesStatus(),
                $claim->getNotificationDate() ?
                    $this->adjustDate($claim->getNotificationDate()) :
                    '',
                $claim->getLossDate() ?
                    $this->adjustDate($claim->getLossDate()) :
                    '',
                $claim->getDescription(),
                $this->toTwoDp($claim->getExcess()),
                $this->toTwoDp($claim->getReservedValue()),
                $claim->isOpen() ? '' : $this->toTwoDp($claim->getIncurred()),
                $claim->isOpen() ? '' : $this->toTwoDp($claim->getClaimHandlingFees()),
                $claim->getReplacementReceivedDate() ?
                    $this->adjustDate($claim->getReplacementReceivedDate()) :
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

        return sprintf('"%s"', implode('","', $data));
    }

    public function exportPayments($year, $month)
    {
        $lines = [];
        $repo = $this->dm->getRepository(JudoPayment::class);
        $lines[] = sprintf("%s\n", $this->transformPayment(null));
        foreach ($repo->getAllPaymentsForExport($year, $month) as $payment) {
            $lines[] = sprintf("%s\n", $this->transformPayment($payment));
        }

        return $lines;
    }

    public function exportClaims(\DateTime $date)
    {
        $lines = [];
        $repo = $this->dm->getRepository(Claim::class);
        $lines[] =  sprintf("%s\n", $this->transformClaim(null));
        foreach ($repo->getAllClaimsForExport($date) as $claim) {
            $lines[] = sprintf("%s\n", $this->transformClaim($claim));
        }

        return $lines;
    }

    public function sendPolicy(PhonePolicy $phonePolicy)
    {
        $xml = $this->createXml($phonePolicy);
        $this->logger->info($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_IMPORT)) {
            throw new \Exception('Failed to validate policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_IMPORT);
        $this->logger->info($response);
        $responseId = $this->getResponseId($response);

        return $responseId;
    }

    public function cancelPolicy(PhonePolicy $phonePolicy, $reason)
    {
        $xml = $this->cancelXml($phonePolicy, $reason);
        $this->logger->info($xml);
        print_r($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_TERMINATE)) {
            throw new \Exception('Failed to validate cancel policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_TERMINATE);
        print_r($response);
        $this->logger->info($response);
        $responseId = $this->getResponseId($response);

        return $responseId;
    }

    public function updatePolicy(PhonePolicy $phonePolicy)
    {
        $phonePolicy->incrementSalvaPolicyNumber();
        $this->dm->flush();

        $this->cancelPolicy($phonePolicy, self::CANCELLED_REPLACE);
        $this->sendPolicy($phonePolicy);
    }

    protected function getResponseId($xml)
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOBLANKS);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ns1', "http://sims.salva.ee/service/schema/v1");
        $xpath->registerNamespace('ns2', "http://sims.salva.ee/service/schema/policy/v1");
        $xpath->registerNamespace('ns3', "http://sims.salva.ee/service/schema/policy/export/v1");
        $xpath->registerNamespace('ns4', "http://sims.salva.ee/service/schema/policy/classifier/export/v1");
        $xpath->registerNamespace('ns5', "http://sims.salva.ee/service/schema/invoice/v1");
        $xpath->registerNamespace('ns6', "http://sims.salva.ee/service/schema/invoice/export/v1");
        $xpath->registerNamespace('ns7', "http://sims.salva.ee/service/schema/policy/termination/v1");
        $xpath->registerNamespace('ns8', "http://sims.salva.ee/service/schema/policy/import/v1");

        $elementList = $xpath->query('//ns8:serviceResponse/ns8:policies/ns2:policy/ns2:recordId');
        foreach ($elementList as $element) {
            return $element->nodeValue;
        }

        $elementList = $xpath->query('//ns1:errorResponse/ns1:errorList/ns1:constraint');
        foreach ($elementList as $element) {
            $errMsg = sprintf(
                "Error sending policy. Response: %s : %s",
                $element->getAttribute('ns1:code'),
                $element->nodeValue
            );
            $this->logger->error($errMsg);
        }

        throw new \Exception('Unable to get response');
    }

    public function adjustDate(\DateTime $date)
    {
        $clonedDate = clone $date;
        $clonedDate->setTimezone(new \DateTimeZone("UTC"));

        return $clonedDate->format("Y-m-d\TH:i:00");
    }

    public function cancelXml(PhonePolicy $phonePolicy, $reason)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $root = $dom->createElement('n1:serviceRequest');
        $dom->appendChild($root);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:n1',
            'http://sims.salva.ee/service/schema/policy/termination/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:n2',
            'http://sims.salva.ee/service/schema/v1'
        );
        $root->appendChild($dom->createElement('n1:policyNo', $phonePolicy->getPolicyNumber()));
        $root->appendChild($dom->createElement('n1:terminationReasonCode', $reason));
        $root->appendChild($dom->createElement(
            'n1:terminationTime',
            $this->adjustDate(new \DateTime())
        ));

        // Make sure policy is incremented prior to calling
        $version = $policy->getLatestSalvaPolicyNumberVersion() - 1;
        $payments = $policy->getPaymentsForSalvaVersions()[$version];
        $usedPremium = $phonePolicy->getTotalPremiumPrice($payments);

        $usedFinalPremium = $dom->createElement('n1:usedFinalPremium', $usedPremium);
        $usedFinalPremium->setAttribute('n2:currency', 'GBP');
        $root->appendChild($usedFinalPremium);

        return $dom->saveXML();
    }

    public function createXml(PhonePolicy $phonePolicy)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $root = $dom->createElement('ns3:serviceRequest');
        $dom->appendChild($root);
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns3',
            'http://sims.salva.ee/service/schema/policy/import/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns2',
            'http://sims.salva.ee/service/schema/policy/v1'
        );
        $root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:ns1',
            'http://sims.salva.ee/service/schema/v1'
        );
        $root->setAttribute('ns3:mode', 'policy');
        $root->setAttribute('ns3:includeInvoiceRows', 'true');

        $policy = $dom->createElement('ns3:policy');
        $root->appendChild($policy);

        $policy->appendChild($dom->createElement('ns2:renewable', 'false'));
        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodStart',
            $this->adjustDate($phonePolicy->getStart())
        ));

        $policy->appendChild($dom->createElement(
            'ns2:insurancePeriodEnd',
            $this->adjustDate($phonePolicy->getEnd())
        ));
        $policy->appendChild($dom->createElement(
            'ns2:paymentsPerYearCode',
            $phonePolicy->getNumberOfInstallments()
        ));
        $policy->appendChild($dom->createElement('ns2:issuerUser', 'so_sure'));
        $policy->appendChild($dom->createElement('ns2:deliveryModeCode', 'undefined'));
        $policy->appendChild($dom->createElement('ns2:policyNo', $phonePolicy->getPolicyNumber()));

        $policyCustomers = $dom->createElement('ns2:policyCustomers');
        $policy->appendChild($policyCustomers);
        $policyCustomers->appendChild($this->createCustomer($dom, $phonePolicy->getUser(), 'policyholder'));

        $insuredObjects = $dom->createElement('ns2:insuredObjects');
        $policy->appendChild($insuredObjects);
        $insuredObject = $dom->createElement('ns2:insuredObject');
        $insuredObjects->appendChild($insuredObject);
        $insuredObject->appendChild($dom->createElement('ns2:productObjectCode', 'ss_phone'));

        $objectCustomers = $dom->createElement('ns2:objectCustomers');
        $insuredObject->appendChild($objectCustomers);
        $objectCustomers->appendChild($this->createCustomer($dom, $phonePolicy->getUser(), 'insured_person'));

        $objectFields = $dom->createElement('ns2:objectFields');
        $insuredObject->appendChild($objectFields);
        $phone = $phonePolicy->getPhone();
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_make', $phone->getMake()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_model', $phone->getModel()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_memory', $phone->getMemory()));
        $objectFields->appendChild($this->createObjectFieldText($dom, 'ss_phone_imei', $phonePolicy->getImei()));
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_value', $phone->getInitialPrice()));
        $tariff = $phonePolicy->getPremium()->getYearlyGwp();
        $objectFields->appendChild($this->createObjectFieldMoney($dom, 'ss_phone_base_tariff', $tariff));

        return $dom->saveXML();
    }

    private function createObjectFieldText($dom, $code, $value)
    {
        $objectField = $dom->createElement('ns2:objectField');
        $objectField->setAttribute('ns2:fieldCode', $code);
        $objectField->setAttribute('ns2:fieldTypeCode', 'string');

        $textValue = $dom->createElement('ns2:textValue', $value);
        $objectField->appendChild($textValue);

        return $objectField;
    }

    private function createObjectFieldMoney($dom, $code, $value)
    {
        $objectField = $dom->createElement('ns2:objectField');
        $objectField->setAttribute('ns2:fieldCode', $code);
        $objectField->setAttribute('ns2:fieldTypeCode', 'money');

        $amountValue = $dom->createElement('ns2:amountValue', $value);
        $amountValue->setAttribute('ns1:currency', 'GBP');
        $objectField->appendChild($amountValue);

        return $objectField;
    }

    private function createCustomer($dom, User $user, $role)
    {
        $customer = $dom->createElement('ns2:customer');
        $customer->setAttribute('ns2:role', $role);

        $customer->appendChild($dom->createElement('ns2:code', $user->getId()));
        $customer->appendChild($dom->createElement('ns2:name', $user->getLastName()));
        $customer->appendChild($dom->createElement('ns2:firstName', $user->getFirstName()));
        $customer->appendChild($dom->createElement('ns2:countryCode', 'GB'));
        $customer->appendChild($dom->createElement('ns2:personTypeCode', 'private'));

        return $customer;
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
    
    public function send($xml, $schema)
    {
        try {
            $client = new Client();
            $url = sprintf("%s/service/xmlService", $this->baseUrl);
            $res = $client->request('POST', $url, [
                'body' => $xml,
                'auth' => [$this->username, $this->password]
            ]);
            $body = (string) $res->getBody();
            // print_r($body);

            if (!$this->validate($body, $schema)) {
                throw new \InvalidArgumentException("unable to validate response");
            }

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in salva send: %s', $e->getMessage()));

            throw $e;
        }
    }
}
