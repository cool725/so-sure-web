<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

use AppBundle\Classes\ApiErrorCode;

use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Lead;
use AppBundle\Document\User;

use AppBundle\Exception\InvalidEmailException;

use AppBundle\Service\MixpanelService;

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
     * @Route("/blog-lead-form", name="blog_lead_form")
     * @Template
     */
    public function blogLeadFormAction(Request $request)
    {
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        $data = [
            'lead_csrf' => $csrf->refreshToken('lead'),
        ];

        return $data;
    }

    /**
     * @Route("/blog-lead/{source}", name="blog_lead")
     */
    public function blogLeadAction(Request $request, $source)
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->validateFields(
            $data,
            ['email', 'csrf']
        )) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        if (!$this->isCsrfTokenValid('lead', $data['csrf'])) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid csrf', 422);
        }

        $email = $this->getDataString($data, 'email');

        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $leadRepo = $dm->getRepository(Lead::class);
        $existingLead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        // Add tracking - always catpure lead as we need to verify if exisiting users signed up
        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_CONTENTS_LEAD_CAPTURE, [
            'email' => $email]);

        if (!$existingLead && !$existingUser) {
            $lead = new Lead();
            $lead->setSource($source);
            $lead->setEmail($email);

            try {
                $this->validateObject($lead);
            } catch (InvalidEmailException $e) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid email format', 200);
            }
                $dm->persist($lead);
                $dm->flush();
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * @Route("/looking-back-and-forward", name="looking_back_and_forward", options={"sitemap" = false})
     * @Template
     */
    public function lookingBackAndForwardAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/looking-back-and-forward.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-to-do-if-you-break-your-phone-screen", name="what_to_do_if_you_break_your_phone_screen", options={"sitemap" = false})
     * @Template
     */
    public function whaToDoIfYouBreakYourPhoneScreenAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-to-do-if-you-break-your-phone-screen.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/5-great-gadgets-from-2018-for-the-january-sales", name="5_great_gadgets_from_2018_for_the_january_sales", options={"sitemap" = false})
     * @Template
     */
    public function greatGadgetsFrom2018ForTheJanuarySalesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/5-great-gadgets-from-2018-for-the-january-sales.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/all-i-want-for-christmas-is-a-new-phone", name="all_i_want_for_christmas_is_a_new_phone", options={"sitemap" = false})
     * @Template
     */
    public function allIWantForChristmasIsANewPhoneAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/all-i-want-for-christmas-is-a-new-phone.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/broken-promises", name="broken_promises", options={"sitemap" = false})
     * @Template
     */
    public function brokenPromisesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/broken-promises.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/so-sure-people-dylan-bourguignon", name="so_sure_people_dylan_bourguignon", options={"sitemap" = false})
     * @Template
     */
    public function soSurePeopleDylanBourguignonAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/so-sure-people-dylan-bourguignon.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/5-ways-to-protect-your-valuables-abroad", name="5_ways_to_protect_your_valuables_abroad", options={"sitemap" = false})
     * @Template
     */
    public function waysToProtectYourValuablesAbroadAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/5-ways-to-protect-your-valuables-abroad.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/englands-most-trusting-cities", name="englands_most_trusting_cities", options={"sitemap" = false})
     * @Template
     */
    public function englandsMostTrustingCitiesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/englands-most-trusting-cities.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/starling-bank-and-so-sure-team-up-to-offer-mobile-phone-insurance-through-the-starling-marketplace", name="starling_bank_and_so_sure_team_up_to_offer_mobile_phone_insurance_through_the_starling_marketplace", options={"sitemap" = false})
     * @Template
     */
    public function starlingBankAndSoSureAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/starling-bank-and-so-sure.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/dirty-tricks-to-watch-out-for-when-buying-insurance", name="dirty_tricks_to_watch_out_for_when_buying_insurance", options={"sitemap" = false})
     * @Template
     */
    public function dirtyTricksToWatchOutForAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/dirty-tricks-to-watch-out-for-when-buying-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/googles-pixel-3-takes-on-apples-iphone-xs", name="googles_pixel_3_takes_on_apples_iphone_xs", options={"sitemap" = false})
     * @Template
     */
    public function googlesPixel3TakesOnIPhoneAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/googles-pixel-3-takes-on-apples-iphone-xs.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-development-of-insurance-as-we-know-it", name="the_development_of_insurance_as_we_know_it", options={"sitemap" = false})
     * @Template
     */
    public function theDevelopmentOfInsuranceAsWeKnowItAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-development-of-insurance-as-we-know-it.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/introducing-social-insurance", name="introducing_social_insurance", options={"sitemap" = false})
     * @Template
     */
    public function introducingSocialInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/introducing-social-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsungs-note-9-takes-on-apples-iphone-x", name="samsungs_note_9_takes_on_apples_iphone_x", options={"sitemap" = false})
     * @Template
     */
    public function samsungsNote9TakesOnApplesIphoneXAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsungs-note-9-takes-on-apples-iphone-x.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-to-look-out-for-when-buying-phone-insurance", name="what_to_look_out_for_when_buying_phone_insurance", options={"sitemap" = false})
     * @Template
     */
    public function whatToLookOutForWhenBuyingPhoneInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-to-look-out-for-when-buying-phone-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-weird-and-wonderful-origins-of-insurance-from-the-babylonians-to-benjamin-franklin", name="the_weird_and_wonderful_origins_of_insurance_from_the_babylonians_to_benjamin_franklin", options={"sitemap" = false})
     * @Template
     */
    public function theWeirdAndWonderfulOriginsOfInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-weird-and-wonderful-origins-of-insurance-from-the-babylonians-to-benjamin-franklin.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-to-fix-a-problem-like-insurance", name="how_to_fix_a_problem_like_insurance", options={"sitemap" = false})
     * @Template
     */
    public function howToFixAProblemLikeInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-to-fix-a-problem-like-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-insurtech-revolution", name="the_insurtech_revolution", options={"sitemap" = false})
     * @Template
     */
    public function theInsurtechRevolutionAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-insurtech-revolution.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/the-internet-of-things", name="the_internet_of_things", options={"sitemap" = false})
     * @Template
     */
    public function theInternetOfThingsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/the-internet-of-things.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-galaxy-s9-versus-the-s9-plus", name="samsung_galaxy_s9_versus_the_s9_plus", options={"sitemap" = false})
     * @Template
     */
    public function samsungGalaxyS9VersusTheS9PlusAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-galaxy-s9-versus-the-s9-plus.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/mwc-2018-preview", name="mwc_2018_preview", options={"sitemap" = false})
     * @Template
     */
    public function mwc2018PreviewAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/mwc-2018-preview.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/money-saving-tips", name="money_saving_tips", options={"sitemap" = false})
     * @Template
     */
    public function moneySavingTipsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/money-saving-tips.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/mobile-phone-insurance-buying-guide", name="mobile_phone_insurance_buying_guide", options={"sitemap" = false})
     * @Template
     */
    public function mobilePhoneInsuranceBuyingGuideAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/mobile-phone-insurance-buying-guide.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/disruptive-technology-what-is-it", name="disruptive_technology_what_is_it", options={"sitemap" = false})
     * @Template
     */
    public function disruptiveTechnologyWhatIsItAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/disruptive-technology-what-is-it.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/our-top-5-winter-sports-insurance-tips", name="our_top_5_winter_sports_insurance_tips", options={"sitemap" = false})
     * @Template
     */
    public function ourTop5WinterSportsInsuranceTipsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/our-top-5-winter-sports-insurance-tips.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/3-technologies-that-will-shape-the-future-of-insurance", name="3_technologies_that_will_shape_the_future_of_insurance", options={"sitemap" = false})
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
     * @Route("/phone-insurance-guide", name="phone_insurance_guide", options={"sitemap" = false})
     * @Template
     */
    public function phoneInsuranceGuideAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/phone-insurance-guide.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/most-durable-phones", name="most_durable_phones", options={"sitemap" = false})
     * @Template
     */
    public function mostDurablePhonesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/most-durable-phones.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-galaxy-rumours", name="samsung_galaxy_rumours", options={"sitemap" = false})
     * @Template
     */
    public function samsungGalaxyS11S20RumoursAndNewsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-galaxy-s11-s20-rumours-and-news.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-battery-life-phones", name="best_battery_life_phones", options={"sitemap" = false})
     * @Template
     */
    public function bestBatteryLifePhonesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-battery-life-phones.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-phone-cases", name="best_phone_cases", options={"sitemap" = false})
     * @Template
     */
    public function bestPhoneCasesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-phone-cases.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/iphone-problems-and-solutions", name="iphone_problems_and_solutions", options={"sitemap" = false})
     * @Template
     */
    public function commoniPhoneProblemsAndSolutionsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/iphone-problems-and-solutions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-car-phone-mounts", name="best_car_phone_mounts", options={"sitemap" = false})
     * @Template
     */
    public function bestCarPhoneMountsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-car-phone-mounts.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/most-instagrammed-dog-breeds", name="most_instagrammed_dog_breeds", options={"sitemap" = false})
     * @Template
     */
    public function mostInstagrammedDogBreedsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/most-instagrammed-dog-breeds.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/why-does-my-phone-keep-crashing", name="why_does_my_phone_keep_crashing", options={"sitemap" = false})
     * @Template
     */
    public function whyDoesMyPhoneKeepCrashingAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/why-does-my-phone-keep-crashing.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-screen-protectors", name="best_screen_protectors", options={"sitemap" = false})
     * @Template
     */
    public function bestScreenProtectorsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-screen-protectors.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/applecare-vs-phone-insurance", name="applecare_vs_phone_insurance", options={"sitemap" = false})
     * @Template
     */
    public function appleCareVsPhoneInsuranceAction()
    {

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        // To display lowest monthly premium
        $iPhones = $repo->findBy([
            'active' => true,
            'topPhone' => true,
            'make' => 'Apple'
        ]);

        $data = [
            'iphones_prices' => $iPhones,
        ];

        $template = 'AppBundle:Blog:Articles/applecare-vs-phone-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-phone-security-apps", name="best_phone_security_apps", options={"sitemap" = false})
     * @Template
     */
    public function bestPhoneSecurityAppsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-phone-security-apps.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/new-iphone-se2-rumour-hub", name="new_iphone_se2_rumour_hub", options={"sitemap" = false})
     * @Template
     */
    public function newiPhoneSe2RumourHubAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/new-iphone-se2-rumour-hub.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/why-is-my-phone-so-hot", name="why_is_my_phone_so_hot", options={"sitemap" = false})
     * @Template
     */
    public function whyIsMyPhoneSoHotAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/why-is-my-phone-so-hot.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/common-mobile-phone-faults-and-solutions",
     * name="common_mobile_phone_faults_and_solutions", options={"sitemap" = false})
     * @Template
     */
    public function commonMobilePhoneFaultsAndSolutionsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/common-mobile-phone-faults-and-solutions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-galaxy-tips-and-tricks",
     * name="samsung_galaxy_tips_and_tricks", options={"sitemap" = false})
     * @Template
     */
    public function samsungGalaxyTipsAndTricksAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-galaxy-tips-and-tricks.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-to-fix-cracked-phone-screen",
     * name="how_to_fix_cracked_phone_screen", options={"sitemap" = false})
     * @Template
     */
    public function howToFixCrackedPhoneScreenAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-to-fix-cracked-phone-screen.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/phone-insurance-vs-contents-insurance",
     * name="phone_insurance_vs_contents_insurance", options={"sitemap" = false})
     * @Template
     */
    public function phoneInsuranceVsContentsInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/phone-insurance-vs-contents-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/new-huawei-release-rumour-hub",
     * name="new_huawei_release_rumour_hub", options={"sitemap" = false})
     * @Template
     */
    public function newHuaweiReleaseRumourHubAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/new-huawei-release-rumour-hub.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-phone-running-armbands",
     * name="best_phone_running_armbands", options={"sitemap" = false})
     * @Template
     */
    public function bestPhoneRunningArmbandsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-phone-running-armbands.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/best-waterproof-phone-cases",
     * name="best_waterproof_phone_cases", options={"sitemap" = false})
     * @Template
     */
    public function bestWaterproofPhoneCasesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/best-waterproof-phone-cases.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/uk-phone-statistics",
     * name="uk_phone_statistics", options={"sitemap" = false})
     * @Template
     */
    public function ukPhoneStatisticsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/uk-phone-statistics.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/sony-xperia-1-II-release-rumours",
     * name="sony_xperia_1_II_release_rumours", options={"sitemap" = false})
     * @Template
     */
    public function sonyXperia1IIReleaseRumoursAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/sony-xperia-1-II-release-rumours.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-long-do-mobile-phones-last",
     * name="how_long_do_mobile_phones_last", options={"sitemap" = false})
     * @Template
     */
    public function howLongDoMobilePhonesLastAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-long-do-mobile-phones-last.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/halloween-horror-stories",
     * name="halloween_horror_stories", options={"sitemap" = false})
     * @Template
     */
    public function halloweenHorrorStoriesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/halloween-horror-stories.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/excessive-phone-use-can-lead-to-stress-and-anxiety",
     * name="excessive_phone_use_can_lead_to_stress_and_anxiety", options={"sitemap" = false})
     * @Template
     */
    public function excessivePhoneUseCanLeadToStressAndAnxietyAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/excessive-phone-use-can-lead-to-stress-and-anxiety.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-is-contents-insurance",
     * name="what_is_contents_insurance", options={"sitemap" = false})
     * @Template
     */
    public function whatIsContentsInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-is-contents-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/stay-at-home",
     * name="stay_at_home", options={"sitemap" = false})
     * @Template
     */
    public function stayAtHomeAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/stay-at-home.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-is-phone-cosmetic-damage",
     * name="what_is_phone_cosmetic_damage", options={"sitemap" = false})
     * @Template
     */
    public function whatIsPhoneCosmeticDamageAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-is-phone-cosmetic-damage.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/contents-insurance-in-a-shared-house",
     * name="contents_insurance_in_a_shared_house", options={"sitemap" = false})
     * @Template
     */
    public function contentsInsuranceInASharedHouseAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/contents-insurance-in-a-shared-house.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-is-a-single-article-limit",
     * name="what_is_a_single_article_limit", options={"sitemap" = false})
     * @Template
     */
    public function whatIsASingleArticleLimitAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-is-a-single-article-limit.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/is-contents-insurance-mandatory",
     * name="is_contents_insurance_mandatory", options={"sitemap" = false})
     * @Template
     */
    public function isContentsInsuranceMandatoryAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/is-contents-insurance-mandatory.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/a-guide-to-student-contents-insurance",
     * name="a_guide_to_student_contents_insurance", options={"sitemap" = false})
     * @Template
     */
    public function aGuideToStudentContentsInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/a-guide-to-student-contents-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-counts-as-high-value-contents",
     * name="what_counts_as_high_value_contents", options={"sitemap" = false})
     * @Template
     */
    public function whatCountsAsHighValueContentsAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-counts-as-high-value-contents.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-to-transfer-data-to-your-new-phone",
     * name="how_to_transfer_data_to_your_new_phone", options={"sitemap" = false})
     * @Template
     */
    public function howToTransferDataToYourNewPhoneAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-to-transfer-data-to-your-new-phone.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/samsung-care-plus-vs-phone-insurance",
     * name="samsung_care_plus_vs_phone_insurance", options={"sitemap" = false})
     * @Template
     */
    public function samsungCarePlusVsPhoneInsuranceAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/samsung-care-plus-vs-phone-insurance.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/dog-damage-and-other-findings-from-our-new-research",
     * name="dog_damage_and_other_findings_from_our_new_research", options={"sitemap" = false})
     * @Template
     */
    public function dogDamageAndOtherFindingsFromOurNewResearchAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/dog-damage-and-other-findings-from-our-new-research.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/cost-of-living-in-fictional-homes",
     * name="cost_of_living_in_fictional_homes", options={"sitemap" = false})
     * @Template
     */
    public function costOfLivingInFictionalHomesAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/cost-of-living-in-fictional-homes.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/what-does-phone-insurance-cover",
     * name="what_does_phone_insurance_cover", options={"sitemap" = false})
     * @Template
     */
    public function whatDoesPhoneInsuranceCoverAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/what-does-phone-insurance-cover.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/how-to-make-a-successful-mobile-insurance-claim",
     * name="how_to_make_a_successful_mobile_insurance_claim", options={"sitemap" = false})
     * @Template
     */
    public function howToMakeASuccessfulMobileInsuranceClaimAction()
    {
        $data = [];

        $template = 'AppBundle:Blog:Articles/how-to-make-a-successful-mobile-insurance-claim.html.twig';

        return $this->render($template, $data);
    }
}
