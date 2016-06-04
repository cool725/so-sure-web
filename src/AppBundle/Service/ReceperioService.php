<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;

class ReceperioService extends BaseImeiService
{
    const TEST_INVALID_IMEI = "352000067704506";
    const PARTNER_ID = 415;
    const BASE_URL = "http://gapi.checkmend.com";

    /** @var string */
    protected $secretKey;

    /** @var string */
    protected $storeId;

    /** @var string */
    protected $certId;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $responseData;

    /**
     * @param string $environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    public function getEnvironment()
    {
        return $this->environment;
    }

    /**
     * @param string $secretKey
     */
    public function setSecretKey($secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * @param string $storeId
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
    }

    public function getCertId()
    {
        return $this->certId;
    }

    public function getResponseData()
    {
        return $this->responseData;
    }

    /**
     * Checks imei against a blacklist
     *
     * @param Phone  $phone
     * @param string $imei
     *
     * @return boolean True if imei is ok
     */
    public function checkImei(Phone $phone, $imei)
    {
        // TODO: Cache

        \AppBundle\Classes\NoOp::noOp([$phone]);
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            return true;
        }

        try {
            $response = $this->send("/claimscheck/search", [
                'serial' => $imei,
                'storeid' => $this->storeId,
            ]);
            $this->logger->info(sprintf("Claimscheck search for %s -> %s", $imei, print_r($response, true)));
            $data = json_decode($response, true);
            $this->responseData = $data;
            $this->certId = $data['checkmendstatus']['certid'];

            return $data['checkmendstatus']['result'] == 'green';
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(sprintf("Unable to check imei %s Ex: %s", $imei, $e->getMessage()));

            // For now, if there are any issues, assume true and run a manual retry later
            return true;
        }
    }

    /**
     * Validate that the serial number matches the expected phone details
     *
     * @param Phone  $phone
     * @param string $serialNumber
     *
     * @return boolean True if imei is ok
     */
    public function checkSerial(Phone $phone, $serialNumber)
    {
        // TODO: Cache

        \AppBundle\Classes\NoOp::noOp([$phone]);
        if ($serialNumber == "111111") {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            return true;
        }

        try {
            $response = $this->send("/makemodelext", [
                'serials' => [$serialNumber],
                'storeid' => $this->storeId,
            ]);
            $this->logger->info(sprintf(
                "Claimscheck serial verification for %s -> %s",
                $serialNumber,
                print_r($response, true)
            ));
            $data = json_decode($response, true);
            $this->responseData = $data;

            return $this->validateSamePhone($phone, $serialNumber, $data);
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(sprintf("Unable to check serial number %s Ex: %s", $serialNumber, $e->getMessage()));

            // For now, if there are any issues, assume true and run a manual retry later
            return true;
        }
    }

    public function validateSamePhone(Phone $phone, $serialNumber, $data)
    {
        if (!isset($data['makes']) || count($data['makes']) != 1) {
            throw new \Exception(sprintf(
                "Unable to check serial number (multiple makes) %s. Data: %s",
                $serialNumber,
                print_r($data, true)
            ));
        }
        $makeData = $data['makes'][0];
        $make = strtolower($makeData['make']);

        if (!isset($makeData['models']) || count($makeData['models']) != 1) {
            throw new \Exception(sprintf(
                "Unable to check serial number (multiple models) %s. Data: %s",
                $serialNumber,
                print_r($data, true)
            ));
        }
        $modelData = $makeData['models'][0];
        $model = $modelData['name'];

        // Non applce devices rely on on the modelreference special mapping
        // we give reciperio and it really just a make/model check
        if ($model != 'apple') {
            if (isset($modelData['modelreference']) && $modelData['modelreference']) {
                $device = $modelData['modelreference'];
                if (!in_array(strtolower($device), $phone->getDevices())) {
                    throw new \Exception(sprintf(
                        "Mismatching make %s for serial number %s. Data: %s",
                        $phone->getMake(),
                        $serialNumber,
                        print_r($data, true)
                    ));
                }
            } else {
                $this->logger->warning(sprintf('Need to contact reciperio to add modelreference for %s', $phone));
            }

            return true;
        }

        if (strtolower($model) != strtolower($phone->getModel())) {
            throw new \Exception(sprintf(
                "Mismatching model %s for serial number %s. Data: %s",
                $phone->getModel(),
                $serialNumber,
                print_r($data, true)
            ));
        }

        if (!isset($modelData['storage']) || !$modelData['storage']) {
            throw new \Exception(sprintf(
                "Unable to check serial number (missing storage) %s. Data: %s",
                $serialNumber,
                print_r($data, true)
            ));
        } elseif ($modelData['storage'] != sprintf('%sGB', $phone->getMemory())) {
            $this->logger->error(sprintf(
                "Error validating check serial number %s for memory %s. Data: %s",
                $serialNumber,
                $phone->getMemory(),
                print_r($data, true)
            ));
        }

        return true;
    }

    protected function send($url, $data)
    {
        try {
            $body = json_encode($data);
            $client = new Client();
            $url = sprintf("%s%s", self::BASE_URL, $url);
            $res = $client->request('POST', $url, [
                'json' => $data,
                'auth' => [self::PARTNER_ID, $this->sign($body)],
                'headers' => ['Accept' => 'application/json'],
            ]);
            $body = (string) $res->getBody();
            //print_r($body);

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in checkImei: %s', $e->getMessage()));
        }
    }
    
    protected function sign($body)
    {
        return sha1(sprintf("%s%s", $this->secretKey, $body));
    }
}
