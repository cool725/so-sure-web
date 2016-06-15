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

    const S3_BUCKET = 'salva.so-sure.com';

    const CANCELLED_REPLACE = 'new_cover_to_be_issued';
    const CANCELLED_UNPAID = 'debt';
    const CANCELLED_FRAUD = 'annulment';
    const CANCELLED_GOODWILL = 'withdrawal_client';
    const CANCELLED_COOLOFF = 'withdrawal';
    const CANCELLED_BADRISK = 'claim';
    const CANCELLED_OTHER = 'other';

    const KEY_POLICY_ACTION = 'salva:policyid:action';

    const QUEUE_CREATED = 'created';
    const QUEUE_UPDATED = 'updated';
    const QUEUE_CANCELLED = 'cancelled';

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

    protected $redis;
    protected $s3;
    protected $environment;

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $baseUrl
     * @param string          $username
     * @param string          $password
     * @param string          $rootDir
     * @param                 $redis
     * @param                 $s3
     * @param                 $environment
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $baseUrl,
        $username,
        $password,
        $rootDir,
        $redis,
        $s3,
        $environment
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->baseUrl = $baseUrl;
        $this->username = $username;
        $this->password = $password;
        $this->rootDir = $rootDir;
        $this->redis = $redis;
        $this->s3 = $s3;
        $this->environment = $environment;
    }

    public function transformPolicy(PhonePolicy $policy = null, $version = null)
    {
        if ($policy) {
            if (!$policy->getNumberOfInstallments()) {
                throw new \Exception('Invalid policy payment');
            }
            if ($version) {
                $payments = $policy->getPaymentsForSalvaVersions()[$version];

                $status = PhonePolicy::STATUS_CANCELLED;
                $totalPremium = $policy->getTotalPremiumPrice($payments);
                $premiumPaid = $policy->getPremiumPaid($payments);
                $totalIpt = $policy->getTotalIpt($payments);
                $totalBroker = $policy->getTotalBrokerFee($payments);
                $brokerPaid = $policy->getBrokerFeePaid($payments);
                $connections = 0;
                $potValue = 0;
                $promoPotValue = 0;
                $terminationDate = $policy->getSalvaTerminationDate($version) ?
                    $policy->getSalvaTerminationDate($version) :
                    null;
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
                $promoPotValue = $policy->getPromoPotValue();
                $terminationDate = $policy->getStatus() == PhonePolicy::STATUS_CANCELLED ?
                    $policy->getEnd():
                    null;
            }

            $data = [
                $policy->getSalvaPolicyNumber($version),
                $status,
                $this->adjustDate($policy->getSalvaStartDate($version)),
                $this->adjustDate($policy->getStaticEnd()),
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
                $promoPotValue
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
                'MarkertingPotValue',
            ];
        }

        return sprintf('"%s"', implode('","', $data));
    }

    public function exportPolicies($s3, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $lines[] = sprintf("%s\n", $this->transformPolicy(null));
        foreach ($repo->getAllPoliciesForExport($date, $this->environment) as $policy) {
            foreach ($policy->getSalvaPolicyNumbers() as $version => $versionDate) {
                $lines[] =  sprintf("%s\n", $this->transformPolicy($policy, $version));
            }
            $lines[] =  sprintf("%s\n", $this->transformPolicy($policy));
        }

        if ($s3) {
            $filename = sprintf('policies-export-%d-%02d.csv', $date->format('Y'), $date->format('m'));
            $this->uploadS3($lines, $filename, 'policies', $date->format('Y'));
        }

        return $lines;
    }

    public function exportPayments($s3, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        $repo = $this->dm->getRepository(JudoPayment::class);
        $lines[] = sprintf("%s\n", $this->transformPayment(null));
        foreach ($repo->getAllPaymentsForExport($date) as $payment) {
            $lines[] = sprintf("%s\n", $this->transformPayment($payment));
        }

        if ($s3) {
            $filename = sprintf('payments-export-%d-%02d.csv', $date->format('Y'), $date->format('m'));
            $this->uploadS3($lines, $filename, 'payments', $date->format('Y'));
        }

        return $lines;
    }

    public function exportClaims($s3, \DateTime $date = null, $days = null)
    {
        if (!$date) {
            $date = new \DateTime();
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
        }

        $lines = [];
        $repo = $this->dm->getRepository(Claim::class);
        $lines[] =  sprintf("%s\n", $this->transformClaim(null));
        foreach ($repo->getAllClaimsForExport($date, $days) as $claim) {
            $lines[] = sprintf("%s\n", $this->transformClaim($claim));
        }

        if ($s3) {
            $filename = sprintf(
                'claims-export-%d-%02d-%02d.csv',
                $date->format('Y'),
                $date->format('m'),
                $date->format('d')
            );
            $this->uploadS3($lines, $filename, 'claims', $date->format('Y'));
        }

        return $lines;
    }

    public function uploadS3($data, $filename, $type, $year)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);
        $s3Key = sprintf('%s/%s/%s/%s', $this->environment, $type, $year, $filename);

        $result = $this->s3->putObject(array(
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));

        unlink($tmpFile);
    }

    public function transformPayment(JudoPayment $payment = null)
    {
        if ($payment) {
            if (!$payment->isSuccess()) {
                throw new \Exception('Invalid payment');
            }
            $policy = $payment->getPolicy();
            $data = [
                $policy->getSalvaPolicyNumberByDate($payment->getDate()),
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

    public function transformClaim(Claim $claim = null)
    {
        if ($claim) {
            $policy = $claim->getPolicy();
            $data = [
                $policy->getSalvaPolicyNumberByDate($claim->getRecordedDate()),
                $claim->getNumber(),
                $claim->getDaviesStatus(),
                $claim->getNotificationDate() ?
                    $this->adjustDate($claim->getNotificationDate()) :
                    '',
                $claim->getLossDate() ?
                    $this->adjustDate($claim->getLossDate()) :
                    '',
                $claim->getType(),
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
                'EventType',
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
        $phonePolicy->addSalvaPolicyResults($responseId, false);

        return $responseId;
    }

    public function cancelPolicy(PhonePolicy $phonePolicy, $reason = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
            $date->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));
            // Termination time can be a bit in the future without issue - match the 10 minutes of policy creation
            // $date->add(new \DateInterval('PT10M'));
        }

        // We should only bump the salva version if we're replacing a policy
        if ($reason && $reason == self::CANCELLED_REPLACE) {
            $phonePolicy->incrementSalvaPolicyNumber($date);
            $this->dm->flush();
        }

        if (!$reason) {
            if ($phonePolicy->getCancelledReason() == PhonePolicy::CANCELLED_UNPAID) {
                $reason = self::CANCELLED_UNPAID;
            } elseif ($phonePolicy->getCancelledReason() == PhonePolicy::CANCELLED_FRAUD) {
                $reason = self::CANCELLED_FRAUD;
            } elseif ($phonePolicy->getCancelledReason() == PhonePolicy::CANCELLED_GOODWILL) {
                $reason = self::CANCELLED_GOODWILL;
            } elseif ($phonePolicy->getCancelledReason() == PhonePolicy::CANCELLED_COOLOFF) {
                $reason = self::CANCELLED_COOLOFF;
            } elseif ($phonePolicy->getCancelledReason() == PhonePolicy::CANCELLED_BADRISK) {
                $reason = self::CANCELLED_BADRISK;
            } else {
                $reason = self::CANCELLED_OTHER;
            }
        }

        $xml = $this->cancelXml($phonePolicy, $reason, $date);
        $this->logger->info($xml);
        if (!$this->validate($xml, self::SCHEMA_POLICY_TERMINATE)) {
            throw new \Exception('Failed to validate cancel policy');
        }
        $response = $this->send($xml, self::SCHEMA_POLICY_TERMINATE);
        $this->logger->info($response);
        $responseId = $this->getResponseId($response);
        $phonePolicy->addSalvaPolicyResults($responseId, true);

        return $responseId;
    }

    public function updatePolicy(PhonePolicy $phonePolicy)
    {
        $this->cancelPolicy($phonePolicy, self::CANCELLED_REPLACE);
        $this->sendPolicy($phonePolicy);
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $policy = null;
            $action = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_POLICY_ACTION);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (!isset($data['policyId']) || !$data['policyId'] || !isset($data['action']) || !$data['action']) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $repo = $this->dm->getRepository(PhonePolicy::class);
                $policy = $repo->find($data['policyId']);
                $action = $data['action'];
                if (!$policy) {
                    throw new \Exception(sprintf('Unable to find policyId: %s', $data['policyId']));
                }

                if ($action == self::QUEUE_CREATED) {
                    $this->sendPolicy($policy);
                } elseif ($action == self::QUEUE_UPDATED) {
                    $this->updatePolicy($policy);
                } elseif ($action == self::QUEUE_CANCELLED) {
                    $this->cancelPolicy($policy);
                } else {
                    throw new \Exception(sprintf(
                        'Unknown action %s for policyId: %s',
                        $data['action'],
                        $data['policyId']
                    ));
                }
                $this->dm->flush();

                $count = $count + 1;
            } catch (\Exception $e) {
                if ($policy && $action) {
                    $queued = false;
                    if (isset($data['retryAttempts']) && $data['retryAttempts'] >= 0) {
                        // 20 minute attempts
                        if ($data['retryAttempts'] < 20) {
                            $this->queue($policy, $action, $data['retryAttempts'] + 1);
                            $queued = true;
                        }
                    } else {
                        $this->queue($policy, $action);
                        $queued = true;
                    }
                    $this->logger->error(sprintf(
                        'Error sending policy %s (%s) to salva (requeued: %s). Ex: %s',
                        $policy->getId(),
                        $action,
                        $queued ? 'Yes' : 'No',
                        $e->getMessage()
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'Error sending policy (Unknown) to salva (requeued). Ex: %s',
                        $e->getMessage()
                    ));
                }

                throw $e;
            }
        }
        
        return $count;
    }

    public function queue(PhonePolicy $policy, $action, $retryAttempts = 0)
    {
        if (!in_array($action, [self::QUEUE_CANCELLED, self::QUEUE_CREATED, self::QUEUE_UPDATED])) {
            throw new \Exception(sprintf('Unknown queue action %s', $action));
        }

        // For production, only process valid policies (e.g. not policies with @so-sure.com)
        if ($this->environment == "prod" && !$policy->isValidPolicy()) {
            return false;
        }

        $data = ['policyId' => $policy->getId(), 'action' => $action, 'retryAttempts' => $retryAttempts];
        $this->redis->rpush(self::KEY_POLICY_ACTION, serialize($data));

        return true;
    }

    public function clearQueue()
    {
        $this->redis->del(self::KEY_POLICY_ACTION);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_POLICY_ACTION, 0, $max);
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

        $elementList = $xpath->query('//ns7:serviceResponse/ns7:policies/ns2:policy/ns2:recordId');
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
        $clonedDate->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));

        return $clonedDate->format("Y-m-d\TH:i:00");
    }

    public function cancelXml(PhonePolicy $phonePolicy, $reason, $date)
    {
        if ($reason == self::CANCELLED_REPLACE) {
            // Make sure policy was incremented prior to calling
            $version = $phonePolicy->getLatestSalvaPolicyNumberVersion() - 1;
            if (!isset($phonePolicy->getPaymentsForSalvaVersions()[$version])) {
                throw new \Exception(sprintf(
                    'Missing version %s for salva. Was version incremented prior to cancellation?',
                    $version
                ));
            }
        } else {
            $version = $phonePolicy->getLatestSalvaPolicyNumberVersion();
        }

        $policyNumber = $phonePolicy->getSalvaPolicyNumber($version);
        if (isset($phonePolicy->getPaymentsForSalvaVersions()[$version])) {
            $payments = $phonePolicy->getPaymentsForSalvaVersions()[$version];
        } else {
            $payments = $phonePolicy->getPayments();
        }

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
        $root->appendChild($dom->createElement('n1:policyNo', $policyNumber));
        $root->appendChild($dom->createElement('n1:terminationReasonCode', $reason));
        $root->appendChild($dom->createElement(
            'n1:terminationTime',
            $this->adjustDate($date)
        ));

        $usedPremium = $phonePolicy->getTotalGwp($payments);

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
            $this->adjustDate($phonePolicy->getLatestSalvaStartDate())
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
        $policy->appendChild($dom->createElement('ns2:policyNo', $phonePolicy->getSalvaPolicyNumber()));

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

        $allPayments = $phonePolicy->getPaymentsForSalvaVersions(false);
        $tariff = $phonePolicy->getRemainingTotalGwp($allPayments);
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
        $client = new Client();
        $url = sprintf("%s/service/xmlService", $this->baseUrl);
        $res = $client->request('POST', $url, [
            'body' => $xml,
            'auth' => [$this->username, $this->password]
        ]);
        $body = (string) $res->getBody();

        if (!$this->validate($body, $schema)) {
            throw new \InvalidArgumentException("unable to validate response");
        }

        return $body;
    }
}
