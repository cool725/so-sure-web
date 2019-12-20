<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use AppBundle\Document\Lead;

use AppBundle\Exception\InvalidEmailException;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\MailerService;

use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\CompetitionType;

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
        $dm = $this->getManager();
        $lead = new Lead();
        $lead->setSource(Lead::SOURCE_COMPETITION);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadEmailType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                try {
                    $leadForm->handleRequest($request);
                    if ($leadForm->isValid()) {
                        // TODO: Probably want to check if they've already entered
                        $leadRepo = $dm->getRepository(Lead::class);
                        $existingLead = $leadRepo->findOneBy(['email' => mb_strtolower($lead->getEmail())]);
                        if (!$existingLead) {
                            $dm->persist($lead);
                            $dm->flush();
                            $days = \DateTime::createFromFormat('U', time());
                            $days = $days->add(new \DateInterval(sprintf('P%dD', 14)));

                            // Add to Mixpanel
                            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                            $this->get('app.mixpanel')->queuePersonProperties([
                                '$email' => $lead->getEmail()
                            ], true);
                            // TODO: Create unique link and email the user
                            // We want to check for existing links too
                            // We don't want these guys falling in warm leads need to be cold 🧊
                            // Emails > competition

                            // Just for making a random link for testing
                            $randy = mb_substr(md5(uniqid(mt_rand(), true)), 0, 8);
                            $session = $this->get('session');
                            $session->set('randy', $randy);

                            return $this->redirectToRoute('enter_draw_questions');
                        } else {
                            // TODO: If Exisiting lead functionality
                            // TODO: Create unique link and email the user
                            // We want to check for existing links too
                            // Emails > competition
                            // Just for making a random link for testing
                            $randy = mb_substr(md5(uniqid(mt_rand(), true)), 0, 8);
                            $session = $this->get('session');
                            $session->set('randy', $randy);

                            return $this->redirectToRoute('enter_draw_questions');
                        }
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
        $questionsForm = $this->get('form.factory')
            ->createNamedBuilder('questions_form', CompetitionType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('questions_form')) {
                try {
                    $questionsForm->handleRequest($request);
                    if ($questionsForm->isValid()) {
                        // TODO: Record answers in DB??? Against user 🤔
                        // Send to confirm page to display
                        $session = $this->get('session');
                        $session->set('user-answers', $_POST);
                        return $this->redirectToRoute('enter_draw_confirm');
                    } else {
                        $this->addFlash('error', sprintf(
                            "Error"
                        ));
                    }
                } catch (\Exception $e) {
                    $this->addFlash('error', sprintf(
                        "Something is very wrong"
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

        // TODO: Match the results to the answers - this doesn't work just looks for matches
        $answers = ['C','C','C'];
        $ans = array_intersect($userAnswers['questions_form'], $answers);

        // Again for random visual testing purps - this will be the user code
        $code = $session->get('randy');

        $data = [
            'users_answers' => $userAnswers,
            'answers' =>  $answers,
            'ans' => $ans,
            'code' => $code,
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
        $shareCode = null;

        try {
            // TODO: Find lead in DB via code
            // This for test purps
            $shareCode = $code;
            $session = $this->get('session');
            $session->set('referred-by', $shareCode);
        } catch (\Exception $e) {
            $shareCode = null;
        }

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
            'share_code' => $shareCode,
        ];

        return $this->render('AppBundle:SCode:scodeCompetition.html.twig', $data);
    }

    private function competitorsData()
    {
        $competitor = [
            'PYB' => [
                'name' => 'Protect Your Bubble',
                'days' => '<strong>1 - 5</strong> days <div>depending on stock</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 4
            ],
            'GC' => [
                'name' => 'Gadget<br>Cover',
                'days' => '<strong>5 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>18 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'SS' => [
                'name' => 'Simplesurance',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'CC' => [
                'name' => 'CloudCover',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 3,
            ],
            'END' => [
                'name' => 'Endsleigh',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-check',
                'oldphones' => 'fa-check',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'LICI' => [
                'name' => 'Loveit<br>coverIt.co.uk',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'O2' => [
                'name' => 'O2',
                'days' => '<strong>1 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'From 02 only',
                'phoneage' => '<strong>29 days</strong> <div>O2 phones only</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1.5,
            ]
        ];

        return $competitor;
    }
}
