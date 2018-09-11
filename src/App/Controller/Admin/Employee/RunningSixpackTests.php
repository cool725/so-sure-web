<?php

namespace App\Controller\Admin\Employee;

use AppBundle\Service\SixpackService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/sixpack_tests", name="admin_sixpack_tests")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class RunningSixpackTests extends AbstractController
{
    /** @var SixpackService */
    private $sixpack;

    public function __construct(SixpackService $sixpack)
    {
        $this->sixpack = $sixpack;
    }

    public function __invoke()
    {
        $knownUnAuthedTests = SixpackService::$unauthExperiments;

        $allTests = [];
        foreach ($knownUnAuthedTests as $testName) {
            $allTests[$testName] = $this->sixpack->getOptionsAvailable($testName);
        }

        $tests = [
            'knownUnAuthedTests' => $knownUnAuthedTests,
            'allTests' => $allTests,
        ];

        return $this->render('AppBundle:AdminEmployee:sixpackTests.html.twig', $tests);
    }
}
