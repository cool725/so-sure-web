<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Classes\SoSure;

class BICommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:bi')
            ->setDescription('Run a bi export')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->exportUsers();
        $this->exportClaims();
    }

    private function exportClaims()
    {
        $repo = $this->getManager()->getRepository(Claim::class);
        $claims = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Policy Number"',
            '"Policy Start Date"',
            '"FNOL"',
            '"Postcode"',
        ]);
        foreach ($claims as $claim) {
            $lines[] = implode(',', [
                sprintf('"%s"', $claim->getPolicy()->getPolicyNumber()),
                sprintf('"%s"', $claim->getPolicy()->getStart()->format('Y-m-d H:i:s')),
                sprintf(
                    '"%s"',
                    $claim->getNotificationDate() ? $claim->getNotificationDate()->format('Y-m-d H:i:s') : ""
                ),
                sprintf('"%s"', $claim->getPolicy()->getUser()->getBillingAddress()->getPostcode()),
            ]);
        }
        $this->uploadS3(implode(PHP_EOL, $lines), 'claims.csv');
    }

    private function exportUsers()
    {
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Age"',
            '"Postcode"',
        ]);
        foreach ($users as $user) {
            if ($user->hasValidPolicy()) {
                $lines[] = implode(',', [
                   sprintf('"%d"', $user->getAge()),
                   sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                ]);
            }
        }
        $this->uploadS3(implode(PHP_EOL, $lines), 'users.csv');
    }
    
    private function getManager()
    {
        return $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }
    
    public function getS3()
    {
        return $this->getContainer()->get("aws.s3");
    }

    public function getEnvironment()
    {
        return $this->getContainer()->getParameter("kernel.environment");
    }

    public function uploadS3($data, $filename)
    {
        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($tmpFile, $data);
        $s3Key = sprintf('%s/quicksight/%s', $this->getEnvironment(), $filename);

        $result = $this->getS3()->putObject(array(
            'Bucket' => 'admin.so-sure.com',
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));

        unlink($tmpFile);

        return $s3Key;
    }
}
