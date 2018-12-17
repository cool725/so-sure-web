<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Attribution;

/**
 * @group unit
 */
class AttributionTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testRefererConform()
    {
        $attribution = new Attribution();
        // @codingStandardsIgnoreStart
        $attribution->setReferer('https://mg.mail.yahoo.com/neo/rd?s=cA.lBygDeL4.DeewGiHtP59uy1wiLzu4ELDVPfJEBqtFtxfXgrLuUZ_M6DxWewN9pPKCI2bwwA4Pe4zign1u9jpvyxNd2LcgHrwj6RLf1UQUf23_zQb.y2h6NlkqNBdb7ydW4GRby.C.dDiXBmlbPNOjOZRB4NPsh_x.oVGKA9uyN0Yb0Fc2MzTrkDjUxrvhUTWu~A&ncrumb=1JSX.2y6ghv');
        $this->assertEquals(
            'https://mg.mail.yahoo.com/neo/rd?s=cA.lBygDeL4.DeewGiHtP59uy1wiLzu4ELDVPfJEBqtFtxfXgrLuUZ_M6DxWewN9pPKCI2bwwA4Pe4zign1u9jpvyxNd2LcgHrwj6RLf1UQUf23_zQb.y2h6NlkqNBdb7ydW4GRby.C.dDiXBmlbPNOjOZRB4NPsh_x.oVGKA9uyN0Yb0Fc2MzTrkDjUxrvhUTWuA&ncrumb=1JSX.2y6ghv',
            $attribution->getReferer()
        );
        // @codingStandardsIgnoreEnd
    }

    /**
     * Make sure attribution can be created with only campaign source set.
     */
    public function testEmptyAttribution()
    {
        $attribution = new Attribution();
        $attribution->setCampaignSource(Attribution::SOURCE_UNTRACKED);
        $this->assertNull($attribution->getCampaignName());
        $this->assertEquals(Attribution::SOURCE_UNTRACKED, $attribution->getCampaignSource());
        $this->assertNull($attribution->getCampaignTerm());
        $this->assertNull($attribution->getCampaignMedium());
    }
}
