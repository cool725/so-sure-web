<?php

namespace AppBundle\Controller;

use AppBundle\Form\Type\ContactUsType;
use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use AppBundle\Document\Lead;

/**
 * @Route("/blog")
 */
class BlogController extends BaseController
{
    /** @codingStandardsIgnoreStart */

    /**
     * @Route("/", name="blog_index", options={"sitemap" = true})
     * @Template
     */
    public function blogAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:index.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/looking-back-and-forward", name="looking_back_and_forward", options={"sitemap" = true})
     * @Template
     */
    public function lookingBackAndForwardAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/looking-back-and-forward.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-to-do-if-you-break-your-phone-screen", name="what_to_do_if_you_break_your_phone_screen", options={"sitemap" = true})
     * @Template
     */
    public function whaToDoIfYouBreakYourPhoneScreenAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-to-do-if-you-break-your-phone-screen.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/5-great-gadgets-from-2018-for-the-january-sales", name="5_great_gadgets_from_2018_for_the_january_sales", options={"sitemap" = true})
     * @Template
     */
    public function greatGadgetsFrom2018ForTheJanuarySalesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/5-great-gadgets-from-2018-for-the-january-sales.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/all-i-want-for-christmas-is-a-new-phone", name="all_i_want_for_christmas_is_a_new_phone", options={"sitemap" = true})
     * @Template
     */
    public function allIWantForChristmasIsANewPhoneAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/all-i-want-for-christmas-is-a-new-phone.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/broken-promises", name="broken_promises", options={"sitemap" = true})
     * @Template
     */
    public function brokenPromisesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/broken-promises.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/so-sure-people-dylan-bourguignon", name="so_sure_people_dylan_bourguignon", options={"sitemap" = true})
     * @Template
     */
    public function soSurePeopleDylanBourguignonAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/so-sure-people-dylan-bourguignon.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/5-ways-to-protect-your-valuables-abroad", name="5_ways_to_protect_your_valuables_abroad", options={"sitemap" = true})
     * @Template
     */
    public function waysToProtectYourValuablesAbroadAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/5-ways-to-protect-your-valuables-abroad.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/englands-most-trusting-cities", name="englands_most_trusting_cities", options={"sitemap" = true})
     * @Template
     */
    public function englandsMostTrustingCitiesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/englands-most-trusting-cities.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/starling-bank-and-so-sure-team-up-to-offer-mobile-phone-insurance-through-the-starling-marketplace", name="starling_bank_and_so_sure_team_up_to_offer_mobile_phone_insurance_through_the_starling_marketplace", options={"sitemap" = true})
     * @Template
     */
    public function starlingBankAndSoSureAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/starling-bank-and-so-sure.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/dirty-tricks-to-watch-out-for-when-buying-insurance", name="dirty_tricks_to_watch_out_for_when_buying_insurance", options={"sitemap" = true})
     * @Template
     */
    public function dirtyTricksToWatchOutForAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/dirty-tricks-to-watch-out-for-when-buying-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/googles-pixel-3-takes-on-apples-iphone-xs", name="googles_pixel_3_takes_on_apples_iphone_xs", options={"sitemap" = true})
     * @Template
     */
    public function googlesPixel3TakesOnIPhoneAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/googles-pixel-3-takes-on-apples-iphone-xs.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-development-of-insurance-as-we-know-it", name="the_development_of_insurance_as_we_know_it", options={"sitemap" = true})
     * @Template
     */
    public function theDevelopmentOfInsuranceAsWeKnowItAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-development-of-insurance-as-we-know-it.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/introducing-social-insurance", name="introducing_social_insurance", options={"sitemap" = true})
     * @Template
     */
    public function introducingSocialInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/introducing-social-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsungs-note-9-takes-on-apples-iphone-x", name="samsungs_note_9_takes_on_apples_iphone_x", options={"sitemap" = true})
     * @Template
     */
    public function samsungsNote9TakesOnApplesIphoneXAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsungs-note-9-takes-on-apples-iphone-x.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-to-look-out-for-when-buying-phone-insurance", name="what_to_look_out_for_when_buying_phone_insurance", options={"sitemap" = true})
     * @Template
     */
    public function whatToLookOutForWhenBuyingPhoneInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-to-look-out-for-when-buying-phone-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-weird-and-wonderful-origins-of-insurance-from-the-babylonians-to-benjamin-franklin", name="the_weird_and_wonderful_origins_of_insurance_from_the_babylonians_to_benjamin_franklin", options={"sitemap" = true})
     * @Template
     */
    public function theWeirdAndWonderfulOriginsOfInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-weird-and-wonderful-origins-of-insurance-from-the-babylonians-to-benjamin-franklin.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-to-fix-a-problem-like-insurance", name="how_to_fix_a_problem_like_insurance", options={"sitemap" = true})
     * @Template
     */
    public function howToFixAProblemLikeInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-to-fix-a-problem-like-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-insurtech-revolution", name="the_insurtech_revolution", options={"sitemap" = true})
     * @Template
     */
    public function theInsurtechRevolutionAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-insurtech-revolution.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-internet-of-things", name="the_internet_of_things", options={"sitemap" = true})
     * @Template
     */
    public function theInternetOfThingsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-internet-of-things.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-galaxy-s9-versus-the-s9-plus", name="samsung_galaxy_s9_versus_the_s9_plus", options={"sitemap" = true})
     * @Template
     */
    public function samsungGalaxyS9VersusTheS9PlusAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-galaxy-s9-versus-the-s9-plus.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/mwc-2018-preview", name="mwc_2018_preview", options={"sitemap" = true})
     * @Template
     */
    public function mwc2018PreviewAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/mwc-2018-preview.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/money-saving-tips", name="money_saving_tips", options={"sitemap" = true})
     * @Template
     */
    public function moneySavingTipsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/money-saving-tips.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/mobile-phone-insurance-buying-guide", name="mobile_phone_insurance_buying_guide", options={"sitemap" = true})
     * @Template
     */
    public function mobilePhoneInsuranceBuyingGuideAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/mobile-phone-insurance-buying-guide.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/disruptive-technology-what-is-it", name="disruptive_technology_what_is_it", options={"sitemap" = true})
     * @Template
     */
    public function disruptiveTechnologyWhatIsItAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/disruptive-technology-what-is-it.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/our-top-5-winter-sports-insurance-tips", name="our_top_5_winter_sports_insurance_tips", options={"sitemap" = true})
     * @Template
     */
    public function ourTop5WinterSportsInsuranceTipsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/our-top-5-winter-sports-insurance-tips.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/3-technologies-that-will-shape-the-future-of-insurance", name="3_technologies_that_will_shape_the_future_of_insurance", options={"sitemap" = true})
     * @Template
     */
    public function technologiesThatWillShapeTheFutureOfInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/3-technologies-that-will-shape-the-future-of-insurance.html.twig';

        return $this->render($template, $data);
    }
    /** @codingStandardsIgnoreEnd */

    /**
     * @Route("/phone-insurance-guide", name="phone_insurance_guide", options={"sitemap" = true})
     * @Template
     */
    public function phoneInsuranceGuideAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/phone-insurance-guide.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/most-durable-phones", name="most_durable_phones", options={"sitemap" = true})
     * @Template
     */
    public function mostDurablePhonesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/most-durable-phones.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-galaxy-rumours", name="samsung_galaxy_rumours", options={"sitemap" = true})
     * @Template
     */
    public function samsungGalaxyS11S20RumoursAndNewsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-galaxy-s11-s20-rumours-and-news.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-battery-life-phones", name="best_battery_life_phones", options={"sitemap" = true})
     * @Template
     */
    public function bestBatteryLifePhonesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-battery-life-phones.html.twig';

        return $this->render($template, $data);
    }
}
