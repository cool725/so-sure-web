<?php

namespace App\Hubspot;

use AppBundle\Service\HubspotService;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use SevenShores\Hubspot\Exceptions\BadRequest;
use SevenShores\Hubspot\Factory as HubspotFactory;

/**
 * Contains functions for building the initial structure of the data we require on hubspot in order for our integration
 * to work.
 * TODO: I am considering moving this all into the hubspot service because it's weird, but that would make the file
 *       pretty long. Actually yeah I will do it, but I think that I will wait until after I have got everything running
 *       and working because otherwise it could lead to a lot of pain if I don't know why stuff is not working.
 */
class ApiCreateStructures
{
    const HUBSPOT_SOSURE_PROPERTY_NAME = "sosure";
    const HUBSPOT_SOSURE_PROPERTY_DESC = "Custom properties used by SoSure";

    /** @var LoggerInterface */
    protected $logger;
    /** @var HubspotFactory */
    private $client;
    /** @var HubspotService */
    private $hubspotService;

    /**
     * Builds the structure service.
     * @param HubspotService  $hubspotService is the hubspot service.
     * @param string          $hubspotKey     is the API key to access hubspot.
     * @param LoggerInterface $logger         is the logger.
     */
    public function __construct(
        HubspotService $hubspotService,
        $hubspotKey,
        LoggerInterface $logger
    ) {
        $this->client = HubspotFactory::create($hubspotKey);
        $this->hubspotService = $hubspotService;
        $this->logger = $logger;
    }

    /**
     * Checks the Hubspot API for the properties that we need and creates any that it cannot find.
     * @return array Containing messages with all the actions taken.
     */
    public function syncProperties()
    {
        $actions = [];
        $soSureProperties = $this->allPropertiesWithGroup();
        foreach ($soSureProperties as $propertyData) {
            $propertyName = $propertyData["name"];
            try {
                $this->client->contactProperties()->get($propertyName);
                $actions[] = "<info>$propertyName</info> was found on Hubspot.";
            } catch (\Exception $e) {
                $actions[] = "<comment>$propertyName</comment> was not found on Hubspot.";
                $actions[] = "<info>$propertyName</info> trying to create Hubspot.";
                $actions = array_push($actions, $this->createHubspotProperty($propertyData, $propertyName));
            }
        }
        return $actions;
    }

    /**
     * Synchronises a property group with hubspot.
     * NOTE: I massively changed this because it seemed to be completely broken, but there is also the possibility that
     *       it was right and I had no idea what I was doing. Keep in mind.
     * @param string $groupName is the name of the group to run for.
     * @param string $displayName is the desired display name of the group.
     * @return string containing messages detailing what it did.
     */
    public function syncPropertyGroup(
        $groupName = self::HUBSPOT_SOSURE_PROPERTY_NAME,
        $displayName = self::HUBSPOT_SOSURE_PROPERTY_DESC
    ) {
        $groups = $this->client->contactProperties()->getGroups();
        foreach ($groups->getData() as $group) {
            if ($group->name === $groupName) {
                return "Group named '$groupName' already exists.";
            }
        }
        try {
            $create = $this->client->contactProperties()->createGroup([
                "name" => $groupName,
                "displayName" => $displayName
            ]);
            if ($create->getStatusCode() !== 200) {
                return "Could not create group on Hubspot: ".json_encode($create);
            }
            $this->hubspotService->assertHubspotNotRateLimited($create);
            return "Group named '$groupName' created successfully.";
        } catch (BadRequest $exception) {
            return "Group named '$groupName' creation failed. ".$exception->getMessage();
        } catch (ClientException $exception) {
            return "Group named '$groupName' creation failed. ".$exception->getMessage();
        }
    }

    /**
     * Attempts to create a property on hubspot.
     * @param object $propertyData is the content of the property.
     * @param string $propertyName is the name of the property on hubspot.
     * @return string with a message detailing the success of the function.
     */
    private function createHubspotProperty($propertyData, $propertyName)
    {
        try {
            $this->client->contactProperties()->create($propertyData);
            return "<info>$propertyName</info> created on Hubspot.";
        } catch (\Exception $e) {
            return "property: <error>$propertyName</error> could not be created on Hubspot." . $e->getMessage();
        }
    }

    /**
     * Fields that, if they do not exist, will be created as properties in the 'sosure' group
     * @return array containing property data formatted for hubspot.
     */
    private function allPropertiesWithGroup()
    {
        return [
            [
                "name" => "gender",
                "label" => "gender",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "radio",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "male", "value" => "male"],
                    ["label" => "female", "value" => "female"],
                    ["label" => "x/not-known", "value" => "x"]
                ]
            ],
            [
                "name" => "date_of_birth",
                "label" => "Date of birth",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "date",
                "fieldType" => "date",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "facebook",
                "label" => "Facebook?",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "checkbox",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "yes", "value" => "yes"],
                    ["label" => "no", "value" => "no"]
                ],
            ],
            [
                "name" => "billing_address",
                "label" => "Billing address",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "textarea",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "census_subgroup",
                "label" => "Estimated census_subgroup",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "total_weekly_income",
                "label" => "Estimated total_weekly_income",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "attribution",
                "label" => "attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "latestattribution",
                "label" => "Latest attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "sosure_lifecycle_stage",
                "label" => "SO-SURE lifecycle stage",
                "description" => "Current stage in purchase-flow",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "select",
                "formField" => true,
                "displayOrder" => -1,
                "options" => [
                    ["label" => Api::QUOTE, "value" => "Quote"],
                    ["label" => Api::READY_FOR_PURCHASE, "value" => "Ready for purchase"],
                    ["label" => Api::PURCHASED, "value" => "Purchased"],
                    ["label" => Api::RENEWED, "value" => "Renewed"],
                    ["label" => Api::CANCELLED, "value" => "Cancelled"],
                    ["label" => Api::EXPIRED, "value" => "Expired"]
                ]
            ]
        ];
    }
}
