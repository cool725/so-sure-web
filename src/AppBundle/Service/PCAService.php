<?php
namespace AppBundle\Service;

use AppBundle\Document\PostcodeTrait;
use CensusBundle\Service\SearchService;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Address;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Charge;
use AppBundle\Document\User;
use AppBundle\Document\BacsTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client;
use AppBundle\Exception\DirectDebitBankException;

class PCAService
{
    use BacsTrait;
    use PostcodeTrait;

    const TIMEOUT = 5;
    const REDIS_POSTCODE_KEY = 'postcode';
    const REDIS_ADDRESS_KEY_FORMAT = 'address:%s:%s';
    const REDIS_BANK_KEY_FORMAT = 'bank:%s:%s';
    const CACHE_TIME = 84600; // 1 day
    const FIND_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/xmla.ws";
    const RETRIEVE_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/xmla.ws";
    // @codingStandardsIgnoreStart
    const BANK_ACCOUNT_URL = "https://services.postcodeanywhere.co.uk/BankAccountValidation/Interactive/Validate/v2.00/json.ws";
    // @codingStandardsIgnoreEnd
    const TEST_SORT_CODE = "000099";
    const TEST_ACCOUNT_NUMBER_PCA = "12345678";
    const TEST_ACCOUNT_NUMBER_OK = "87654321";
    const TEST_ACCOUNT_NUMBER_OK_DISPLAY = "XXXX4321";
    const TEST_ACCOUNT_NUMBER_ADJUSTED = "876543";
    const TEST_ACCOUNT_NUMBER_ADJUSTED_DISPLAY = "XXXX4300";
    const TEST_ACCOUNT_NUMBER_NO_DD = "00000000";
    const TEST_ACCOUNT_NUMBER_INVALID_SORT_CODE = "99999998";
    const TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER = "99999999";

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $environment;

    /** @var \Predis\Client */
    protected $redis;

    /** @var SearchService */
    protected $searchService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $apiKey
     * @param string          $environment
     * @param \Predis\Client  $redis
     * @param SearchService   $searchService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $apiKey,
        $environment,
        \Predis\Client $redis,
        SearchService $searchService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->environment = $environment;
        $this->redis = $redis;
        $this->searchService = $searchService;
    }

    /**
     * @param string    $sortCode
     * @param string    $accountNumber
     * @param User|null $user
     * @return BankAccount|null
     * @throws DirectDebitBankException
     */
    public function getBankAccount($sortCode, $accountNumber, User $user = null)
    {
        $sortCode = $this->normalizeSortCode($sortCode);
        $accountNumber = $this->normalizeAccountNumber($accountNumber);

        $redisKey = sprintf(self::REDIS_BANK_KEY_FORMAT, $sortCode, $accountNumber);
        if ($value = $this->redis->get($redisKey)) {
            return unserialize($value);
        }

        if ($this->environment != 'prod') {
            // Use 00-00-99 / 87654321 for testing. as 00-00-99/12345678 is used for testing for pca-predict
            // hopefully should be ok
            if ($sortCode == self::TEST_SORT_CODE && in_array($accountNumber, [
                    self::TEST_ACCOUNT_NUMBER_OK,
                    self::TEST_ACCOUNT_NUMBER_ADJUSTED
                ])) {
                $bankAccount = new BankAccount();
                $bankAccount->setBankName('foo bank');
                $bankAccount->setSortCode($sortCode);
                $bankAccount->setAccountNumber($accountNumber);
                if ($accountNumber == self::TEST_ACCOUNT_NUMBER_ADJUSTED) {
                    $bankAccount->setAccountNumber(sprintf("%s00", $accountNumber));
                }
                $address = new Address();
                $address->setLine1('so-sure Test Address Line 1');
                $address->setLine2('so-sure Test Address Line 2');
                $address->setLine3('so-sure Test Address Line 3');
                $address->setCity('so-sure Test City');
                $address->setPostcode('BX1 1LT');
                $bankAccount->setBankAddress($address);
                $this->cacheBankAccountResults($sortCode, $accountNumber, $bankAccount);

                return $bankAccount;
            } elseif ($sortCode == self::TEST_SORT_CODE &&
                $accountNumber == self::TEST_ACCOUNT_NUMBER_INVALID_SORT_CODE) {
                throw new DirectDebitBankException('Bad sort code', DirectDebitBankException::ERROR_SORT_CODE);
            } elseif ($sortCode == self::TEST_SORT_CODE &&
                $accountNumber == self::TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER) {
                throw new DirectDebitBankException('No direct debit', DirectDebitBankException::ERROR_ACCOUNT_NUMBER);
            } elseif ($sortCode == self::TEST_SORT_CODE && $accountNumber == self::TEST_ACCOUNT_NUMBER_NO_DD) {
                throw new DirectDebitBankException('No direct debit', DirectDebitBankException::ERROR_NON_DIRECT_DEBIT);
            } elseif ($this->environment != 'prod') {
                // 00-00-99/12345678 is a free search via pca, so can used for non production environments
                $sortCode = self::TEST_SORT_CODE;
                $accountNumber = self::TEST_ACCOUNT_NUMBER_PCA;
            }
        }

        try {
            $bankAccount = $this->findBankAccount($sortCode, $accountNumber);
        } catch (DirectDebitBankException $e) {
            throw $e;
        }

        if ($bankAccount) {
            $this->cacheBankAccountResults($sortCode, $accountNumber, $bankAccount);

            // ignore free check
            if (!($sortCode == self::TEST_SORT_CODE && $accountNumber == self::TEST_ACCOUNT_NUMBER_PCA)) {
                $charge = new Charge();
                try {
                    $charge->setType(Charge::TYPE_BANK_ACCOUNT);
                    $charge->setUser($user);
                    $charge->setDetails(sprintf(
                        '%s %s',
                        $this->displayableSortCode($sortCode),
                        $this->displayableAccountNumber($accountNumber)
                    ));
                    $this->dm->persist($charge);
                    $this->dm->flush();
                } catch (\Exception $e) {
                    // Better to swallow this than fail
                    $this->logger->warning('Error saving address charge.', ['exception' => $e]);
                }
            }

            return $bankAccount;
        }

        return null;
        
    }

    /**
     * @param string    $postcode
     * @param string    $number
     * @param User|null $user
     * @return Address|null
     */
    public function getAddress($postcode, $number, User $user = null)
    {
        $postcode = $this->normalizePostcodeForDb($postcode);

        $redisKey = sprintf(self::REDIS_ADDRESS_KEY_FORMAT, $postcode, $number);
        if ($value = $this->redis->get($redisKey)) {
            return unserialize($value);
        }

        // Use BX1 1LT as a hard coded address for testing
        // (its a non-geographical postcode for Lloyds Bank, so is hopefully safe ;)
        if ($postcode == "BX11LT") {
            $address = new Address();
            $address->setLine1('so-sure Test Address Line 1');
            $address->setLine2('so-sure Test Address Line 2');
            $address->setLine3('so-sure Test Address Line 3');
            $address->setCity('so-sure Test City');
            $address->setPostcode('BX1 1LT');
            $this->cacheResults($postcode, $number, $address);

            return $address;
        } elseif ($postcode == "ZZ993CZ") {
            // Used for testing invalid postcode - pseudo-postcodes for england
            return null;
        } elseif ($this->environment != 'prod') {
            // WR5 3DA is a free search via pca, so can used for non production environments
            $postcode = "WR53DA";
            $number = null;
        }

        $data = $this->find($postcode, $number);
        if ($data) {
            $key = array_keys($data)[0];

            $address = $this->retreive($key);
            $this->cacheResults($postcode, $number, $address);

            // ignore free check
            if ($postcode != "WR53DA") {
                $charge = new Charge();
                try {
                    $charge->setType(Charge::TYPE_ADDRESS);
                    $charge->setUser($user);
                    $charge->setDetails(sprintf('%s, %s', $postcode, $number));
                    $this->dm->persist($charge);
                    $this->dm->flush();
                } catch (\Exception $e) {
                    // Better to swallow this than fail
                    $this->logger->warning('Error saving address charge.', ['exception' => $e]);
                }
            }

            return $address;
        }

        return null;
    }

    protected function cacheResults($postcode, $number, $address)
    {
        $redisKey = sprintf(self::REDIS_ADDRESS_KEY_FORMAT, $postcode, $number);
        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($address));
        $this->redis->hset(self::REDIS_POSTCODE_KEY, $postcode, 1);

    }

    protected function cacheBankAccountResults($sortCode, $accountNumber, $bankAccount)
    {
        $redisKey = sprintf(self::REDIS_BANK_KEY_FORMAT, $sortCode, $accountNumber);
        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($bankAccount));
    }

    /**
     * Use the free find service to ensure that the postcode is valid
     *
     * @param string $postcode
     *
     * @return boolean
     */
    public function validatePostcode($postcode, $ignoreCache = false)
    {
        $postcode = $this->normalizePostcodeForDb($postcode);
        if ($postcode == "BX11LT") {
            return true;
        } elseif ($postcode == "ZZ993CZ") {
            return false;
        }

        if (!$ignoreCache && $this->redis->hexists(self::REDIS_POSTCODE_KEY, $postcode) == 1) {
            return true;
        }

        $results = null;
        try {
            $results = $this->find($postcode, null);
        } catch (\Exception $e) {
            return false;
        }

        if (!$results || count($results) == 0) {
            return false;
        }

        foreach ($results as $id => $line) {
            if (PostcodeTrait::findPostcode($line, $postcode)) {
                $this->redis->hset(self::REDIS_POSTCODE_KEY, $postcode, 1);
                return true;
            }
        }
        return false;
    }

    /**
     * Call pca find to get list of addresses that match criteria
     * @param string      $postcode
     * @param string|null $number
     * @return array|null
     */
    public function find($postcode, $number)
    {
        if ($number) {
            $search = sprintf("%s, %s", $postcode, $number);
        } else {
            $search = $postcode;
        }

        $data = [
            'Key' => $this->apiKey,
            'SearchTerm' => $search,
            'SearchFor' => 'PostalCodes',
            'Country' => 'GBR',
            'LanguagePreference' => 'EN',
            'MaxResults' => 50,
        ];
        $url = sprintf("%s?%s", self::FIND_URL, http_build_query($data));

        //Make the request to Postcode Anywhere and parse the XML returned
        $file = simplexml_load_file($url);
        try {
            $this->checkError($file, $postcode);
        } catch (\Exception $e) {
            return null;
        }

        $data = [];
        if (!empty($file->Rows)) {
            foreach ($file->Rows->Row as $item) {
                $id = (string) $item->attributes()->Id;
                $address = (string) $item->attributes()->Text;
                $data[$id] = $address;
            }
        }
        $this->logger->info(sprintf('Address lookup for %s %s', $postcode, json_encode($data)));

        return $data;
    }

    /**
     * Call pca retreive to get details of and id (from find)
     * There is a cost associated with this call (~£0.055)
     *
     * @param string $id
     *
     * @return Address|null
     */
    public function retreive($id)
    {
        $data = [
            'Key' => $this->apiKey,
            'Id' => $id,
        ];
        $url = sprintf("%s?%s", self::RETRIEVE_URL, http_build_query($data));

        //Make the request to Postcode Anywhere and parse the XML returned
        /** @var \SimpleXMLElement $file */
        $file = simplexml_load_file($url);
        try {
            $this->checkError($file);
        } catch (\Exception $e) {
            return null;
        }

        if (!empty($file->Rows)) {
            $data = $this->transformAddress($file->Rows->Row[0]);
            $this->logger->info(sprintf('Address find for %s %s', $id, json_encode($data->toApiArray())));

            if (!$this->searchService->validatePostcode($data->getPostcode())) {
                $this->logger->info(sprintf(
                    'Postcode %s was found in PCA but missing from local db',
                    $data->getPostcode()
                ));
            }

            return $data;
        }

        return null;
    }

    /**
     * Call PCA Bank Account to validate sortcode/account number
     *
     * @param string $sortCode
     * @param string $accountNumber
     * @return BankAccount
     * @throws DirectDebitBankException
     */
    public function findBankAccount($sortCode, $accountNumber)
    {
        $data = $this->findBankAccountRequest($sortCode, $accountNumber);

        // @codingStandardsIgnoreStart
        // {"IsCorrect":"True","IsDirectDebitCapable":"True","StatusInformation":"CautiousOK","CorrectedSortCode":"000099","CorrectedAccountNumber":"12345678","IBAN":"GB27NWBK00009912345678","Bank":"TEST BANK PLC PLC","BankBIC":"NWBKGB21","Branch":"Worcester","BranchBIC":"18R","ContactAddressLine1":"2 High Street","ContactAddressLine2":"Smallville","ContactPostTown":"Worcester","ContactPostcode":"WR2 6NJ","ContactPhone":"01234 456789","ContactFax":"","FasterPaymentsSupported":"False","CHAPSSupported":"True"}
        // @codingStandardsIgnoreEnd
        $this->logger->info(sprintf('Bank Account lookup for %s %s %s', $sortCode, $accountNumber, json_encode($data)));
        if ($data['StatusInformation'] == "UnknownSortCode") {
            throw new DirectDebitBankException('Unknown sort code', DirectDebitBankException::ERROR_SORT_CODE);
        } elseif ($data['StatusInformation'] == "InvalidAccountNumber") {
            throw new DirectDebitBankException(
                'Invalid account number',
                DirectDebitBankException::ERROR_ACCOUNT_NUMBER
            );
        } elseif (!$data['IsDirectDebitCapable']) {
            throw new DirectDebitBankException(
                'Account is not dd capable',
                DirectDebitBankException::ERROR_NON_DIRECT_DEBIT
            );
        }
        $bankAccount = new BankAccount();
        $bankAccount->setBankName($data['Bank']);
        $bankAccount->setSortCode($data['CorrectedSortCode']);
        $bankAccount->setAccountNumber($data['CorrectedAccountNumber']);
        $address = new Address();
        $address->setLine1($data['ContactAddressLine1']);
        $address->setLine2($data['ContactAddressLine2']);
        $address->setCity($data['ContactPostTown']);
        $address->setPostcode($data['ContactPostcode']);
        $bankAccount->setBankAddress($address);

        return $bankAccount;
    }

    /**
     * Perform the http request to PCA Bank Account to validate sortcode/account number
     *
     * @param string $sortCode
     * @param string $accountNumber
     * @return String
     */
    public function findBankAccountRequest($sortCode, $accountNumber)
    {
        $data = [
            'Key' => $this->apiKey,
            'AccountNumber' => $accountNumber,
            'SortCode' => $sortCode,
        ];
        $url = sprintf("%s?%s", self::BANK_ACCOUNT_URL, http_build_query($data));

        $client = new Client();
        $res = $client->request('GET', $url, ['connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT]);

        $body = (string) $res->getBody();

        return json_decode($body, true)[0];
    }

    /**
     * Transform xml row to address
     *
     * @param mixed $row
     *
     * @return Address
     */
    public function transformAddress($row)
    {
        // TODO: Move to method, but will need to fix test cases
        /** @var \SimpleXMLElement $row */
        $address = new Address();
        $line1 = (string) $row->attributes()->Line1;
        $line2 = (string) $row->attributes()->Line2;
        $line3 = (string) $row->attributes()->Line3;
        $line4 = (string) $row->attributes()->Line4;
        $line5 = (string) $row->attributes()->Line5;
        if (mb_strlen($line5) > 0) {
            $line1 = sprintf("%s, %s", $line1, $line2);
            $line2 = $line3;
            $line3 = sprintf("%s, %s", $line4, $line5);
        } elseif (mb_strlen($line4) > 0) {
            $line3 = sprintf("%s, %s", $line3, $line4);
        }
        $address->setLine1($line1);
        $address->setLine2($line2);
        $address->setLine3($line3);
        $address->setCity((string) $row->attributes()->City);
        $address->setPostcode((string) $row->attributes()->PostalCode);

        return $address;
    }

    /**
     * @param \SimpleXMLElement $file
     * @param string|null       $postcode
     * @throws \Exception
     */
    private function checkError(\SimpleXMLElement $file, $postcode = null)
    {
        //Check for an error, if there is one then throw an exception
        if (isset($file->Columns) && $file->Columns->Column->attributes()->Name == "Error") {
            $err = sprintf(
                "[ID] %s [DESCRIPTION] %s [CAUSE] %s [RESOLUTION] %s",
                $file->Rows->Row->attributes()->Error,
                $file->Rows->Row->attributes()->Description,
                $file->Rows->Row->attributes()->Cause,
                $file->Rows->Row->attributes()->Resolution
            );
            $this->logger->error(sprintf("Error checking postcode (%s) db Ex: %s", $postcode, $err));

            throw new \Exception();
        }
    }
}
