<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Lead;
use AppBundle\Repository\UserRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\JudopayService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentRepository;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\EmailValidator;

class LeadsCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:leads')
            ->setDescription('Transform leads to users')
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process (-1 all)',
                1
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $process = $input->getOption('process');
        /** @var UserManagerInterface $userManager */
        $userManager = $this->getContainer()->get('fos_user.user_manager');
        /** @var UserRepository $userRepo */
        $userRepo = $this->getManager()->getRepository(User::class);
        /** @var DocumentRepository $leadsRepo */
        $leadsRepo = $this->getManager()->getRepository(Lead::class);
        $yesterday = new \DateTime();
        $yesterday = $yesterday->sub(new \DateInterval(('P1D')));
        $leads = $leadsRepo->findBy(['email' => ['$ne' => null], 'created' => ['$lte' => $yesterday]]);
        $count = 0;
        foreach ($leads as $lead) {
            /** @var Lead $lead */

            if ($process >= 0 && $count >= $process) {
                break;
            }
            $count++;

            if (mb_strlen($lead->getEmail()) < 5) {
                $output->writeln(sprintf('Deleting lead %s as invalid email', $lead->getEmail()));
                $this->getManager()->remove($lead);
                continue;
            }

            $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($lead->getEmail())]);
            // user exists, so lead can be removed
            if ($user) {
                $output->writeln(sprintf('Deleting lead %s as user exists', $lead->getEmail()));
                $this->getManager()->remove($lead);
            } else {
                $output->writeln(sprintf('Create user %s from lead', $lead->getEmail()));
                /** @var User $user */
                $user = $userManager->createUser();
                $user->setEnabled(true);
                $lead->populateUser($user);
                $this->getManager()->persist($user);
            }

            $this->getManager()->flush();
        }


        $output->writeln(sprintf('Finished. Processed %d of %d leads', $count, count($leads)));
    }
}
