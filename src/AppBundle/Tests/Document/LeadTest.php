<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Tests\UserClassTrait;
use DateTime;
use Symfony\Bridge\PhpUnit\ClockMock;

/**
 * @group unit
 */
class LeadTest extends \PHPUnit\Framework\TestCase
{
    use UserClassTrait;

    public function testPopulateUserNoLeadSource()
    {
        $lead = new Lead();
        $lead->setSource(Lead::SOURCE_BUY);
        $user = new User();
        $lead->populateUser($user);
        $this->assertNull($user->getLeadSource());
    }

    public function testPopulateUserLeadSource()
    {
        $lead = new Lead();
        $lead->setSource(Lead::LEAD_SOURCE_SCODE);
        $user = new User();
        $lead->populateUser($user);
        $this->assertEquals(Lead::LEAD_SOURCE_SCODE, $user->getLeadSource());
    }
}
