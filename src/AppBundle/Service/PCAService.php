<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Address;
use AppBundle\Document\Charge;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;

class PCAService
{
    const REDIS_POSTCODE_KEY = 'postcode';
    const REDIS_ADDRESS_KEY_FORMAT = 'address:%s:%s';
    const CACHE_TIME = 84600; // 1 day
    const FIND_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/xmla.ws";
    const RETRIEVE_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/xmla.ws";

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $environment;

    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $apiKey
     * @param string          $environment
     * @param                 $redis
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger, $apiKey, $environment, $redis)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->environment = $environment;
        $this->redis = $redis;
    }

    public function normalizePostcode($postcode)
    {
        return strtoupper(str_replace(' ', '', trim($postcode)));
    }

    /**
     * @param string $postcode
     * @param string $number   Optional house number
     * @param User   $user     Optional user
     */
    public function getAddress($postcode, $number, User $user = null)
    {
        $postcode = $this->normalizePostcode($postcode);

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

    public function validateSortCode($sortCode)
    {
        return true;
    }

    public function validateAccountNumber($accountNumber)
    {
        return true;
    }

    /**
     * Use the free find service to ensure that the postcode is valid
     *
     * @param string $postcode
     *
     * @return boolean
     */
    public function validatePostcode($postcode)
    {
        $postcode = $this->normalizePostcode($postcode);
        if ($postcode == "BX11LT") {
            return true;
        } elseif ($postcode == "ZZ993CZ") {
            return false;
        }

        if ($this->redis->hexists(self::REDIS_POSTCODE_KEY, $postcode)) {
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
            $items = explode(',', $line);
            $found = $this->normalizePostcode($items[0]);

            if ($postcode == $found) {
                $this->redis->hset(self::REDIS_POSTCODE_KEY, $postcode, 1);

                return true;
            }
        }

        return false;
    }

    /**
     * Call pca find to get list of addresses that match criteria
     *
     * @param string $postcode
     * @param string $number   Optional house number
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
        $file = simplexml_load_file($url);
        try {
            $this->checkError($file);
        } catch (\Exception $e) {
            return null;
        }

        if (!empty($file->Rows)) {
            $data = $this->transformAddress($file->Rows->Row[0]);
            $this->logger->info(sprintf('Address find for %s %s', $id, json_encode($data)));

            return $data;
        }

        return null;
    }

    /**
     * Transform xml row to address
     *
     * @param $row
     *
     * return @Address
     */
    public function transformAddress($row)
    {
        $address = new Address();
        $line1 = (string) $row->attributes()->Line1;
        $line2 = (string) $row->attributes()->Line2;
        $line3 = (string) $row->attributes()->Line3;
        $line4 = (string) $row->attributes()->Line4;
        $line5 = (string) $row->attributes()->Line5;
        if (strlen($line5) > 0) {
            $line1 = sprintf("%s, %s", $line1, $line2);
            $line2 = $line3;
            $line3 = sprintf("%s, %s", $line4, $line5);
        } elseif (strlen($line4) > 0) {
            $line3 = sprintf("%s, %s", $line3, $line4);
        }
        $address->setLine1($line1);
        $address->setLine2($line2);
        $address->setLine3($line3);
        $address->setCity((string) $row->attributes()->City);
        $address->setPostcode((string) $row->attributes()->PostalCode);

        return $address;
    }

    private function checkError($file, $postcode = null)
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
