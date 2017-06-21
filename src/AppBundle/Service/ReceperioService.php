<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;
use AppBundle\Document\Charge;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\IdentityLog;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\ReciperoManualProcessException;

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

    /** @var RateLimitService */
    protected $rateLimit;

    protected $mailer;

    protected $statsd;

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

    public function setRateLimit($rateLimit)
    {
        $this->rateLimit = $rateLimit;
    }

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function setStatsd($statsd)
    {
        $this->statsd = $statsd;
    }

    private function queueMessage(
        $action,
        $phoneId,
        $userId = null,
        $imei = null,
        $serial = null,
        $phonePolicyId = null
    ) {
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

    public function clearQueue($max = null)
    {
        if (!$max) {
            $this->redis->del(self::KEY_RECEPERIO_QUEUE);
        } else {
            for ($i = 0; $i < $max; $i++) {
                $this->redis->lpop(self::KEY_RECEPERIO_QUEUE);
            }
        }
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

                if (!isset($data['action']) || !isset($data['phoneId'])) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $action = $data['action'];
                $user = null;
                $userRepo = $this->dm->getRepository(User::class);
                if (isset($data['userId'])) {
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

    /**
     * Reprocess an imei check and record the checkmend against a policy with that imei
     *
     * @param Phone  $phone
     * @param string $imei
     * @param User   $user
     * @param        $data
     *
     * @return boolean|null null if unable to find policy, otherwise, checkImei result
     */
    public function reprocessImei(Phone $phone, $imei, User $user = null, $data = null)
    {
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        // TODO: check policy status
        $policy = $phonePolicyRepo->findOneBy(['imei' => $imei]);
        if (!$policy) {
            $this->logger->warning(sprintf(
                'Skipping imei recheck as policy has not been created for imei %s [%s]',
                $imei,
                json_encode($data)
            ));

            return null;
        }

        $result = $this->checkImei($phone, $imei, $user);
        $policy->addCheckmendCertData($this->getCertId(), $this->getResponseData());
        $this->dm->flush();
        if (!$result) {
            $this->logger->error(sprintf(
                'Imei %s failed validation and policy %s should be cancelled',
                $imei,
                $policy->getId()
            ));
        }

        return $result;
    }

    /**
     * Reprocess an serial check and record the checkmend against a policy with that serial
     *
     * @param Phone  $phone
     * @param string $serialNumber
     * @param User   $user
     * @param        $data
     *
     * @return boolean|null null if unable to find policy, otherwise, checkSerial result
     */
    public function reprocessSerial(Phone $phone, $serialNumber, User $user = null, $data = null)
    {
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        // TODO: check policy status
        $policy = $phonePolicyRepo->findOneBy(['serialNumber' => $serialNumber]);
        if (!$policy) {
            $this->logger->warning(sprintf(
                'Missing serial recheck as policy has not been created for serial %s [%s]',
                $serial,
                json_encode($data)
            ));

            return null;
        }

        $result = $this->checkSerial($phone, $serialNumber, null, $user);
        $policy->addCheckmendSerialData($this->getResponseData());
        $this->dm->flush();
        if (!$result) {
            $this->logger->error(sprintf(
                'Serial %s failed validation and policy %s should be investigated (cancelled?)',
                $serialNumber,
                $policy->getId()
            ));
        }

        return $result;
    }

    /**
     * Checks imei against a blacklist
     *
     * @param Phone       $phone
     * @param string      $imei
     * @param User        $user
     * @param IdentityLog $identityLog
     * @param Claim       $claim
     * @param User        $handler
     *
     * @return boolean True if imei is ok
     */
    public function checkImei(
        Phone $phone,
        $imei,
        User $user = null,
        IdentityLog $identityLog = null,
        Claim $claim = null,
        User $handler = null
    ) {
        \AppBundle\Classes\NoOp::ignore([$phone]);
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($identityLog && $identityLog->isSessionDataPresent()) {
            if (!$this->rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_IMEI,
                $identityLog->getIp(),
                $identityLog->getCognitoId()
            )) {
                throw new RateLimitException();
            }
        }

        if ($this->getEnvironment() != 'prod') {
            $this->responseData = 'imei';
            $this->certId = 'imei';

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
                if ($claim) {
                    $charge->setType(Charge::TYPE_CLAIMSDAMAGE);
                } else {
                    $charge->setType(Charge::TYPE_GSMA);
                }
                $charge->setUser($user);
                $charge->setDetails($imei);
                $charge->setHandler($handler);
                if ($claim) {
                    $claim->addCharge($charge);
                }
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

    /**
     * @param PhonePolicy $policy
     * @param string      $claimType Claim::TYPE_DAMAGE, Claim::TYPE_LOSS, Claim::TYPE_THEFT
     * @param Claim       $claim     Claim to record against
     * @param User        $handler   Optional: User who ran the check
     * @param string      $imei      Optional: Allow running against a previous imei
     */
    public function policyClaim(
        PhonePolicy $policy,
        $claimType,
        Claim $claim,
        User $handler = null,
        $imei = null,
        $register = true
    ) {
        if ($policy->getImeiReplacementDate() && $claim
            && $policy->getImeiReplacementDate() > $claim->getRecordedDate()) {
            // if passing in imei, then hopefully we know what we're doing, so allow it
            if (!$imei) {
                // @codingStandardsIgnoreStart
                throw new \Exception('Policy has been updated for the replacement imei from the claim. Claimscheck must be run by so-sure.');
                // @codingStandardsIgnoreEnd
            }
        }
        if (!$imei) {
            $imei = $policy->getImei();
        }

        $runClaimsCheck = null;
        if (in_array($claimType, [Claim::TYPE_DAMAGE, Claim::TYPE_EXTENDED_WARRANTY])) {
            $runClaimsCheck = false;
        } elseif (in_array($claimType, [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            $runClaimsCheck = true;
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown claim type %s', $claimType));
        }

        if ($register) {
            $this->registerClaims($policy, $claim, $imei, $runClaimsCheck);
        } else {
            $this->logger->warning(sprintf('Running claims without register for claim %s', $claim->getId()));
        }

        $result = null;
        if (!$runClaimsCheck) {
            $result = $this->checkImei(
                $policy->getPhone(),
                $imei,
                $policy->getUser(),
                null,
                $claim,
                $handler
            );
        } else {
            $result = $this->checkClaims($policy, $claim, $imei, $handler);
        }

        return $result;
    }

    /**
     * Register a phone
     *
     * @param PhonePolicy $policy
     * @param Claim       $claim
     * @param string      $imei
     * @param boolean     $settled
     *
     * @return boolean True if imei is ok
     */
    public function registerClaims(
        PhonePolicy $policy,
        Claim $claim,
        $imei,
        $settled
    ) {
        $claimType = $settled ? 'settled' : 'logged';

        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            $policy->addCheckmendRegisterData('transaction id', $imei, $claim, $claimType);
            $this->dm->flush();

            return true;
        }

        try {
            $phone = $policy->getPhone();
            $data = [
                'claimtype' => $claimType,
                'serials' => [$imei],
                'storeid' => $this->storeId,
                'itemcategory' => '1', // http://gapi.checkmend.com/docs/categories.php
                'make' => $phone->getMake(),
                'model' => $phone->getModel(),
                'itemdescription' => $phone->__toString(),
                'claimdate' => $claim->getRecordedDate()->format('Y-m-d H:i:s'),
                'claimreference' => $claim->getId(),
            ];
            $response = $this->send("/claimscheck/submitclaim", $data);
            //$response = '{"transactionid":"CLAIMSCHECK:CLAIM:6ADFFF53-7D92-4778-98E3-CB9844EF089B"}';
            $this->logger->info(sprintf("Claimscheck register for %s -> %s", $imei, $response));

            $data = json_decode($response, true);
            $this->responseData = $data;
            $this->certId = $data['transactionid'];
            $policy->addCheckmendRegisterData($this->getCertId(), $imei, $claim, $claimType);
            $this->dm->flush();

            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Unable to register claim for imei %s Ex: %s", $imei, $e->getMessage()));

            return false;
        }
    }

    /**
     * Claimscheck
     *
     * @param PhonePolicy $policy
     * @param Claim       $claim
     * @param string      $imei
     * @param User        $handler
     *
     * @return boolean True if imei is ok
     */
    public function checkClaims(
        PhonePolicy $policy,
        Claim $claim,
        $imei,
        User $handler = null
    ) {
        // gsma should return blacklisted for this imei.  to avoid cost for testing, hardcode to false
        if ($imei == self::TEST_INVALID_IMEI) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            $policy->addCheckmendCertData('claimscheck', 'n/a', $claim);
            $this->dm->flush();

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
                if ($claim) {
                    $claim->addCharge($charge);
                }
                $charge->setHandler($handler);
                $charge->setDetails($imei);
                $this->dm->persist($charge);
                $this->dm->flush();
            }
            $this->responseData = $data;
            $this->certId = $data['checkmendstatus']['certid'];
            $policy->addCheckmendCertData($this->getCertId(), $this->getResponseData(), $claim);
            $this->dm->flush();

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
     * @param Phone       $phone
     * @param string      $serialNumber
     * @param string      $imei
     * @param User        $user
     * @param IdentityLog $identityLog
     * @param boolean     $warnMismatch For web, we don't need to warn on mismatch as probably user issue
     *
     * @return boolean True if imei is ok
     */
    public function checkSerial(
        Phone $phone,
        $serialNumber,
        $imei,
        User $user = null,
        IdentityLog $identityLog = null,
        $warnMismatch = true
    ) {
        if ($identityLog && $identityLog->isSessionDataPresent()) {
            if (!$this->rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_SERIAL,
                $identityLog->getIp(),
                $identityLog->getCognitoId()
            )) {
                throw new RateLimitException();
            }
        }
        

        if (!$this->runMakeModelCheck($serialNumber, $user)) {
            return false;
        }

        try {
            return $this->runCheckSerial($phone, $serialNumber, $user, $warnMismatch);
        } catch(ReciperoManualProcessException $e) {
            // If apple serial number doesn't work, try imei to get a non-memory match
            if ($phone->getMake() == 'Apple' && $imei) {
                try {
                    return $this->runCheckSerial($phone, $imei, $user, $warnMismatch);
                } catch(ReciperoManualProcessException $e) {
                    return true;
                }
            }

            return true;
        }
    }

    private function runCheckSerial(
        Phone $phone,
        $serialNumber,
        User $user = null,
        $warnMismatch = true
    ) {
        if (!$this->runMakeModelCheck($serialNumber, $user)) {
            return false;
        }

        return $this->validateSamePhone($phone, $serialNumber, $this->responseData, $warnMismatch);
    }

    public function runMakeModelCheck(
        $serialNumber,
        User $user = null
    ) {
        if ($serialNumber == self::TEST_INVALID_SERIAL) {
            return false;
        }

        if ($this->getEnvironment() != 'prod') {
            $this->responseData = 'serial';

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
            $this->certId = null;
            $this->responseData = $data;

            return true;
        } catch (\Exception $e) {
            // TODO: automate a future retry check
            $this->logger->error(
                sprintf("Unable to check serial number '%s'", $serialNumber),
                ['exception' => $e]
            );

            // If there are any issues, assume true and manually process queue later
            $this->queueMessage(
                self::CHECK_SERIAL,
                $phone->getId(),
                $user ? $user->getId() : null,
                null,
                $serialNumber
            );

            return true;
        }
    }

    private function validateMakeModeResponseMakes($serialNumber, $data)
    {
        // This case occurs occasionally
        if (isset($data['makes']) && count($data['makes']) == 0) {
            // @codingStandardsIgnoreStart
            $this->mailer->send(
                sprintf('Empty Data Response for %s', $serialNumber),
                'tech@so-sure.com',
                sprintf("A recent make/model query for %s returned a successful response but without any data in the makes field. If apple, verify at https://checkcoverage.apple.com/gb/en/?sn=%s Email support@recipero.com\n\n--------------\n\nDear Recipero Support,\nA recent make/model query for %s returned a successful response but without any data present for the makes field. Can you please investigate and add to your db if its a valid serial number.  If it is a valid serial number, can you also confirm the make/model/colour & memory?", $serialNumber, $serialNumber, $serialNumber)
            );
            // @codingStandardsIgnoreEnd

            throw new ReciperoManualProcessException(sprintf('Missing make data %s', json_encode($data)));
        }

        if (!isset($data['makes']) || count($data['makes']) != 1) {
            throw new ReciperoManualProcessException(sprintf(
                "Unable to check serial number (no/multiple makes) %s. Data: %s",
                $serialNumber,
                json_encode($data)
            ));
        }
    }

    private function validateMakeModeResponseModels($serialNumber, $makeData, $data, $isApple)
    {
        if (!isset($makeData['models']) || count($makeData['models']) == 0) {
            throw new ReciperoManualProcessException(sprintf(
                "Contact reciperio - Unable to check serial number (no models) %s. Data: %s",
                $serialNumber,
                json_encode($data)
            ));
        }

        if (!$this->areSameModel($makeData['models'], $isApple)) {
            throw new ReciperoManualProcessException(sprintf(
                "Unable to check serial number (multiple models) %s. Data: %s",
                $serialNumber,
                json_encode($data)
            ));
        }
    }

    private function validateMakeModeResponseModel($serialNumber, $modelData, $data, $isApple)
    {
        if ($isApple) {
            // we can run make/model checks on apple. if its a serial number, then storage will match,
            // but if its an imei, then we will get all memory sizes for that model, so should be ignored
            if (!$this->isImei($serialNumber)) {
                if (!isset($modelData['storage']) || !$modelData['storage']) {
                    throw new ReciperoManualProcessException(sprintf(
                        "Unable to check serial number (missing storage) %s. Data: %s",
                        $serialNumber,
                        json_encode($data)
                    ));
                }
            }
        } else {
            if (!isset($modelData['modelreference']) || !$modelData['modelreference']) {
                // @codingStandardsIgnoreStart
                $this->mailer->send(
                    sprintf('Missing ModelReference for %s', $serialNumber),
                    'tech@so-sure.com',
                    sprintf("A recent make/model query for %s returned a successful response but the response was missing a modelreference.  Email support@recipero.com\n\n----------\n\nDear Recipero Support,\nCan you please add modelreference '%s' for the '%s' phone to our account?\n\nResponse returned by recipero: %s", $serialNumber, $phone->getDevices()[0], $phone, json_encode($data))
                );
                // @codingStandardsIgnoreEnd

                throw new ReciperoManualProcessException(sprintf(
                    'Missing modelreference data %s',
                    json_encode($data)
                ));
            }
        }
    }

    private function isSameNonApplePhone(Phone $phone, $serialNumber, $modelData, $data, $warnMismatch = true)
    {
        // Non apple devices rely on on the modelreference special mapping
        // we give reciperio and it really just a make/model check
        $device = $modelData['modelreference'];
        // Devices are cased as not sure what google will do in the future
        // but recipero usually upper cases modelreference
        if (in_array(strtoupper($device), $phone->getDevicesAsUpper())) {
            return true;
        } else {
            $this->statsd->increment('recipero.makeModelMismatch');
            $errMessage = sprintf(
                "Mismatching devices %s for serial number %s. Data: %s",
                json_encode($phone->getDevicesAsUpper()),
                $serialNumber,
                json_encode($data)
            );
            if ($warnMismatch) {
                $this->logger->warning($errMessage);
            } else {
                $this->logger->info($errMessage);
            }

            return false;
        }
    }

    public function isSameApplePhone(Phone $phone, $serialNumber, $modelData, $data, $warnMismatch = true)
    {
        if (strtolower($model) != strtolower($phone->getModel())) {
            $this->statsd->increment('recipero.makeModelMismatch');
            $errMessage = sprintf(
                "Mismatching model %s for serial number %s. Data: %s",
                $phone->getModel(),
                $serialNumber,
                json_encode($data)
            );
            if ($warnMismatch) {
                $this->logger->warning($errMessage);
            } else {
                $this->logger->info($errMessage);
            }

            return false;
        }

        // For Apple Imei, model check is all we can validate - memory check does not make sense
        if ($this->isImei($serialNumber)) {
            return true;
        }

        if ($modelData['storage'] == sprintf('%sGB', $phone->getMemory())) {
            return true;
        } else {
            $this->logger->error(sprintf(
                "Error validating check serial number %s for memory %s. Data: %s",
                $serialNumber,
                $phone->getMemory(),
                json_encode($data)
            ));

            return false;
        }
    }

    public function validateSamePhone(Phone $phone, $serialNumber, $data, $warnMismatch = true)
    {
        $this->validateMakeModelResponseMakes($serialNumber, $data);

        $makeData = $data['makes'][0];
        $make = strtolower($makeData['make']);
        $isApple = $make == 'apple';

        $this->validateMakeModeResponseModels($serialNumber, $makeData, $data, $isApple);

        $modelData = $makeData['models'][0];
        $model = $modelData['name'];

        $this->validateMakeModeResponseModel($serialNumber, $modelData, $data, $isApple);

        if ($isApple) {
            return $this->isSameApplePhone(
                $phone,
                $serialNumber,
                $modelData,
                $data,
                $warnMismatch
            );
        } else {
            return $this->isSameNonApplePhone(
                $phone,
                $serialNumber,
                $modelData,
                $data,
                $warnMismatch
            );
        }

    }

    public function areSameModel($models, $checkMemory)
    {
        // If there are multiple models with the same memory, its just differences in colours which can be ignored
        $model = null;
        $storage = null;
        foreach ($models as $modelData) {
            $loopStorage = isset($modelData['storage']) ? $modelData['storage'] : null;
            if (!$model) {
                $model = $modelData['name'];
            }
            if (!$storage) {
                $storage = $loopStorage;
            }
            if ($model != $modelData['name']) {
                return false;
            }
            if ($checkMemory && $storage != $loopStorage) {
                return false;
            }
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
                $this->logger->debug(sprintf("Get Claimscheck Forces %s", $response));

                $data = json_decode($response, true);
                $forces = [];
                if (isset($data['forces'])) {
                    foreach ($data['forces'] as $force) {
                        $forces[$force['forcename']] = $force['force'];
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
