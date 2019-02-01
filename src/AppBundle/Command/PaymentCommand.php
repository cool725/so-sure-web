<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Payment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\BacsPaymentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Commandline interface for payment related functionality.
 */
class PaymentCommand extends ContainerAwareCommand
{
    const ACTION_LIST = 'list-unlinked';
    const ACTION_LINK = 'link-reversed';

    /** @var DocumentManager $dm */
    protected $dm;

    /**
     * Builds the command object.
     * @param DocumentManager $dm is used to access payment records.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * Sets up the command.
     */
    protected function configure()
    {
        $this->setName("sosure:payment")
            ->setDescription("Run some payment function.")
            ->addArgument(
                "action",
                InputOption::VALUE_REQUIRED,
                "list-unlinked|link-reverse"
            )
            ->setHelp(
                "<info>list-unlinked</info> action gives the total number of unlinked bacs backpayments, and the ".
                "number of those which can and cannot be linked, as well as showing the IDs of all proposed links.\n".
                "<info>link-reversed</info> links all those reversed bacs payments that are able to be automatically ".
                "linked, which should be the same payments counted as linkable by the <info>list-unlinked</info> ".
                "command."
            );
    }

    /**
     * Runs the command.
     * @param InputInterface  $input  is used to receive input.
     * @param OutputInterface $output is used to send output.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument("action");
        switch ($action) {
            case self::ACTION_LIST:
                $this->countUnlinked($output);
                break;
            case self::ACTION_LINK:
                $this->linkReversed();
                $output->writeln("Success.");
                break;
            default:
                $output->writeln("<error>A valid action was not given.</error>.");
        }
    }

    /**
     * Counts the number of reversed payments that are not linked to their reversing payment, and categorises them by
     * whether or not they can be automatically linked.
     * @return array with the keys 'linkable', 'unlinkable' containing the counts for those two categories.
     */
    private function countUnlinked($output)
    {
        $pairs = $this->getUnlinkedPaymentPairs();
        $linkable = 0;
        $unlinkable = 0;
        foreach ($pairs as $pair) {
            if ($pair[0] === null) {
                $reversal = $pair[1]->getId();
                $output->writeln("<info>{$reversal}</info> unlinkable");
                $unlinkable++;
            } else {
                $reversed = $pair[0]->getId();
                $reversal = $pair[1]->getId();
                $output->writeln("<info>{$reversed}</info> -> <info>{$reversal}</info>");
                $linkable++;
            }
        }
        $output->writeln("<info>linkable</info>: {$linkable}");
        $output->writeln("<info>unlinkable</info>: {$unlinkable}");
    }

    /**
     * Finds and links all reversed payments that are not currently linked but can be.
     */
    private function linkReversed()
    {
        $pairs = $this->getUnlinkedPaymentPairs();
        foreach ($pairs as $pair) {
            if ($pair[0] === null) {
                continue;
            }
            $pair[0]->addReverse($pair[1]);
        }
        $this->dm->flush();
    }

    /**
     * Gives you a list of pairs of unlinked bacs payments and backpayments.
     * @return array Each sub list is [payment, backpayment]. If an original payment could not be found then the payment
     *               slot will contain null.
     */
    private function getUnlinkedPaymentPairs()
    {
        /** @var BacsPaymentRepository $bacsPaymentRepo */
        $bacsPaymentRepo = $this->dm->getRepository(BacsPayment::class);
        $reversals = $bacsPaymentRepo->findUnlinkedReversals();
        $pairs = [];
        foreach ($reversals as $reversal) {
            $reversed = $bacsPaymentRepo->findReversed($reversal);
            if ($reversed) {
                $pairs[] = [$reversed, $reversal];
            } else {
                $pairs[] = [null, $reversal];
            }
        }
        return $pairs;
    }
}
