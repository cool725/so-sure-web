<?php

namespace App\Command;

use AppBundle\Document\Participation;
use AppBundle\Document\DateTrait;
use AppBundle\Service\PromotionService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A commmand that allows commandline management of the promotion service and promotions.
 */
class PromotionCommand extends ContainerAwareCommand
{
    use DateTrait;
    const SERVICE_NAME = 'sosure:admin:promotion';
    protected static $defaultName = self::SERVICE_NAME;

    /** @var PromotionService */
    private $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        parent::__construct();
        $this->promotionService = $promotionService;
    }

    protected function configure()
    {
        $this->setDescription('Updates the status of users participating in promotions.')
            ->addArgument(
                "date",
                InputArgument::OPTIONAL,
                "date to confirm rewards up to. Format: YYYYMMDD"
            )
            ->setHelp(
                "Used to update the status of users participating in promotions. This consists of emailing internally ".
                "when a user is due to receive an award, and failing promotion participations that did not manage to ".
                "stick to the requirements of the promotion (e.g. they were meant to not claim for a month but they ".
                "claimed). Also sends emails internally when a user is entered in a promotion for which they cannot ".
                "receive the reward (e.g. the reward is a tastecard but their account already has a tastecard)."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dateString = $input->getArgument('date');
        $date = null;
        if ($dateString) {
            $date = \DateTime::createFromFormat("YYYYMMDD", $dateString);
            if (!$date) {
                $output->writeln("<error>Invalid date format.</error>");
                return;
            }
        } else {
            $date = $this->startOfDay();
        }
        $rewards = $this->promotionService->generate(null, $date);
        $output->writeln("Promotion Command complete.");
        if (array_key_exists(Participation::STATUS_COMPLETED, $rewards)) {
            $output->writeln($rewards[Participation::STATUS_COMPLETED]." rewards given.");
        }
        if (array_key_exists(Participation::STATUS_FAILED, $rewards)) {
            $output->writeln($rewards[Participation::STATUS_FAILED]." participations failed.");
        }
        if (array_key_exists(Participation::STATUS_INVALID, $rewards)) {
            $output->writeln($rewards[Participation::STATUS_INVALID]." participations invalidated.");
        }
    }
}
