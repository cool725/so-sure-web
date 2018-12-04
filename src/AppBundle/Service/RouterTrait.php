<?php
namespace AppBundle\Service;

use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;

trait RouterTrait
{
    public function getCampaign($template)
    {
        // print $subject;
        // base campaign on template name
        // AppBundle:Email:quote/priceGuarentee.html.twig
        $campaign = $template;
        if (mb_stripos($campaign, ':')) {
            $campaignItems = explode(':', $campaign);
            $campaign = $campaignItems[count($campaignItems) - 1];
        }
        $campaign = explode('.', $campaign)[0];

        return $campaign;
    }
}
