<?php

namespace AppBundle\Command;

use AppBundle\Document\Draw\Draw;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class DrawCommand extends ContainerAwareCommand
{
    const TYPE = 'virality';

    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:draw')
            ->setDescription('Manage draws')
            ->addOption(
                'init',
                null,
                InputOption::VALUE_NONE,
                'Create a draw'
            )
            ->addOption(
                'deactivate',
                null,
                InputOption::VALUE_NONE,
                'Deactivate the monthly draw'
            )
            ->addOption(
                'activate',
                null,
                InputOption::VALUE_NONE,
                'Activate the monthly draw'
            )
            ->addOption(
                'export',
                null,
                InputOption::VALUE_NONE,
                'Export the active draw entries'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $init = true === $input->getOption('init');
        $deactivate = true === $input->getOption('deactivate');
        $activate = true === $input->getOption('activate');
        $export = true === $input->getOption('activate');

        if ($init) {
            $draw = $this->getActiveDraw();
            if ($draw) {
                $output->writeln("An active Virality draw already exists!");
                return;
            } else {
                $draw = new Draw;
                $draw->setName('Virality01');
                $draw->setType(self::TYPE);
                $draw->setDescription('Virality draw competition');
                $draw->setActive(true);
                $draw->setCurrent(true);

                $this->dm->persist($draw);
                $this->dm->flush();

                $output->writeln("Virality draw initialised");
            }
        } elseif ($deactivate) {
            $draw = $this->getCurrentDraw();
            if ($draw->getActive()) {
                $draw->setActive(false);
                $this->dm->flush();
                $output->writeln("Virality draw deactivated");
            } else {
                $output->writeln("Virality draw already inactive");
            }
        } elseif ($activate) {
            $draw = $this->getCurrentDraw();
            if ($draw->getActive()) {
                $output->writeln("Virality draw already active");
            } else {
                $draw->setActive(true);
                $this->dm->flush();
                $output->writeln("Virality draw activated");
            }
        } elseif ($export) {
            $output->writeln("Export unavailable : work in progress");
        } else {
            $output->writeln("Please select an option");
        }
    }

    private function getDrawRepo()
    {
        $drawRepo = $this->dm->getRepository(Draw::class);
        return $drawRepo;
    }

    private function getActiveDraw()
    {
        $drawRepo = $this->getDrawRepo();
        /** @var Draw $draw **/
        $draw = $drawRepo->findOneBy(
            [
                'active' => true,
                'current' => true,
                'type' => self::TYPE
            ]
        );
        return $draw;
    }

    private function getCurrentDraw()
    {
        $drawRepo = $this->getDrawRepo();
        /** @var Draw $draw **/
        $draw = $drawRepo->findOneBy(
            [
                'current' => true,
                'type' => self::TYPE
            ]
        );
        return $draw;
    }
}
