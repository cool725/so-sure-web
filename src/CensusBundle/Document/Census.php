<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(collection="2011")
 * @MongoDB\Index(keys={"Location"="2dsphere"}, sparse="true")
 */
class Census
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="OA")
     */
    protected $oa;

    /**
     * @MongoDB\EmbedOne(targetDocument="Coordinates", name="Location")
     */
    protected $location;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_CODE")
     */
    protected $localAuthorityCode;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_NAME")
     */
    protected $localAuthorityName;

    /**
     * @MongoDB\Field(type="string", name="REGION_OR_COUNTRY_CODE")
     */
    protected $regionCode;

    /**
     * @MongoDB\Field(type="string", name="REGION_OR_COUNTRY_NAME")
     */
    protected $regionName;

    /**
     * @MongoDB\Field(type="integer", name="POPULATION")
     */
    protected $population;

    /**
     * @MongoDB\Field(type="integer", name="SPRGRP")
     */
    protected $sprgrp;

    /**
     * @MongoDB\Field(type="string", name="GRP")
     */
    protected $grp;

    /**
     * @MongoDB\Field(type="string", name="SUBGRP")
     */
    protected $subgrp;

    public function getOA()
    {
        return $this->oa;
    }

    public function getGrp()
    {
        return $this->grp;
    }

    public function getSubgrp()
    {
        return $this->subgrp;
    }

    public function getLocation()
    {
        return $this->location;
    }

    /**
     * @return mixed string-result or null of the lookup
     */
    public function getSuperGroup()
    {
        static $superGroup = [
            '1' => 'Rural residents',
            '2' => 'Cosmopolitans',
            '3' => 'Ethnicity central',
            '4' => 'Multicultural metropolitans',
            '5' => 'Urbanites',
            '6' => 'Suburbanites',
            '7' => 'Constrained city dwellers',
            '8' => 'Hard-pressed living',
        ];

        return $superGroup[$this->sprgrp] ?? null;
    }

    /**
     * @return mixed string-result or null of the lookup
     */
    public function getGroup()
    {
        static $group = [
            '1a' => 'Farming communities',
            '1b' => 'Rural tenants',
            '1c' => 'Ageing rural dwellers',
            '2a' => 'Students around campus',
            '2b' => 'Inner city students',
            '2c' => 'Comfortable cosmopolitan',
            '2d' => 'Aspiring and affluent',
            '3a' => 'Ethnic family life',
            '3b' => 'Endeavouring Ethnic Mix',
            '3c' => 'Ethnic dynamics',
            '3d' => 'Aspirational techies',
            '4a' => 'Rented family living',
            '4b' => 'Challenged Asian terraces',
            '4c' => 'Asian traits',
            '5a' => 'Urban professionals and families',
            '5b' => 'Ageing urban living',
            '6a' => 'Suburban achievers',
            '6b' => 'Semi-detached suburbia',
            '7a' => 'Challenged diversity',
            '7b' => 'Constrained flat dwellers',
            '7c' => 'White communities',
            '7d' => 'Ageing city dwellers',
            '8a' => 'Industrious communities',
            '8b' => 'Challenged terraced workers',
            '8c' => 'Hard pressed ageing workers',
            '8d' => 'Migration and churn',
        ];

        return $group[$this->grp] ?? null;
    }

    /**
     * @return mixed string-result or '' of the lookup
     */
    public function getSubGroup()
    {
        static $subGroup = [
            '1a1' => 'Rural workers and families',
            '1a2' => 'Established farming communities',
            '1a3' => 'Agricultural communities',
            '1a4' => 'Older farming communities',
            '1b1' => 'Rural life',
            '1b2' => 'Rural white-collar workers',
            '1b3' => 'Ageing rural flat tenants',
            '1c1' => 'Rural employment and retirees',
            '1c2' => 'Renting rural retirement',
            '1c3' => 'Detached rural retirement',
            '2a1' => 'Student communal living',
            '2a2' => 'Student digs',
            '2a3' => 'Students and professionals',
            '2b1' => 'Students and commuters',
            '2b2' => 'Multicultural student neighbourhoods',
            '2c1' => 'Migrant families',
            '2c2' => 'Migrant commuters',
            '2c3' => 'Professional service cosmopolitans',
            '2d1' => 'Urban cultural mix',
            '2d2' => 'Highly-qualified quaternary workers',
            '2d3' => 'EU white-collar workers',
            '3a1' => 'Established renting families',
            '3a2' => 'Young families and students',
            '3b1' => 'Striving service workers',
            '3b2' => 'Bangladeshi mixed employment',
            '3b3' => 'Multi-ethnic professional service workers',
            '3c1' => 'Constrained neighbourhoods',
            '3c2' => 'Constrained commuters',
            '3d1' => 'New EU tech workers',
            '3d2' => 'Established tech workers',
            '3d3' => 'Old EU tech workers',
            '4a1' => 'Social renting young families',
            '4a2' => 'Private renting new arrivals',
            '4a3' => 'Commuters with young families',
            '4b1' => 'Asian terraces and flats',
            '4b2' => 'Pakistani communities',
            '4c1' => 'Achieving minorities',
            '4c2' => 'Multicultural new arrivals',
            '4c3' => 'Inner city ethnic mix',
            '5a1' => 'White professionals',
            '5a2' => 'Multi-ethnic professionals with families',
            '5a3' => 'Families in terraces and flats',
            '5b1' => 'Delayed retirement',
            '5b2' => 'Communal retirement',
            '5b3' => 'Self-sufficient retirement',
            '6a1' => 'Indian tech achievers',
            '6a2' => 'Comfortable suburbia',
            '6a3' => 'Detached retirement living',
            '6a4' => 'Ageing in suburbia',
            '6b1' => 'Multi-ethnic suburbia',
            '6b2' => 'White suburban communities',
            '6b3' => 'Semi-detached ageing',
            '6b4' => 'Older workers and retirement',
            '7a1' => 'Transitional Eastern European neighbourhoods',
            '7a2' => 'Hampered aspiration',
            '7a3' => 'Multi-ethnic hardship',
            '7b1' => 'Eastern European communities',
            '7b2' => 'Deprived neighbourhoods',
            '7b3' => 'Endeavouring flat dwellers',
            '7c1' => 'Challenged transitionaries',
            '7c2' => 'Constrained young families',
            '7c3' => 'Outer city hardship',
            '7d1' => 'Ageing communities and families',
            '7d2' => 'Retired independent city dwellers',
            '7d3' => 'Retired communal city dwellers',
            '7d4' => 'Retired city hardship',
            '8a1' => 'Industrious transitions',
            '8a2' => 'Industrious hardship',
            '8b1' => 'Deprived blue-collar terraces',
            '8b2' => 'Hard pressed rented terraces',
            '8c1' => 'Ageing industrious workers',
            '8c2' => 'Ageing rural industry workers',
            '8c3' => 'Renting hard-pressed workers',
            '8d1' => 'Young hard-pressed families',
            '8d2' => 'Hard-pressed ethnic mix',
            '8d3' => 'Hard-Pressed European Settlers',
        ];

        return $subGroup[$this->subgrp] ?? '';
    }
}
