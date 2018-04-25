<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="CensusBundle\Repository\OutputAreaRepository")
 */
class OutputArea
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="Postcode")
     * @MongoDB\Index(unique=true, sparse=true)
     */
    protected $postcode;

    /**
     * @MongoDB\Field(type="string", name="OA")
     */
    protected $oa;

    /**
     * @MongoDB\Field(type="string", name="LSOA")
     * @MongoDB\Index()
     */
    protected $lsoa;

    /**
     * @MongoDB\Field(type="string", name="MSOA")
     */
    protected $msoa;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_CODE")
     */
    protected $localAuthorityCode;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_NAME")
     */
    protected $localAuthorityName;

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function getOA()
    {
        return $this->oa;
    }

    public function getLSOA()
    {
        return $this->lsoa;
    }

    public function getMSOA()
    {
        return $this->msoa;
    }

    public function getLocalAuthorityCode()
    {
        return $this->localAuthorityCode;
    }

    public function isLondon()
    {
        return in_array($this->localAuthorityCode, [
            'E09000001', // City of London
            'E09000007', // Camden
            'E09000011', // Greenwich
            'E09000012', // Hackney
            'E09000013', // Hammersmith and Fulham
            'E09000019', // Islington
            'E09000020', // Kensington and Chelsea
            'E09000022', // Lambeth
            'E09000023', // Lewisham
            'E09000028', // Southwark
            'E09000030', // Tower Hamlets
            'E09000032', // Wandsworth
            'E09000033', // Westminster
            'E09000002', // Barking and Dagenham
            'E09000003', // Barnet
            'E09000004', // Bexley
            'E09000005', // Brent
            'E09000006', // Bromley
            'E09000008', // Croydon
            'E09000009', // Ealing
            'E09000010', // Enfield
            'E09000014', // Haringey
            'E09000015', // Harrow
            'E09000016', // Havering
            'E09000017', // Hillingdon
            'E09000018', // Hounslow
            'E09000021', // Kingston upon Thames
            'E09000024', // Merton
            'E09000025', // Newham
            'E09000026', // Redbridge
            'E09000027', // Richmond upon Thames
            'E09000029', // Sutton
            'E09000031', // Waltham Forest
        ]);
    }

    public function isHomeCounty()
    {
        return in_array($this->localAuthorityCode, [
            'E06000055', // Bedfordshire - Bedford
            'E06000056', // Bedfordshire - Central Bedfordshire
            'E06000032', // Bedfordshire - Luton
            'E06000036', // Berkshire - Bracknell Forest
            'E06000040', // Berkshire - Windsor and Maidenhead
            'E06000037', // Berkshire - West Berkshire
            'E06000038', // Berkshire - Reading
            'E06000039', // Berkshire - Slough
            'E06000041', // Berkshire - Wokingham
            'E06000042', // Buckinghamshire - Milton Keynes
            'E10000002', // Buckinghamshire - Buckinghamshire
            'E10000003', // Cambridgeshire - Cambridgeshire
            'E06000031', // Cambridgeshire - Peterborough
            'E10000012', // Essex - Essex
            'E06000033', // Essex - Southend-on-Sea
            'E06000034', // Essex - Thurrock
            'E10000014', // Hampshire - Hampshire
            'E06000044', // Hampshire - Portsmouth
            'E06000045', // Hampshire - Southampton
            'E10000015', // Hertfordshire
            'E10000016', // Kent
            'E06000035', // Kent - Medway
            'E10000030', // Surrey
            'E10000011', // East Sussex
            'E06000043', // East Sussex - Brighton and Hove
            'E10000032', // West Sussex
        ]);
    }
}
