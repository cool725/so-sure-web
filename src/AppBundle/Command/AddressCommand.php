<?php

namespace AppBundle\Command;

use AppBundle\Service\PCAService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class AddressCommand extends ContainerAwareCommand
{
    /** @var PCAService */
    protected $pcaService;

    public function __construct(PCAService $pcaService)
    {
        parent::__construct();
        $this->pcaService = $pcaService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:pca:address')
            // @codingStandardsIgnoreStart
            ->setDescription('Query address from our lookup provider.  There is a 5p charge per request if --id or --address is used, so use with caution')
            // @codingStandardsIgnoreEnd
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'id'
            )
            ->addOption(
                'address',
                null,
                InputOption::VALUE_NONE,
                'run full address check'
            )
            ->addOption(
                'validate',
                null,
                InputOption::VALUE_NONE,
                'validate the postcode'
            )
            ->addArgument(
                'postcode',
                InputArgument::REQUIRED,
                'Postcode'
            )
            ->addArgument(
                'number',
                InputArgument::OPTIONAL,
                'House Number'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $postcode = $input->getArgument('postcode');
        $number = $input->getArgument('number');
        $useAddress = true === $input->getOption('address');
        $validate = true === $input->getOption('validate');
        /** @var string $id **/
        $id = $input->getOption('id');
        if ($id) {
            if ($addressData = $this->pcaService->retreive($id)) {
                $output->writeln(json_encode($addressData->toApiArray()));
            }
        } elseif ($useAddress) {
            if ($addresses = $this->pcaService->getAddress($postcode, $number)) {
                $output->writeln(json_encode($addresses->toApiArray()));
            }
        } elseif ($validate) {
            $result = $this->pcaService->validatePostcode($postcode, true);
            $output->writeln(sprintf('%s validation: %s', $postcode, $result ? 'true' : 'false'));
        } else {
            $addresses = $this->pcaService->find($postcode, $number);
            $output->writeln(json_encode($addresses));
        }
    }
}
