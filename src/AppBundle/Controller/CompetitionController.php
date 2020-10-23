<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use AppBundle\Document\Lead;
use AppBundle\Document\User;
use AppBundle\Document\Draw\Draw;
use AppBundle\Document\Draw\Entry;

use AppBundle\Exception\InvalidEmailException;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\MailerService;

use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\CompetitionType;

use AppBundle\Classes\Competitors;

/**
 * @Route("/enter-draw")
 */
class CompetitionController extends BaseController
{
    /**
     * @Route("/", name="enter_draw")
     * @Template
     * Take the lead and create the unique code
     */
    public function enterDrawAction(Request $request)
    {
        $ip = $request->getClientIp();
        $lead = new Lead();
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadEmailType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                try {
                    $leadForm->handleRequest($request);
                    if ($leadForm->isValid()) {
                        $dm = $this->getManager();
                        $leadRepo = $dm->getRepository(Lead::class);
                        if ($ip) {
                            $existingIps = $leadRepo->findBy(['ip' => $ip]);
                            if ($existingIps && count($existingIps) > 15) {
                                // @codingStandardsIgnoreStart
                                $err = 'It looks like you\'ve already entered the draw, not to worry you can share to get more entries 🤗';
                                // @codingStandardsIgnoreEnd
                                $this->addFlash('error', $err);
                                return $this->redirectToRoute('enter_draw');
                            }
                        }
                        $existingLead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($lead->getEmail())]);
                        $userRepo = $dm->getRepository(User::class);
                        $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($lead->getEmail())]);
                        if ($existingUser) {
                            // @codingStandardsIgnoreStart
                            $err = 'It looks like you already have an account. Log in and share your scode page to enter';
                            // @codingStandardsIgnoreEnd
                            $this->addFlash('error', $err);
                            return $this->redirectToRoute('fos_user_security_login');
                        } elseif ($existingLead) {
                            if ($existingLead->getShareCode()) {
                                // @codingStandardsIgnoreStart
                                $err = 'It looks like you\'ve already entered the draw, check your emails to get your unique link and share to get more entries 🏆';
                                // @codingStandardsIgnoreEnd
                                $this->addFlash('warning', $err);
                                return $this->redirectToRoute('enter_draw');
                            } else {
                                $lead = $existingLead;
                            }
                        } else {
                            $lead->setSource(Lead::SOURCE_COMPETITION);

                            // Add to Mixpanel
                            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                            $this->get('app.mixpanel')->queuePersonProperties([
                                '$email' => $lead->getEmail()
                            ], true);
                        }

                        $emailcode = explode("@", mb_strtolower($lead->getEmail()))[0];
                        if (mb_strlen($emailcode)>5) {
                            $emailcode = mb_substr($emailcode, 0, 6);
                        }
                        $code = uniqid($emailcode);

                        $lead->setShareCode($code);
                        $lead->setIp($ip);

                        $drawRepo = $dm->getRepository(Draw::class);

                        $draw = $drawRepo->findOneBy(
                            [
                                'active' => true,
                                'current' => true,
                                'type' => 'virality'
                            ]
                        );

                        if ($draw) {
                            $entry = new Entry;
                            $entry->setLead($lead);
                            $dm->persist($entry);
                            $draw->addEntry($entry);
                        } else {
                            $this->get('logger')->error('Entry failed, No active virality draw found');
                            // @codingStandardsIgnoreStart
                            $err = 'Oops, something went wrong, please try again later';
                            // @codingStandardsIgnoreEnd
                            $this->addFlash('error', $err);
                            $dm->clear();
                            return $this->redirectToRoute('enter_draw');
                        }

                        $dm->persist($lead);
                        $dm->flush();

                        /** @var MailerService $mailer */
                        $mailer = $this->get('app.mailer');

                        $mailer->sendTemplate(
                            sprintf('Thanks for entering the draw'),
                            'no-reply@so-sure.com',
                            'AppBundle:Email:competition/entered.html.twig',
                            ['code' => $code]
                        );

                        $session = $this->get('session');
                        $session->set('shareCode', $code);
                        $session->remove('user-answers');

                        return $this->redirectToRoute('enter_draw_questions');
                    }
                } catch (InvalidEmailException $ex) {
                    $this->get('logger')->info('Failed validation.', ['exception' => $ex]);
                    $this->addFlash('error', sprintf(
                        "Sorry, didn't quite catch that email. Please try again."
                    ));
                }
            }
        }

        $data = [
            'lead_form' => $leadForm->createView(),
        ];

        return $this->render('AppBundle:Competition:enterDraw.html.twig', $data);
    }

    /**
     * @Route("/questions", name="enter_draw_questions")
     * @Template
     * Questions
     */
    public function enterDrawQuestionsAction(Request $request)
    {
        $session = $this->get('session');
        $questionsForm = $this->get('form.factory')
            ->createNamedBuilder('questions_form', CompetitionType::class)
            ->getForm();
        $session = $this->get('session');
        $shareCode = $session->get('shareCode');
        $userAnswers = $session->get('user-answers');
        if (!$shareCode) {
            return $this->redirectToRoute('enter_draw');
        } elseif ($userAnswers) {
            return $this->redirectToRoute('enter_draw_confirm');
        }
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('questions_form')) {
                try {
                    $questionsForm->handleRequest($request);
                    if ($questionsForm->isValid()) {
                        // TODO: Record answers in DB
                        $dm = $this->getManager();
                        $drawRepo = $dm->getRepository(Draw::class);

                        $draw = $drawRepo->findOneBy(
                            [
                                'active' => true,
                                'current' => true,
                                'type' => 'virality'
                            ]
                        );

                        if ($draw) {
                            $leadRepo = $dm->getRepository(Lead::class);
                            $shareCode = $session->get('shareCode');
                            $lead = $leadRepo->findOneBy(['shareCode' => $shareCode]);
                            if ($lead) {
                                $entry = new Entry;
                                $entry->setLead($lead);
                                $dm->persist($entry);
                                $draw->addEntry($entry);
                                $dm->flush();
                            }
                        } else {
                            $this->get('logger')->error('Entry failed, No active virality draw found');
                            // @codingStandardsIgnoreStart
                            $err = 'Oops, something went wrong, please try again later';
                            // @codingStandardsIgnoreEnd
                            $this->addFlash('error', $err);
                        }

                        $session->set('user-answers', $_POST);
                        return $this->redirectToRoute('enter_draw_confirm');
                    }
                } catch (\Exception $e) {
                    $this->addFlash('error', sprintf(
                        "Oops, something went wrong, please try again later"
                    ));
                }
            }
        }
        $data = [
            'questions_form' => $questionsForm->createView(),
        ];

        return $this->render('AppBundle:Competition:enterDrawQuestions.html.twig', $data);
    }

    /**
     * @Route("/finish", name="enter_draw_confirm")
     * @Template
     * Confirmation page with quote and unique link
     */
    public function enterDrawConfirmedAction()
    {

        // Grab the answers from the session
        $session = $this->get('session');
        $userAnswers = $session->get('user-answers');
        $shareCode = $session->get('shareCode');

        if (!$userAnswers && $shareCode) {
            return $this->redirectToRoute('enter_draw_questions');
        } elseif (!$userAnswers) {
            return $this->redirectToRoute('enter_draw');
        }

        $answers = ['C','C','C'];
        $ans = array_intersect($userAnswers['questions_form'], $answers);

        $shareCode = $session->get('shareCode');

        $data = [
            'users_answers' => $userAnswers,
            'answers' =>  $answers,
            'ans' => $ans,
            'code' => $shareCode,
        ];

        return $this->render('AppBundle:Competition:enterDrawConfirmed.html.twig', $data);
    }

    /**
     * @Route("/{code}", name="enter_draw_code")
     * @Template
     * For tracking non-users but people who enter
     */
    public function enterDrawCodeAction($code)
    {
        $shareCode = $code;

        if ($shareCode) {
            $dm = $this->getManager();
            $leadRepo = $dm->getRepository(Lead::class);
            $lead = $leadRepo->findOneBy(['shareCode' => $shareCode]);
            if ($lead) {
                $session = $this->get('session');
                $session->set('referred-by', $shareCode);
            } else {
                if ($shareCode != 'enternow') {
                    return $this->redirectToRoute('enter_draw_code', ['code' => 'enternow']);
                } else {
                    $shareCode = null;
                }
            }
        } else {
            $shareCode = null;
        }

        $competitorData = new Competitors();

        $data = [
            'competitor' => $competitorData::$competitorComparisonData,
            'share_code' => $shareCode,
        ];

        return $this->render('AppBundle:SCode:scodeCompetition.html.twig', $data);
    }
}
