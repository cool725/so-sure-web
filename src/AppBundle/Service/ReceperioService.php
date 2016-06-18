<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;
use AppBundle\Document\Charge;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;

class ReceperioService extends BaseImeiService
{
    // 1 day
    const DUEDILIGENCE_CACHE_TIME = 84600;
    const MAKEMODEL_CACHE_TIME = 84600;
    const CLAIMSCHECK_CACHE_TIME = 84600;
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

    public function getCertUrl($certId = null)
    {
        if ($certId == null) {
            $certId = $this->getCertId();
        }

        return sprintf("https://www.checkmend.com/uk/verify/%s", $certId);
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
     * @param User   $user
     *
     * @return boolean True if imei is ok
     */
    public function checkImei(Phone $phone, $imei, User $user = null)
    {
        \AppBundle\Classes\NoOp::noOp([$phone]);
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            return true;
        }

        try {
            $key = sprintf("receperio:duediligence:%s", $imei);
            if (($redisData = $this->redis->get($key)) != null) {
                $data = unserialize($redisData);
                $this->logger->info(sprintf("Cached DueDiligence search for %s -> %s", $imei, json_encode($data)));
            } else {
                $url = sprintf("/duediligence/%s/%s", $this->storeId, $imei);
                $response = $this->send($url, [
                    'category' => 1,
                ]);
                $this->logger->info(sprintf("DueDiligence search for %s -> %s", $imei, $response));

                $data = json_decode($response, true);
                $this->redis->setex($key, self::DUEDILIGENCE_CACHE_TIME, serialize($data));

                $now = new \DateTime();
                $logKey = sprintf(
                    'receperio:duediligence:%s:%s:%s',
                    $this->storeId,
                    $now->format('Y'),
                    $now->format('d')
                );
                $this->redis->zincrby($logKey, 1, $imei);

                $charge = new Charge();
                $charge->setType(Charge::TYPE_GSMA);
                $charge->setUser($user);
                $charge->setDetails($imei);
                $this->dm->persist($charge);
                $this->dm->flush();
            }
            $this->responseData = $data;
            $this->certId = $data['certid'];

            return $data['result'] == 'passed';
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(sprintf("Unable to check imei %s Ex: %s", $imei, $e->getMessage()));

            // For now, if there are any issues, assume true and run a manual retry later
            return true;
        }
    }

    public function policyClaim(PhonePolicy $policy, $claimType)
    {
        $result = null;
        if ($claimType == Claim::TYPE_DAMAGE) {
            $result = $this->checkImei($policy->getPhone(), $policy->getImei(), $policy->getUser());
        } elseif (in_array($claimType, [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            $result = $this->checkClaims($policy->getPhone(), $policy->getImei(), $policy);
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown claim type %s', $claimType));
        }

        $now = new \DateTime();
        $policy->addCheckmendCerts($now->format('U'), $this->getCertId());
        $this->dm->flush();

        return $result;
    }

    /**
     * Claimscheck
     *
     * @param Phone       $phone
     * @param string      $imei
     * @param PhonePolicy $policy
     *
     * @return boolean True if imei is ok
     */
    public function checkClaims(Phone $phone, $imei, PhonePolicy $policy = null)
    {
        \AppBundle\Classes\NoOp::noOp([$phone]);
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            return true;
        }

        try {
            $key = sprintf("receperio:claimscheck:%s", $imei);
            if (($redisData = $this->redis->get($key)) != null) {
                $data = unserialize($redisData);
                $this->logger->info(sprintf("Cached Claimscheck search for %s -> %s", $imei, json_encode($data)));
            } else {
                $response = $this->send("/claimscheck/search", [
                    'serial' => $imei,
                    'storeid' => $this->storeId,
                ]);
                $this->logger->info(sprintf("Claimscheck search for %s -> %s", $imei, $response));

                $data = json_decode($response, true);
                $this->redis->setex($key, self::CLAIMSCHECK_CACHE_TIME, serialize($data));

                $now = new \DateTime();
                $logKey = sprintf(
                    'receperio:claimscheck:%s:%s:%s',
                    $this->storeId,
                    $now->format('Y'),
                    $now->format('d')
                );
                $this->redis->zincrby($logKey, 1, $imei);

                $charge = new Charge();
                $charge->setType(Charge::TYPE_CLAIMSCHECK);
                $charge->setPolicy($policy);
                if ($policy) {
                    $charge->setUser($policy->getUser());
                }
                $charge->setDetails($imei);
                $this->dm->persist($charge);
                $this->dm->flush();
            }
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
     * @param User   $user
     *
     * @return boolean True if imei is ok
     */
    public function checkSerial(Phone $phone, $serialNumber, User $user = null)
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
            $key = sprintf("receperio:makemodel:%s", $serialNumber);
            if (($redisData = $this->redis->get($key)) != null) {
                $data = unserialize($redisData);
                $this->logger->info(sprintf(
                    "Cached Make/Model verification for %s -> %s",
                    $serialNumber,
                    json_encode($data)
                ));
            } else {
                $response = $this->send("/makemodelext", [
                    'serials' => [$serialNumber],
                    'storeid' => $this->storeId,
                    'category' => 1,
                ]);
                $this->logger->info(sprintf(
                    "Make/Model verification for %s -> %s",
                    $serialNumber,
                    $response
                ));
                $data = json_decode($response, true);
                $this->redis->setex($key, self::MAKEMODEL_CACHE_TIME, serialize($data));

                $now = new \DateTime();
                $logKey = sprintf('receperio:makemodel:%s:%s:%s', $this->storeId, $now->format('Y'), $now->format('d'));
                $this->redis->zincrby($logKey, 1, $serialNumber);

                $charge = new Charge();
                $charge->setType(Charge::TYPE_MAKEMODEL);
                $charge->setUser($user);
                $charge->setDetails($serialNumber);
                $this->dm->persist($charge);
                $this->dm->flush();
            }
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
                json_encode($data)
            ));
        }
        $makeData = $data['makes'][0];
        $make = strtolower($makeData['make']);

        if (!isset($makeData['models']) || count($makeData['models']) != 1) {
            throw new \Exception(sprintf(
                "Unable to check serial number (multiple models) %s. Data: %s",
                $serialNumber,
                json_encode($data)
            ));
        }
        $modelData = $makeData['models'][0];
        $model = $modelData['name'];

        // Non applce devices rely on on the modelreference special mapping
        // we give reciperio and it really just a make/model check
        if ($make != 'apple') {
            if (isset($modelData['modelreference']) && $modelData['modelreference']) {
                $device = $modelData['modelreference'];
                if (!in_array(strtolower($device), $phone->getDevices())) {
                    throw new \Exception(sprintf(
                        "Mismatching make %s for serial number %s. Data: %s",
                        $phone->getMake(),
                        $serialNumber,
                        json_encode($data)
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
                json_encode($data)
            ));
        }

        if (!isset($modelData['storage']) || !$modelData['storage']) {
            throw new \Exception(sprintf(
                "Unable to check serial number (missing storage) %s. Data: %s",
                $serialNumber,
                json_encode($data)
            ));
        } elseif ($modelData['storage'] != sprintf('%sGB', $phone->getMemory())) {
            $this->logger->error(sprintf(
                "Error validating check serial number %s for memory %s. Data: %s",
                $serialNumber,
                $phone->getMemory(),
                json_encode($data)
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
