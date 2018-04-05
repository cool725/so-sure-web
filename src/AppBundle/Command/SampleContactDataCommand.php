<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Invitation\EmailInvitation;
use Faker;

class SampleContactDataCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:sample:contacts')
            ->setDescription('Email reinvitations')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getContacts();
    }

    public function getContacts()
    {
        $faker = Faker\Factory::create('en_GB');

        for ($i = 0; $i < 3000; $i++) {
            $firstName = $faker->firstName;
            $lastName = $faker->lastName;
            print sprintf(
                '%s,%s,%s,%s' . PHP_EOL,
                $firstName,
                $lastName,
                $this->getMobile($faker),
                $this->getEmail($faker, $firstName, $lastName)
            );
        }
    }

    private function getMobile($faker)
    {
        $mobile = null;
        while ($mobile = $faker->mobileNumber) {
            // faker can return 070 type numbers, which are disallowed
            if (preg_match('/7[1-9]\d{8,8}$/', $mobile)) {
                break;
            }
        }

        return $mobile;
    }

    private function getEmail($faker, $firstName, $lastName)
    {
        $email = $faker->email;
        // Use the first/last name as the user portion of the email address so they vaugely match
        // Keep the random portion of the email domain though
        $rand = rand(1, 3);
        if ($rand == 1) {
            $email = sprintf("%s.%s@%s", $firstName, $lastName, explode("@", $email)[1]);
        } elseif ($rand == 2) {
            $email = sprintf("%s%s@%s", mb_substr($firstName, 0, 1), $lastName, explode("@", $email)[1]);
        } elseif ($rand == 3) {
            $email = sprintf("%s%s%02d@%s", mb_substr($firstName, 0, 1), $lastName, rand(1, 99), explode("@", $email)[1]);
        }

        return $email;
    }
}
