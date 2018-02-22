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
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Classes\SoSure;

class BICommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:bi')
            ->setDescription('Run a bi export')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'show debug output'
            )
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy prefix'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $debug = $input->getOption('debug');
        $prefix = $input->getOption('prefix');
        $lines = $this->exportPolicies($prefix);
        if ($debug) {
            $output->write(json_encode($lines, JSON_PRETTY_PRINT));
        }

        $lines = $this->exportClaims();
        if ($debug) {
            $output->write(json_encode($lines, JSON_PRETTY_PRINT));
        }

        $lines = $this->exportUsers();
        if ($debug) {
            $output->write(json_encode($lines, JSON_PRETTY_PRINT));
        }
    }

    private function exportClaims()
    {
        $search = $this->getContainer()->get('census.search');
        $repo = $this->getManager()->getRepository(Claim::class);
        $claims = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Policy Number"',
            '"Policy Start Date"',
            '"FNOL"',
            '"Postcode"',
            '"Claim Type"',
            '"Claim Status"',
            '"Claim Location"',
            '"Policy Cancellation Date"',
            '"Policy Cancellation Type"',
            '"Policy Connections"',
            '"Claim Suspected Fraud"',
            '"Policy upgraded"',
            '"Age of Policy Holder"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
            '"Latest Referer"',
            '"First Campaign Name"',
            '"First Campaign Source"',
            '"First Referer"',
            '"Claim Initial Suspicion"',
            '"Claim Final Suspicion"',
        ]);
        foreach ($claims as $claim) {
            $policy = $claim->getPolicy();
            // mainly for dev use
            if (!$policy) {
                $this->getContainer()->get('logger')->error(sprintf('Missing policy for claim %s', $claim->getId()));
                continue;
            }
            $user = $policy->getUser();
            $census = $search->findNearest($user->getBillingAddress()->getPostcode());
            $income = $search->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%s"', $policy->getStart()->format('Y-m-d H:i:s')),
                sprintf(
                    '"%s"',
                    $claim->getNotificationDate() ? $claim->getNotificationDate()->format('Y-m-d H:i:s') : ""
                ),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                sprintf('"%s"', $claim->getType()),
                sprintf('"%s"', $claim->getStatus()),
                sprintf('"%s"', $claim->getLocation()),
                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getEnd()->format('Y-m-d H:i:s') : ""),
                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getCancelledReason() : ""),
                sprintf('"%s"', count($policy->getStandardConnections())),
                'N/A',
                sprintf(
                    '"%s"',
                    $policy->getCancelledReason() && $policy->getCancelledReason() == Policy::CANCELLED_UPGRADE ?
                        'yes' :
                        'no'
                ),
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignName() : ''),
                sprintf(
                    '"%s"',
                    $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignSource() : ''
                ),
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getReferer() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getReferer() : ''),
                sprintf('"%s"', $claim->getInitialSuspicion() ? 'yes' : 'no'),
                sprintf('"%s"', $claim->getFinalSuspicion() ? 'yes' : 'no'),
            ]);
        }
        $this->uploadS3(implode(PHP_EOL, $lines), 'claims.csv');

        return $lines;
    }

    private function exportPolicies($prefix)
    {
        $search = $this->getContainer()->get('census.search');
        $repo = $this->getManager()->getRepository(PhonePolicy::class);
        $policies = $repo->findAllStartedPolicies($prefix);
        $lines = [];
        $lines[] = implode(',', [
            '"Policy Number"',
            '"Age of Policy Holder"',
            '"Postcode of Policy Holder"',
            '"Policy Start Date"',
            '"Policy End Date"',
            '"Policy Status"',
            '"Policy Holder Id"',
            '"Policy Cancellation Reason"',
            '"Requested Cancellation (Phone Damaged Prior To Policy)"',
            '"Total Number of Claims"',
            '"Number of Approved/Settled Claims"',
            '"Number of Withdrawn/Declined Claims"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
            '"Requested Cancellation Reason (Phone Damaged Prior To Policy)"',
            '"Policy Renewed"',
            '"Latest Referer"',
            '"First Campaign Name"',
            '"First Campaign Source"',
            '"First Referer"',
            '"Make"',
            '""Make/Model"',
        ]);
        foreach ($policies as $policy) {
            $user = $policy->getUser();
            $census = $search->findNearest($user->getBillingAddress()->getPostcode());
            $income = $search->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%s"', $policy->getPolicyNumber()),
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                sprintf('"%s"', $policy->getStart()->format('Y-m-d')),
                sprintf('"%s"', $policy->getEnd()->format('Y-m-d')),
                sprintf('"%s"', $policy->getStatus()),
                sprintf('"%s"', $user->getId()),
                sprintf('"%s"', $policy->getCancelledReason() ? $policy->getCancelledReason() : null),
                sprintf('"%s"', $policy->hasRequestedCancellation() ? 'yes' : 'no'),
                sprintf('"%s"', count($policy->getClaims())),
                sprintf('"%s"', count($policy->getApprovedClaims(true))),
                sprintf('"%s"', count($policy->getWithdrawnDeclinedClaims())),
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignName() : ''),
                sprintf(
                    '"%s"',
                    $user->getLatestAttribution() ? $user->getLatestAttribution()->getCampaignSource() : ''
                ),
                sprintf(
                    '"%s"',
                    $policy->getRequestedCancellationReason() ? $policy->getRequestedCancellationReason() : null
                ),
                sprintf('"%s"', $policy->isRenewed() ? 'yes' : 'no'),
                sprintf('"%s"', $user->getLatestAttribution() ? $user->getLatestAttribution()->getReferer() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getReferer() : ''),
                sprintf('"%s"', $policy->getPhone()->getMake()),
                sprintf('"%s %s"', $policy->getPhone()->getMake(), $policy->getPhone()->getModel()),
            ]);
        }
        $this->uploadS3(implode(PHP_EOL, $lines), 'policies.csv');

        return $lines;
    }

    private function exportUsers()
    {
        $search = $this->getContainer()->get('census.search');
        $repo = $this->getManager()->getRepository(User::class);
        $users = $repo->findAll();
        $lines = [];
        $lines[] = implode(',', [
            '"Age of User"',
            '"Postcode of User"',
            '"User Id"',
            '"User Created"',
            '"Purchased Policy"',
            '"Pen Portrait"',
            '"Gender"',
            '"Total Weekly Income"',
            '"Latest Campaign Name"',
            '"Latest Campaign Source"',
        ]);
        foreach ($users as $user) {
            if (!$user->getBillingAddress()) {
                continue;
            }
            $census = $search->findNearest($user->getBillingAddress()->getPostcode());
            $income = $search->findIncome($user->getBillingAddress()->getPostcode());
            $lines[] = implode(',', [
                sprintf('"%d"', $user->getAge()),
                sprintf('"%s"', $user->getBillingAddress()->getPostcode()),
                sprintf('"%s"', $user->getId()),
                sprintf('"%s"', $user->getCreated()->format('Y-m-d')),
                sprintf('"%s"', count($user->getCreatedPolicies()) > 0 ? 'yes' : 'no'),
                sprintf('"%s"', $census ? $census->getSubgrp() : ''),
                sprintf('"%s"', $user->getGender() ? $user->getGender() : ''),
                $income ? sprintf('"%0.0f"', $income->getTotal()->getIncome()) : '""',
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignName() : ''),
                sprintf('"%s"', $user->getAttribution() ? $user->getAttribution()->getCampaignSource() : ''),
            ]);
        }
        $this->uploadS3(implode(PHP_EOL, $lines), 'users.csv');

        return $lines;
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
        $s3Key = sprintf('%s/bi/%s', $this->getEnvironment(), $filename);

        $result = $this->getS3()->putObject(array(
            'Bucket' => 'admin.so-sure.com',
            'Key'    => $s3Key,
            'SourceFile' => $tmpFile,
        ));

        unlink($tmpFile);

        return $s3Key;
    }
}
