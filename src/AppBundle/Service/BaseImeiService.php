<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;
use AppBundle\Document\LostPhone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use Doctrine\ODM\MongoDB\DocumentManager;

class BaseImeiService
{
    use \AppBundle\Document\ImeiTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $redis;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param DocumentManager $dm
     */
    public function setDm($dm)
    {
        $this->dm = $dm;
    }

    public function setRedis($redis)
    {
        $this->redis = $redis;
    }

    /**
     * Check if imei has be registered as lost
     *
     * @param string $imei
     *
     * @return boolean
     */
    public function isLostImei($imei)
    {
        $repo = $this->dm->getRepository(LostPhone::class);
        $phones = $repo->findBy(['imei' => (string) $imei]);

        return count($phones) > 0;
    }

    /**
     * Check if imei is already assigned to another policy
     *
     * @param string $imei
     *
     * @return boolean
     */
    public function isDuplicatePolicyImei($imei)
    {
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findDuplicateImei($imei);

        foreach ($policies as $policy) {
            // Expired policies can be paid for again
            if ($policy->isExpired()) {
                continue;
            }

            // Unrenewed policies can be paid for again
            if ($policy->isUnrenewed()) {
                continue;
            }

            // Cancelled policies that are not policy declined can be paid for again
            if ($policy->isCancelled() && !$policy->isCancelledWithPolicyDeclined()) {
                continue;
            }

            // TODO: may want to allow a new policy if within 1 month of expiration and same user
            // TODO: consider if we want to allow an unpaid policy on a different user?
            return true;
        }

        return false;
    }

    /**
     * @param string $imei
     *
     * @return boolean
     */
    public function isImei($imei)
    {
        return $this->isLuhn($imei) && strlen($imei) == 15;
    }

    /**
     * @see http://stackoverflow.com/questions/4741580/imei-validation-function
     * @param string $n
     *
     * @return boolean
     */
    protected function isLuhn($n)
    {
        $str = '';
        foreach (str_split(strrev((string) $n)) as $i => $d) {
            $str .= $i %2 !== 0 ? $d * 2 : $d;
        }
        return array_sum(str_split($str)) % 10 === 0;
    }

    public function ocr($filename, $make)
    {
        $results = $this->ocrRaw($filename);

        return $this->parseOcr($results, $make);
    }

    private function findSerialNumberByLinePosition($results, $imei)
    {
        // for non-english language settings
        // find imei line (will always be IMEI regardless of language)
        // and go up 3 lines
        $imeiLine = 0;
        $lines = preg_split("/\\r\\n|\\r|\\n/", $results);
        foreach ($lines as $line) {
            $line = str_replace(' ', '', $line);
            if (stripos($line, $imei) !== false) {
                break;
            }
            $imeiLine++;
        }
        $serialLine = $lines[$imeiLine - 3];
        $serialLineData = explode(' ', $serialLine);
        foreach ($serialLineData as $serialNumber) {
            if (preg_match('/[A-Z0-9]{12}/', $serialNumber)) {
                break;
            } else {
                $serialNumber = null;
            }
        }

        return $serialNumber;
    }

    public function parseOcr($results, $make)
    {
        $noSpace = str_replace(' ', '', $results);
        
        if ($make == "Apple") {
            if (preg_match('/SerialNumber([A-Z0-9]+).*(IMEI|lMEI)(\d{15})/s', $noSpace, $matches)) {
                // Expected case
                return ['imei' => $matches[3], 'serialNumber' => $matches[1]];
            } elseif (preg_match('/(IMEI|lMEI)(\d{15})/', $noSpace, $matches)) {
                // Expected case if non-english language (serial number copy is different)
                $serialNumber = $this->findSerialNumberByLinePosition($results, $matches[2]);

                return ['imei' => $matches[2], 'serialNumber' => $serialNumber];
            } elseif (preg_match('/SerialNumber([A-Z0-9]+).*(IMEI|lMEI)(\d{14})A/s', $noSpace, $matches)) {
                // 14 digit IMEI followed by A
                return ['imei' => $this->luhnGenerate($matches[3]), 'serialNumber' => $matches[1]];
            } elseif (preg_match('/(IMEI|lMEI)(\d{14})A/', $noSpace, $matches)) {
                // 14 digit IMEI followed by A with non-english language (serial number copy is different)
                $serialNumber = $this->findSerialNumberByLinePosition($results, $matches[2]);

                return ['imei' => $this->luhnGenerate($matches[2]), 'serialNumber' => $serialNumber];
            } elseif (preg_match('/(\d{15})/', $noSpace, $matches)) {
                // might be a screenshot of *#06# rather than settings
                if ($this->isImei($matches[1])) {
                    return ['imei' => $matches[1], 'serialNumber' => null];
                }
            }
        } else {
            if (preg_match('/(\d{15})/', $noSpace, $matches)) {
                if ($this->isImei($matches[1])) {
                    return ['imei' => $matches[1]];
                }
            }
        }

        return null;
    }

    public function ocrRaw($filename)
    {
        $ocr = new \TesseractOCR($filename);
        $results = $ocr
            ->psm(6) // singleBlock
            ->lang('eng')
            ->config('tessedit_ocr_engine_mode', '2') // tesseractCubeCombined
            ->run();

        return $results;
    }
}
