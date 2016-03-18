<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Address;

class PCAService
{
    const FIND_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Find/v2.10/xmla.ws";
    const RETRIEVE_URL = "http://services.postcodeanywhere.co.uk/CapturePlus/Interactive/Retrieve/v2.10/xmla.ws";

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $apiKey;

    /**
     * @param LoggerInterface $logger
     * @param string          $apikey
     */
    public function __construct(LoggerInterface $logger, $apiKey)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
    }

    /**
     * @param string $postcode
     * @param string $number   Optional house number
     */
    public function getAddress($postcode, $number)
    {
        $data = $this->find($postcode, $number);
        if ($data) {
            $key = array_keys($data)[0];

            return $this->retreive($key);
        }

        return null;
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
        if (!empty($file->Rows))
        {
           foreach ($file->Rows->Row as $item)
           {
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

        if (!empty($file->Rows))
        {
            $item = $file->Rows->Row[0];
            $address = new Address();
            $address->setAddress1((string) $item->attributes()->Line1);
            $address->setAddress2((string) $item->attributes()->Line2);
            $address->setAddress3((string) $item->attributes()->Line3);
            $address->setAddress4((string) $item->attributes()->Line4);
            $address->setAddress5((string) $item->attributes()->Line5);
            $address->setCity((string) $item->attributes()->City);
            $address->setPostcode((string) $item->attributes()->PostalCode);

            return $address;
        }

        return null;
    }

    private function checkError($file)
    {
        //Check for an error, if there is one then throw an exception
        if (isset($file->Columns) && $file->Columns->Column->attributes()->Name == "Error") 
        {
            $err = sprintf("[ID] %s [DESCRIPTION] %s [CAUSE] %s [RESOLUTION] %s",
                $file->Rows->Row->attributes()->Error,
                $file->Rows->Row->attributes()->Description,
                $file->Rows->Row->attributes()->Cause,
                $file->Rows->Row->attributes()->Resolution
            );
            $this->logger->err($err);

            throw new \Exception();
        }
    }
}
