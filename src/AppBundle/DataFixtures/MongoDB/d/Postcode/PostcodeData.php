<?php

namespace AppBundle\DataFixtures\MongoDB\d\Postcode;

use AppBundle\Document\Postcode;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class PostcodeData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface|null
     */
    private $container;

    private function getPostcodes()
    {
        return [
            [
                "postcode" => "DE14",
                "added" => new \DateTime("2017-01-01"),
                "notes" => "Suspected fraud in those postcodes based on claims we received"
            ],
            [
                "postcode" => "TN15 7LY",
                "added" => new \DateTime("2017-01-01"),
                "notes" => "Suspected fraud in those postcodes based on claims we received"
            ],
            [
                "postcode" => "PE21 7TB",
                "added" => new \DateTime("2017-08-16"),
                "notes" => "Explainable but odd situation from customer triggering manual fraud suspicion"
            ],
            [
                "postcode" => "OL11 1QA",
                "added" => new \DateTime("2018-03-18"),
                "notes" => "Suspected fraud"
            ],
            [
                "postcode" => "WN1 2XD",
                "added" => new \DateTime("2018-04-23"),
                "notes" => "Suspected fraud for Mob/2018/5503304"
            ],
            [
                "postcode" => "TW15 1LN",
                "added" => new \DateTime("2018-05-01"),
                "notes" => "Attempting to insure an already damaged phone"
            ],
            [
                "postcode" => "CB6 1DD",
                "added" => new \DateTime("2018-08-23"),
                "notes" => "Reason unknown"
            ],
            [
                "postcode" => "IG11 9XH",
                "added" => new \DateTime("2019-02-14"),
                "notes" => "Suspected Fraud"
            ],
            [
                "postcode" => "E6 1DY",
                "added" => new \DateTime("2019-02-14"),
                "notes" => "Suspected Fraud"
            ],
            [
                "postcode" => "IG3 9JX",
                "added" => new \DateTime("2019-02-14"),
                "notes" => "Suspected Fraud"
            ],
            [
                "postcode" => "E6 3EZ",
                "added" => new \DateTime("2019-02-14"),
                "notes" => "Suspected Fraud"
            ],
            [
                "postcode" => "GY1",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY2",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY3",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY4",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY5",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY6",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY7",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY8",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY9",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ],
            [
                "postcode" => "GY10",
                "outcode" => true,
                "banned" => true,
                "added" => new \DateTime("2020-01-27"),
                "notes" => "tax"
            ]
        ];
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $repo = $manager->getRepository(Postcode::class);
        $postcodes = $this->getPostcodes();
        foreach ($postcodes as $postcode) {
            $postcodeDoc = new Postcode();
            $postcodeDoc->setPostcode($postcode['postcode']);
            $postcodeDoc->setAdded($postcode['added']);
            $postcodeDoc->setNotes($postcode['notes']);
            $postcodeDoc->setBanned($this->getOr($postcode, 'banned', false));
            $postcodeDoc->setType($this->getOr($postcode, 'outcode', false) ? 'outcode' : 'postcode');
            $manager->persist($postcodeDoc);
            $manager->flush();
        }
    }

    /**
     * Looks for a value in an array, and if it is not there then it returns a given default.
     * @param array $container is the array to look in.
     * @param string $key is the key to look for.
     * @param mixed $default is the default to return if the key is not found.
     */
    private function getOr($container, $key, $default)
    {
        if (in_array($key, $container)) {
            return $container[$key];
        }
        return $default;
    }
}
