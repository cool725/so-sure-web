<?php

namespace AppBundle\Controller;

use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Form\Type\LeadPosType;
use Doctrine\ODM\MongoDB\DocumentManager;
use PharIo\Manifest\Email;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;

/**
 * @Route("/instore")
 */
class InStoreController extends BaseController
{
    /**
     * @Route("/{store}", name="instore_store")
     * @Template()
     */
    public function inStoreAction(Request $request, $store = null)
    {
        // Set session - needed for purchase flow
        // TODO: Store uri for complete page to go back to ?
        $session = $this->get('session');
        $session->set('store', $store);

        // Template
        $template = 'AppBundle:InStore:indexInStore.html.twig';

        // Data
        $data = [
            'store' => $store,
        ];

        // Check if 'make' parameter exists - then query for search
        // use ?type={make}
        if ($request->query->has('make')) {
            // Get make
            $dm = $this->getManager();
            $repo = $dm->getRepository(Phone::class);
            $make = $request->get('make');
            $phones = $repo->findBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make)
            ]);

            if (count($phones) != 0) {
                $phone = $phones[0];
            } else {
                $phone = $repo->findOneBy([
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                ]);
            }

            // To display in Popular Models sections
            $topPhones = $repo->findBy([
                'active' => true,
                'topPhone' => true,
                'makeCanonical' => mb_strtolower($make)
            ]);

            // If phone add to data
            if ($phone) {
                $data['phone'] = $phone;
                $data['top_phones'] = $topPhones;
            }
        }

        return $this->render($template, $data);
    }
}
