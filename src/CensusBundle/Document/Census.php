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
    
    public function getSuperGroup()
    {
        if ($this->sprgrp == '1') {
            return 'Rural residents';
        } elseif ($this->sprgrp == '2') {
            return 'Cosmopolitans';
        } elseif ($this->sprgrp == '3') {
            return 'Ethnicity central';
        } elseif ($this->sprgrp == '4') {
            return 'Multicultural metropolitans';
        } elseif ($this->sprgrp == '5') {
            return 'Urbanites';
        } elseif ($this->sprgrp == '6') {
            return 'Suburbanites';
        } elseif ($this->sprgrp == '7') {
            return 'Constrained city dwellers';
        } elseif ($this->sprgrp == '8') {
            return 'Hard-pressed living';
        } else {
            return null;
        }
    }

    public function getGroup()
    {
        if ($this->grp == '1a') {
            return 'Farming communities';
        } elseif ($this->grp == '1b') {
            return 'Rural tenants';
        } elseif ($this->grp == '1c') {
            return 'Ageing rural dwellers';
        } elseif ($this->grp == '2a') {
            return 'Students around campus';
        } elseif ($this->grp == '2b') {
            return 'Inner city students';
        } elseif ($this->grp == '2c') {
            return 'Comfortable cosmopolitan';
        } elseif ($this->grp == '2d') {
            return 'Aspiring and affluent';
        } elseif ($this->grp == '3a') {
            return 'Ethnic family life';
        } elseif ($this->grp == '3b') {
            return 'Endeavouring Ethnic Mix';
        } elseif ($this->grp == '3c') {
            return 'Ethnic dynamics';
        } elseif ($this->grp == '3d') {
            return 'Aspirational techies';
        } elseif ($this->grp == '4a') {
            return 'Rented family living';
        } elseif ($this->grp == '4b') {
            return 'Challenged Asian terraces';
        } elseif ($this->grp == '4c') {
            return 'Asian traits';
        } elseif ($this->grp == '5a') {
            return 'Urban professionals and families';
        } elseif ($this->grp == '5b') {
            return 'Ageing urban living';
        } elseif ($this->grp == '6a') {
            return 'Suburban achievers';
        } elseif ($this->grp == '6b') {
            return 'Semi-detached suburbia';
        } elseif ($this->grp == '7a') {
            return 'Challenged diversity';
        } elseif ($this->grp == '7b') {
            return 'Constrained flat dwellers';
        } elseif ($this->grp == '7c') {
            return 'White communities';
        } elseif ($this->grp == '7d') {
            return 'Ageing city dwellers';
        } elseif ($this->grp == '8a') {
            return 'Industrious communities';
        } elseif ($this->grp == '8b') {
            return 'Challenged terraced workers';
        } elseif ($this->grp == '8c') {
            return 'Hard pressed ageing workers';
        } elseif ($this->grp == '8d') {
            return 'Migration and churn';
        } else {
            return null;
        }
    }

    public function getSubGroup()
    {
        if ($this->subgrp == '1a1') {
            return 'Rural workers and families';
        } elseif ($this->subgrp == '1a2') {
            return 'Established farming communities';
        } elseif ($this->subgrp == '1a3') {
            return 'Agricultural communities';
        } elseif ($this->subgrp == '1a4') {
            return 'Older farming communities';
        } elseif ($this->subgrp == '1b1') {
            return 'Rural life';
        } elseif ($this->subgrp == '1b2') {
            return 'Rural white-collar workers';
        } elseif ($this->subgrp == '1b3') {
            return 'Ageing rural flat tenants';
        } elseif ($this->subgrp == '1c1') {
            return 'Rural employment and retirees';
        } elseif ($this->subgrp == '1c2') {
            return 'Renting rural retirement';
        } elseif ($this->subgrp == '1c3') {
            return 'Detached rural retirement';
        } elseif ($this->subgrp == '2a1') {
            return 'Student communal living';
        } elseif ($this->subgrp == '2a2') {
            return 'Student digs';
        } elseif ($this->subgrp == '2a3') {
            return 'Students and professionals';
        } elseif ($this->subgrp == '2b1') {
            return 'Students and commuters';
        } elseif ($this->subgrp == '2b2') {
            return 'Multicultural student neighbourhoods';
        } elseif ($this->subgrp == '2c1') {
            return 'Migrant families';
        } elseif ($this->subgrp == '2c2') {
            return 'Migrant commuters';
        } elseif ($this->subgrp == '2c3') {
            return 'Professional service cosmopolitans';
        } elseif ($this->subgrp == '2d1') {
            return 'Urban cultural mix';
        } elseif ($this->subgrp == '2d2') {
            return 'Highly-qualified quaternary workers';
        } elseif ($this->subgrp == '2d3') {
            return 'EU white-collar workers';
        } elseif ($this->subgrp == '3a1') {
            return 'Established renting families';
        } elseif ($this->subgrp == '3a2') {
            return 'Young families and students';
        } elseif ($this->subgrp == '3b1') {
            return 'Striving service workers';
        } elseif ($this->subgrp == '3b2') {
            return 'Bangladeshi mixed employment';
        } elseif ($this->subgrp == '3b3') {
            return 'Multi-ethnic professional service workers';
        } elseif ($this->subgrp == '3c1') {
            return 'Constrained neighbourhoods';
        } elseif ($this->subgrp == '3c2') {
            return 'Constrained commuters';
        } elseif ($this->subgrp == '3d1') {
            return 'New EU tech workers';
        } elseif ($this->subgrp == '3d2') {
            return 'Established tech workers';
        } elseif ($this->subgrp == '3d3') {
            return 'Old EU tech workers';
        } elseif ($this->subgrp == '4a1') {
            return 'Social renting young families';
        } elseif ($this->subgrp == '4a2') {
            return 'Private renting new arrivals';
        } elseif ($this->subgrp == '4a3') {
            return 'Commuters with young families';
        } elseif ($this->subgrp == '4b1') {
            return 'Asian terraces and flats';
        } elseif ($this->subgrp == '4b2') {
            return 'Pakistani communities';
        } elseif ($this->subgrp == '4c1') {
            return 'Achieving minorities';
        } elseif ($this->subgrp == '4c2') {
            return 'Multicultural new arrivals';
        } elseif ($this->subgrp == '4c3') {
            return 'Inner city ethnic mix';
        } elseif ($this->subgrp == '5a1') {
            return 'White professionals';
        } elseif ($this->subgrp == '5a2') {
            return 'Multi-ethnic professionals with families';
        } elseif ($this->subgrp == '5a3') {
            return 'Families in terraces and flats';
        } elseif ($this->subgrp == '5b1') {
            return 'Delayed retirement';
        } elseif ($this->subgrp == '5b2') {
            return 'Communal retirement';
        } elseif ($this->subgrp == '5b3') {
            return 'Self-sufficient retirement';
        } elseif ($this->subgrp == '6a1') {
            return 'Indian tech achievers';
        } elseif ($this->subgrp == '6a2') {
            return 'Comfortable suburbia';
        } elseif ($this->subgrp == '6a3') {
            return 'Detached retirement living';
        } elseif ($this->subgrp == '6a4') {
            return 'Ageing in suburbia';
        } elseif ($this->subgrp == '6b1') {
            return 'Multi-ethnic suburbia';
        } elseif ($this->subgrp == '6b2') {
            return 'White suburban communities';
        } elseif ($this->subgrp == '6b3') {
            return 'Semi-detached ageing';
        } elseif ($this->subgrp == '6b4') {
            return 'Older workers and retirement';
        } elseif ($this->subgrp == '7a1') {
            return 'Transitional Eastern European neighbourhoods';
        } elseif ($this->subgrp == '7a2') {
            return 'Hampered aspiration';
        } elseif ($this->subgrp == '7a3') {
            return 'Multi-ethnic hardship';
        } elseif ($this->subgrp == '7b1') {
            return 'Eastern European communities';
        } elseif ($this->subgrp == '7b2') {
            return 'Deprived neighbourhoods';
        } elseif ($this->subgrp == '7b3') {
            return 'Endeavouring flat dwellers';
        } elseif ($this->subgrp == '7c1') {
            return 'Challenged transitionaries';
        } elseif ($this->subgrp == '7c2') {
            return 'Constrained young families';
        } elseif ($this->subgrp == '7c3') {
            return 'Outer city hardship';
        } elseif ($this->subgrp == '7d1') {
            return 'Ageing communities and families';
        } elseif ($this->subgrp == '7d2') {
            return 'Retired independent city dwellers';
        } elseif ($this->subgrp == '7d3') {
            return 'Retired communal city dwellers';
        } elseif ($this->subgrp == '7d4') {
            return 'Retired city hardship';
        } elseif ($this->subgrp == '8a1') {
            return 'Industrious transitions';
        } elseif ($this->subgrp == '8a2') {
            return 'Industrious hardship';
        } elseif ($this->subgrp == '8b1') {
            return 'Deprived blue-collar terraces';
        } elseif ($this->subgrp == '8b2') {
            return 'Hard pressed rented terraces';
        } elseif ($this->subgrp == '8c1') {
            return 'Ageing industrious workers';
        } elseif ($this->subgrp == '8c2') {
            return 'Ageing rural industry workers';
        } elseif ($this->subgrp == '8c3') {
            return 'Renting hard-pressed workers';
        } elseif ($this->subgrp == '8d1') {
            return 'Young hard-pressed families';
        } elseif ($this->subgrp == '8d2') {
            return 'Hard-pressed ethnic mix';
        } elseif ($this->subgrp == '8d3') {
            return 'Hard-Pressed European Settlers';
        } else {
            return '';
        }
    }
}
