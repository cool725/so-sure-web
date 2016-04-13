<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Address;

class PCAService
{
    const CACHE_TIME = 84600; // 1 day
    const FIND_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/xmla.ws";
    const RETRIEVE_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/xmla.ws";

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $environment;

    protected $redis;

    /**
     * @param LoggerInterface $logger
     * @param string          $apiKey
     * @param string          $environment
     * @param                 $redis
     */
    public function __construct(LoggerInterface $logger, $apiKey, $environment, $redis)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->environment = $environment;
        $this->redis = $redis;
    }

    /**
     * @param string $postcode
     * @param string $number   Optional house number
     */
    public function getAddress($postcode, $number)
    {
        $redisKey = sprintf("address:%s:%s", $postcode, $number);
        if ($value = $this->redis->get($redisKey)) {
            return unserialize($value);
        }

        // Use BX1 1LT as a hard coded address for testing
        // (its a non-geographical postcode for Lloyds Bank, so is hopefully safe ;)
        if (strtoupper(trim($postcode)) == "BX11LT") {
            $address = new Address();
            $address->setLine1('so-sure Test Address Line 1');
            $address->setLine2('so-sure Test Address Line 2');
            $address->setLine3('so-sure Test Address Line 3');
            $address->setCity('so-sure Test City');
            $address->setPostcode('BX1 1LT');

            return $address;
        } elseif ($this->environment != 'prod') {
            // WR5 3DA is a free search via pca, so can used for non productione environments
            $postcode = "WR53DA";
            $number = null;
        }

        $data = $this->find($postcode, $number);
        if ($data) {
            $key = array_keys($data)[0];

            $value = $this->retreive($key);
            $this->redis->setex($redisKey, self::CACHE_TIME, serialize($value));

            return $value;
        }

        return null;
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
        // TODO: If we cache the original postcode in redis, we can avoid querying the api service for most cases
        $postcode = strtolower(str_replace(' ', '', $postcode));
        $results = $this->find($postcode, null);
        if (!$results || count($results) == 0) {
            return false;
        }

        foreach ($results as $id => $line) {
            $items = explode(',', $line);
            $found = strtolower(str_replace(' ', '', $items[0]));
            return $postcode == $found;
        }
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
            'SearchFor' => 'Everything',
            'Country' => 'GBR',
            'LanguagePreference' => 'EN',
            'MaxResults' => 50,
        ];
        $url = sprintf("%s?%s", self::FIND_URL, http_build_query($data));

        //Make the request to Postcode Anywhere and parse the XML returned
        $file = simplexml_load_file($url);
        $this->checkError($file);

        $data = [];
        if (!empty($file->Rows)) {
            foreach ($file->Rows->Row as $item) {
                $id = (string) $item->attributes()->Id;
                $address = (string) $item->attributes()->Text;
                $data[$id] = $address;
            }
        }

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
        $this->checkError($file);

        if (!empty($file->Rows)) {
            return $this->transformAddress($file->Rows->Row[0]);
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

    private function checkError($file)
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
            $this->logger->error($err);

            throw new \Exception();
        }
    }
}
