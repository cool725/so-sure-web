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
    const FORCES_CACHE_TIME = 84600;
    const TEST_INVALID_IMEI = "352000067704506";
    const TEST_INVALID_SERIAL = "111111";
    const PARTNER_ID = 415;
    const BASE_URL = "http://gapi.checkmend.com";

    const KEY_RECEPERIO_QUEUE = "receperio:queue";
    const KEY_DUEDILIGENCE_FORMAT = "receperio:duediligence:%s";
    const KEY_CLAIMSCHECK_FORMAT = "receperio:claimscheck:%s";
    const KEY_MAKEMODEL_FORMAT = "receperio:makemodel:%s";
    const KEY_FORCES = "receperio:forces";

    const CHECK_IMEI = 'imei';
    const CHECK_CLAIMS = 'claims';
    const CHECK_SERIAL = 'serial';

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

    private function queueMessage($action, $phoneId, $userId = null, $imei = null, $serial = null, $phonePolicyId = null)
    {
        $data = ['action' => $action, 'phoneId' => $phoneId, 'userId' => $userId];
        if ($imei) {
            $data['imei'] = $imei;
        }
        if ($serial) {
            $data['serial'] = $serial;
        }
        if ($phonePolicyId) {
            $data['phonePolicyId'] = $phonePolicyId;
        }
        $this->redis->rpush(self::KEY_RECEPERIO_QUEUE, serialize($data));
    }

    public function clearQueue()
    {
        $this->redis->del(self::KEY_RECEPERIO_QUEUE);
    }

    public function getQueueSize()
    {
        return $this->redis->llen(self::KEY_RECEPERIO_QUEUE);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_RECEPERIO_QUEUE, 0, $max - 1);
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $policy = null;
            $action = null;
            $cancelReason = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_RECEPERIO_QUEUE);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (!isset($data['action']) || !isset($data['phoneId']) || !isset($data['userId'])) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $action = $data['action'];
                $user = null;
                $userRepo = $this->dm->getRepository(User::class);
                if ($data['userId']) {
                    $user = $userRepo->find($data['userId']);
                }
                $phoneRepo = $this->dm->getRepository(Phone::class);
                $phone = $phoneRepo->find($data['phoneId']);
                if (!$phone) {
                    throw new \Exception(sprintf('Unknown phone from queue %s', json_encode($data)));                    
                }

                if ($action == self::CHECK_IMEI) {
                    if (!isset($data['imei'])) {
                        throw new \Exception(sprintf('Missing imei from message in queue %s', json_encode($data)));
                    }
                    $this->reprocessImei($phone, $data['imei'], $user, $data);
                } elseif ($action == self::CHECK_SERIAL) {
                    if (!isset($data['serial'])) {
                        throw new \Exception(sprintf('Missing serial from message in queue %s', json_encode($data)));
                    }
                    $this->reprocessSerial($phone, $data['serial'], $user, $data);
                } else {
                    throw new \Exception(sprintf(
                        'Unknown action %s [%s]',
                        $data['action'],
                        json_encode($data)
                    ));
                }

                $count = $count + 1;
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error reprocessing receperio message [%s]',
                    json_encode($data)
                ), ['exeception' => $e]);

                throw $e;
            }
        }
        
        return $count;
    }

    private function reprocessImei($phone, $imei, $user, $data)
    {
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policy = $phonePolicyRepo->findOneBy(['imei' => $imei]);
        if (!$policy) {
            $this->logger->warning(sprintf(
                'Skipping imei recheck as policy has not been created for imei %s [%s]',
                $imei,
                json_encode($data)
            ));

            return;
        }

        if ($this->checkImei($phone, $imei, $user)) {
            $policy->addCheckmendCertData($this->getCertId(), $this->getResponseData());
            $this->dm->flush();
        } else {
            $this->logger->error(sprintf(
                'Imei %s failed validation and policy %s should be cancelled',
                $imei,
                $policy->getId()
            ));
        }
    }

    private function reprocessSerial($phone, $serialNumber, $user, $data)
    {
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policy = $phonePolicyRepo->findOneBy(['serialNumber' => $serialNumber]);
        if (!$policy) {
            $this->logger->warning(sprintf(
                'Missing serial recheck as policy has not been created for serial %s [%s]',
                $serial,
                json_encode($data)
            ));

            return;
        }

        if (!$this->checkSerial($phone, $serialNumber, $user)) {
            $this->logger->error(sprintf(
                'Serial %s failed validation and policy %s should be investigated (cancelled?)',
                $serialNumber,
                $policy->getId()
            ));
        }
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
            $key = sprintf(self::KEY_DUEDILIGENCE_FORMAT, $imei);
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

            // If there are any issues, assume true and manually process queue later
            $this->queueMessage(self::CHECK_IMEI, $phone->getId(), $user ? $user->getId() : null, $imei);

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

        $policy->addCheckmendCertData($this->getCertId(), $this->getResponseData());
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
            $key = sprintf(self::KEY_CLAIMSCHECK_FORMAT, $imei);
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
            $this->logger->error(sprintf("Unable to check claims for imei %s Ex: %s", $imei, $e->getMessage()));

            // Claims are currently a manual process and so would fail at point of triggering
            // not sure that we currently need a process to requeue it
            /*
            $this->queueMessage(
                self::CHECK_CLAIMS,
                $phone->getId(),
                $user ? $user->getId() : null,
                $imei,
                null,
                $policy->getId()
            );
            */

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
        \AppBundle\Classes\NoOp::noOp([$phone]);
        if ($serialNumber == self::TEST_INVALID_SERIAL) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            return true;
        }

        try {
            $key = sprintf(self::KEY_MAKEMODEL_FORMAT, $serialNumber);
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

            // If there are any issues, assume true and manually process queue later
            $this->queueMessage(self::CHECK_SERIAL, $phone->getId(), $user ? $user->getId() : null, null, $serialNumber);

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

    /**
     * Get a list of all uk police forces
     *
     * @return array
     */
    public function getForces()
    {
        try {
            $key = sprintf(self::KEY_FORCES);
            if (($redisData = $this->redis->get($key)) != null) {
                $data = unserialize($redisData);
                $this->logger->debug(sprintf("Cached Claimscheck Forces"));
            } else {
                $response = $this->send("/claimscheck/forces");
                $this->logger->debug(sprintf("Get Claimscheck Forces"));

                $data = json_decode($response, true);
                $forces = [];
                if (isset($data['forces'])) {
                    foreach ($data['forces'] as $force) {
                        $forces[$force['force']] = $force['forcename'];
                    }

                    $this->redis->setex($key, self::FORCES_CACHE_TIME, serialize($forces));
                }
                $data = $forces;
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('Unable to query claimscheck forces', ['exception' => $e]);

            throw $e;
        }
    }

    /**
     * @param string $force    Force value (see getForces)
     * @param string $crimeRef Crime Reference
     *
     * @return boolean
     */
    public function validateCrimeRef($force, $crimeRef)
    {
        try {
            $response = $this->send("/claimscheck/validatecrimeref", [
                'crimereference' => $crimeRef,
                'storeid' => $this->storeId,
                'force' => $force,
            ]);
            $this->logger->info(sprintf("ValidateCrimeRef (%s %s) -> %s", $force, $crimeRef, $response));

            $data = json_decode($response, true);
            $this->responseData = $data;

            return filter_var($data['matchesforce'], FILTER_VALIDATE_BOOLEAN);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf("Error in ValidateCrimeRef (%s %s)", $force, $crimeRef),
                ['exception' => $e]
            );

            throw $e;
        }
    }

    protected function send($url, $data = null)
    {
        try {
            $body = json_encode($data);
            $client = new Client();
            $url = sprintf("%s%s", self::BASE_URL, $url);
            if ($data) {
                $res = $client->request('POST', $url, [
                    'json' => $data,
                    'auth' => [self::PARTNER_ID, $this->sign($body)],
                    'headers' => ['Accept' => 'application/json'],
                ]);
            } else {
                $res = $client->request('GET', $url, [
                    'auth' => [self::PARTNER_ID, $this->sign('')],
                    'headers' => ['Accept' => 'application/json'],
                ]);
            }
            $body = (string) $res->getBody();

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in receperio request: %s', $url), ['exception' => $e]);
            throw $e;
        }
    }
    
    protected function sign($body)
    {
        return sha1(sprintf("%s%s", $this->secretKey, $body));
    }
}
