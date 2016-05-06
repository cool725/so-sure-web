<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadPhoneData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager);
        // $this->loadPreLaunch($manager);
    }

    protected function loadCsv(ObjectManager $manager)
    {
        $file = sprintf(
            "%s/../src/AppBundle/DataFixtures/devices.csv",
            $this->container->getParameter('kernel.root_dir')
        );
        $row = 0;
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $this->newPhoneFromRow($manager, $data);
                }
                if ($row % 1000 == 0) {
                    $manager->flush();
                }
                $row = $row + 1;
            }
            fclose($handle);
        }

        $manager->flush();

        $row = 0;
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if ($row > 0) {
                    $this->setSuggestedReplacement($manager, $data);
                }
                $row = $row + 1;
            }
            fclose($handle);
        }

        $manager->flush();
    }

    protected function loadPreLaunch(ObjectManager $manager)
    {
        $this->newPhone($manager, 'ALL', 'MSRP £150 or less', 4.29);
        $this->newPhone($manager, 'ALL', 'MSRP £151 to £250', 5.29);
        $this->newPhone($manager, 'ALL', 'MSRP £251 to £400', 5.79);
        $this->newPhone($manager, 'ALL', 'MSRP £401 to £500', 6.29);
        $this->newPhone($manager, 'ALL', 'MSRP £501 to £600', 7.29);
        $this->newPhone($manager, 'ALL', 'MSRP £601 to £750', 8.29);
        $this->newPhone($manager, 'ALL', 'MSRP £751 to £1000', 9.29);
        $this->newPhone($manager, 'ALL', 'MSRP £1001 to £1500', 10.29);
        $this->newPhone($manager, 'ALL', 'MSRP £1501 to £2500', 15.29);

        $this->newPhone($manager, 'Apple', 'iPhone SE', 7.29, 16, ['iPhone8,4']);
        $this->newPhone($manager, 'Apple', 'iPhone SE', 7.29, 64, ['iPhone8,4']);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 7.29, 16, ['iPhone 6', 'iPhone7,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 7.29, 64, ['iPhone 6', 'iPhone7,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 7.29, 128, ['iPhone 6', 'iPhone7,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 6 Plus', 7.29, 16, ['iPhone 6 Plus', 'iPhone7,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6 Plus', 7.29, 64, ['iPhone 6 Plus', 'iPhone7,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6 Plus', 7.29, 128, ['iPhone 6 Plus', 'iPhone7,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 7.29, 16, ['iPhone 6s', 'iPhone8,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 7.29, 64, ['iPhone 6s', 'iPhone8,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 7.29, 128, ['iPhone 6s', 'iPhone8,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s Plus', 7.29, 16, ['iPhone 6s Plus', 'iPhone8,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s Plus', 7.29, 64, ['iPhone 6s Plus', 'iPhone8,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 6s Plus', 7.29, 128, ['iPhone 6s Plus', 'iPhone8,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', 5.79, 16, ['iPhone 5s', 'iPhone6,1', 'iPhone6,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', 5.79, 32, ['iPhone 5s', 'iPhone6,1', 'iPhone6,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', 5.79, 64, ['iPhone 5s', 'iPhone6,1', 'iPhone6,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', 5.29, 8, ['iPhone 5c', 'iPhone5,3', 'iPhone5,4']);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', 5.79, 16, ['iPhone 5c', 'iPhone5,3', 'iPhone5,4']);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', 5.79, 32, ['iPhone 5c', 'iPhone5,3', 'iPhone5,4']);
        $this->newPhone($manager, 'Apple', 'iPhone 5', 5.29, 16, ['iPhone 5', 'iPhone5,1', 'iPhone5,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5', 5.79, 32, ['iPhone 5', 'iPhone5,1', 'iPhone5,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 5', 5.79, 64, ['iPhone 5', 'iPhone5,1', 'iPhone5,2']);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', 5.29, 8, ['iPhone 4s', 'iPhone4,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', 5.29, 16, ['iPhone 4s', 'iPhone4,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', 5.29, 32, ['iPhone 4s', 'iPhone4,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', 5.29, 64, ['iPhone 4s', 'iPhone4,1']);
        $this->newPhone($manager, 'Apple', 'iPhone 4', 5.29, 8, ['iPhone 4', 'iPhone3,1', 'iPhone3,2', 'iPhone3,3']);
        $this->newPhone($manager, 'Apple', 'iPhone 4', 5.29, 16, ['iPhone 4', 'iPhone3,1', 'iPhone3,2', 'iPhone3,3']);
        $this->newPhone($manager, 'Apple', 'iPhone 4', 5.29, 32, ['iPhone 4', 'iPhone3,1', 'iPhone3,2', 'iPhone3,3']);

        $this->newPhone($manager, 'Samsung', 'Galaxy Ace 3', 4.29, null, ['logan', 'logan3gcmcc', 'logands', 'loganlte', 'loganrelte']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Ace 4', 5.29, null, ['vivalto3g', 'vivalto3mve3g', 'vivalto5mve3g','vivaltolte', 'vivaltonfc3g']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Alpha', 5.79, null, ['slte', 'slteatt', 'sltecan', 'sltechn', 'sltektt', 'sltelgt', 'slteskt']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Note II', 5.79, null, ['t0lteatt', 'SC-02E', 't03g', 't03gchn', 't03gchnduos', 't03gcmcc', 't03gctc', 't03gcuduos', 't0lte', 't0lteatt', 't0ltecan', 't0ltecmcc', 't0ltedcm', 't0ltektt', 't0ltelgt', 't0lteskt', 't0ltespr', 't0ltetmo', 't0lteusc', 't0ltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Note 3', 5.79, null, ['SC-02F', 'SCL22', 'ha3g', 'hlte', 'hlteatt', 'hltecan', 'hltektt', 'hltelgt', 'hlteskt', 'hltespr', 'hltetmo', 'hlteusc', 'hltevzw', 'htdlte']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Note 4', 7.29, null, ['tre3caltektt', 'tre3caltelgt', 'tre3calteskt', 'tre3g', 'trelte', 'treltektt', 'treltelgt', 'trelteskt', 'trhplte', 'trlte', 'trlteatt', 'trltecan', 'trltechn', 'trltechnzh', 'trltespr', 'trltetmo', 'trlteusc', 'trltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy Note Edge', 8.29, null, ['SCL24', 'tbeltektt', 'tbeltelgt', 'tbelteskt', 'tblte', 'tblteatt', 'tbltecan', 'tbltechn', 'tbltespr', 'tbltetmo', 'tblteusc', 'tblteusc']);
        $this->newPhone($manager, 'Samsung', 'Galaxy SII', 5.79, null, ['GT-I9100', 'GT-I9100M', 'GT-I9100P', 'GT-I9100T', 'GT-I9103', 'GT-I9108', 'GT-I9210T', 'SC-02C', 'SCH-R760X', 'SGH-I777', 'SGH-S959G', 'SGH-T989', 'SHV-E110S', 'SHW-M250K', 'SHW-M250L', 'SHW-M250S', 't1cmcc']);
        $this->newPhone($manager, 'Samsung', 'Galaxy SIII', 5.29, null, ['SC-03E', 'c1att', 'c1ktt', 'c1lgt', 'c1skt', 'd2att', 'd2can', 'd2cri', 'd2dcm', 'd2lteMetroPCS', 'd2lterefreshspr', 'd2ltetmo', 'd2mtr', 'd2spi', 'd2spr', 'd2tfnspr', 'd2tfnvzw', 'd2tmo', 'd2usc', 'd2vmu', 'd2vzw', 'd2xar', 'm0', 'm0apt', 'm0chn', 'm0cmcc', 'm0ctc', 'm0ctcduos', 'm0skt', 'm3', 'm3dcm']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S4', 5.79, null, ['SC-04E', 'ja3g', 'ja3gduosctc', 'jaltektt', 'jaltelgt', 'jalteskt', 'jflte', 'jflteaio', 'jflteatt', 'jfltecan', 'jfltecri', 'jfltecsp', 'jfltelra', 'jflterefreshspr', 'jfltespr', 'jfltetfnatt', 'jfltetfntmo', 'jfltetmo', 'jflteusc', 'jfltevzw', 'jfltevzwpp', 'jftdd', 'jfvelte', 'jfwifi', 'jsglte', 'ks01lte', 'ks01ltektt', 'ks01ltelgt']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S5', 5.79, null, ['SCL23', 'k3g', 'klte', 'klteMetroPCS', 'klteacg', 'klteaio', 'klteatt', 'kltecan', 'klteduoszn', 'kltektt', 'kltelgt', 'kltelra', 'klteskt', 'kltespr', 'kltetmo', 'klteusc', 'kltevzw', 'kwifi', 'lentisltektt', 'lentisltelgt', 'lentislteskt']);
        $this->newPhone($manager, 'Samsung', 'Galaxy SIII mini', 4.29, null, ['golden', 'goldenlteatt', 'goldenltebmc', 'goldenltevzw', 'goldenve3g']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S4 mini', 5.29, null, ['serrano3g', 'serranods', 'serranolte', 'serranoltebmc', 'serranoltektt', 'serranoltekx', 'serranoltelra', 'serranoltespr', 'serranolteusc', 'serranoltevzw', 'serranove3g', 'serranovelte', 'serranovolteatt']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S5 mini', 5.79, null, ['kmini3g', 'kminilte', 'kminilte', 'kminilteusc']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6', 7.29, 32, ['zeroflte', 'zeroflteacg', 'zeroflteaio', 'zeroflteatt', 'zerofltebmc', 'zerofltechn', 'zerofltectc', 'zerofltektt', 'zerofltelgt', 'zerofltelra', 'zerofltemtr', 'zeroflteskt', 'zerofltespr', 'zerofltetfnvzw', 'zerofltetmo', 'zeroflteusc', 'zerofltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6', 8.29, 64, ['zeroflte', 'zeroflteacg', 'zeroflteaio', 'zeroflteatt', 'zerofltebmc', 'zerofltechn', 'zerofltectc', 'zerofltektt', 'zerofltelgt', 'zerofltelra', 'zerofltemtr', 'zeroflteskt', 'zerofltespr', 'zerofltetfnvzw', 'zerofltetmo', 'zeroflteusc', 'zerofltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6', 8.29, 128, ['zeroflte', 'zeroflteacg', 'zeroflteaio', 'zeroflteatt', 'zerofltebmc', 'zerofltechn', 'zerofltectc', 'zerofltektt', 'zerofltelgt', 'zerofltelra', 'zerofltemtr', 'zeroflteskt', 'zerofltespr', 'zerofltetfnvzw', 'zerofltetmo', 'zeroflteusc', 'zerofltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6 Edge', 8.29, 32, ['404SC', 'SCV31', 'zerolte', 'zerolteacg', 'zerolteatt', 'zeroltebmc', 'zeroltechn', 'zeroltektt', 'zeroltelgt', 'zeroltelra', 'zerolteskt', 'zeroltespr', 'zeroltetmo', 'zerolteusc', 'zeroltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6 Edge', 8.29, 64, ['404SC', 'SCV31', 'zerolte', 'zerolteacg', 'zerolteatt', 'zeroltebmc', 'zeroltechn', 'zeroltektt', 'zeroltelgt', 'zeroltelra', 'zerolteskt', 'zeroltespr', 'zeroltetmo', 'zerolteusc', 'zeroltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6 Edge', 9.29, 128, ['404SC', 'SCV31', 'zerolte', 'zerolteacg', 'zerolteatt', 'zeroltebmc', 'zeroltechn', 'zeroltektt', 'zeroltelgt', 'zeroltelra', 'zerolteskt', 'zeroltespr', 'zeroltetmo', 'zerolteusc', 'zeroltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy A3', 5.29, null, ['a33g', 'a3lte', 'a3ltechn', 'a3ltectc', 'a3ltedd', 'a3lteslk', 'a3ltezh', 'a3ltezt', 'a3ulte', 'a3xelte']);
        $this->newPhone($manager, 'Samsung', 'Galaxy A5', 5.79, null, ['a53g', 'a5lte', 'a5ltechn', 'a5ltectc', 'a5ltezh', 'a5ltezt', 'a5ulte', 'a5ultebmc', 'a5ultektt', 'a5ultelgt', 'a5ulteskt', 'a5xelte', 'a5xeltecmcc', 'a5xeltektt', 'a5xeltelgt', 'a5xelteskt', 'a5xeltextc', 'a5xltechn', 'a5xeltextc']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6 Edge+', 8.29, 32, ['zenlte', 'zenlteatt', 'zenltebmc', 'zenltechn', 'zenltektt', 'zenltekx', 'zenltelgt', 'zenlteskt', 'zenltespr', 'zenltetmo', 'zenltevzw']);
        $this->newPhone($manager, 'Samsung', 'Galaxy S6 Edge+', 9.29, 64, ['zenlte', 'zenlteatt', 'zenltebmc', 'zenltechn', 'zenltektt', 'zenltekx', 'zenltelgt', 'zenlteskt', 'zenltespr', 'zenltetmo', 'zenltevzw']);
        $this->newPhone($manager, 'LG', 'Nexus 4', 5.29, 8, ['mako']);
        $this->newPhone($manager, 'LG', 'Nexus 4', 5.79, 16, ['mako']);
        $this->newPhone($manager, 'LG', 'Nexus 5', 5.79, 8, ['hammerhead']);
        $this->newPhone($manager, 'LG', 'Nexus 5', 5.79, 16, ['hammerhead']);
        $this->newPhone($manager, 'LG', 'Nexus 5X', 5.79, 16, ['bullhead']);
        $this->newPhone($manager, 'LG', 'Nexus 5X', 5.79, 32, ['bullhead']);
        $this->newPhone($manager, 'LG', 'G Flex', 8.29, null, ['zee']);
        $this->newPhone($manager, 'LG', 'G Flex 2', 6.29, null, ['z2']);
        $this->newPhone($manager, 'LG', 'G 2', 5.79, null, ['g2']);
        $this->newPhone($manager, 'LG', 'G 2 mini', 5.29, null, ['g2m', 'g2mds', 'g2mss', 'g2mv']);
        $this->newPhone($manager, 'LG', 'G 3', 5.79, null, ['g3']);
        $this->newPhone($manager, 'LG', 'G 3S', 5.29, null, ['jag3gds', 'jagnm']);
        $this->newPhone($manager, 'LG', 'G 4', 6.29, null, ['p1']);
        $this->newPhone($manager, 'Sony', 'Xperia E3', 4.29, null, ['D2202', 'D2203', 'D2206', 'D2243', 'D2212']);
        $this->newPhone($manager, 'Sony', 'Xperia L', 4.29, null, ['C2104']);
        $this->newPhone($manager, 'Sony', 'Xperia M', 4.29, null, ['C1904', 'C1905', 'C2004']);
        $this->newPhone($manager, 'Sony', 'Xperia M2', 5.29, null, ['D2303', 'D2305', 'D2306']);
        $this->newPhone($manager, 'Sony', 'Xperia SP', 5.29, null, ['C5302', 'C5303', 'C5306', 'M35h', 'M35t']);
        $this->newPhone($manager, 'Sony', 'Xperia Z Ultra', 5.79, null, ['C6802', 'C6806', 'C6833', 'C6843', 'SGP412', 'SOL24', 'XL39h']);
        $this->newPhone($manager, 'Sony', 'Xperia Z', 5.79, null, ['C6602', 'C6603', 'C6606', 'C6616', 'L36h', 'SO-02E']);
        $this->newPhone($manager, 'Sony', 'Xperia Z1 Compact', 5.79, null, ['D5503', 'M51w']);
        $this->newPhone($manager, 'Sony', 'Xperia Z2', 5.79, null, ['D6502', 'D6503', 'D6543', 'SO-03F']);
        $this->newPhone($manager, 'Sony', 'Xperia Z3', 5.79, null, ['401SO', 'D6603', 'D6616', 'D6643', 'D6646', 'D6653', 'SO-01G', 'SOL26', 'leo']);
        $this->newPhone($manager, 'Sony', 'Xperia Z3 Compact', 6.29, null, ['D5803', 'D5833', 'SO-02G']);
        $this->newPhone($manager, 'Sony', 'Xperia Z5', 7.29, null, ['501SO', 'E6603', 'E6653', 'SO-01H', 'SOV32']);
        $this->newPhone($manager, 'Sony', 'Xperia Z5 Compact', 6.29, null, ['E5803', 'E5823', 'SO-02H']);
        $this->newPhone($manager, 'Sony', 'Xperia M2 Aqua', 5.29, null, ['D2403', 'D2406']);        
        $this->newPhone($manager, 'Sony', 'Xperia T2 Ultra', 5.29, null, ['D5303', 'D5306', 'D5316', 'D5316N', 'D5322', 'D5322']);
        $this->newPhone($manager, 'Sony', 'Xperia T3', 5.79, null, ['D5102', 'D5103', 'D5106']);
        $this->newPhone($manager, 'HTC', 'One M9', 7.29, null, ['htc_himauhl', 'htc_himaul', 'htc_himaulatt', 'htc_himawhl', 'htc_himawl']);
        $this->newPhone($manager, 'HTC', 'One M8s', 6.29, null, ['htc_m8qlul']);
        $this->newPhone($manager, 'HTC', 'One M8', 7.29, null, ['htc_m8', 'htc_m8dug', 'htc_m8dwg', 'htc_m8whl', 'htc_m8wl', 'htc_m8dug']);
        $this->newPhone($manager, 'HTC', 'One Mini 2', 5.79, null, ['htc_memul']);
        $this->newPhone($manager, 'HTC', 'One Mini', 5.79, null, ['htc_m4', 'm4']);
        $this->newPhone($manager, 'HTC', 'One X', 6.29, null, ['endeavoru']);
        $this->newPhone($manager, 'HTC', 'One Max', 7.29, null, ['t6ul', 't6whl']);
        $this->newPhone($manager, 'HTC', 'Desire Eye', 5.79, null, ['htc_eyetuhl', 'htc_eyeul', 'htc_eyeul_att']);
        $this->newPhone($manager, 'HTC', 'M7', 5.79, null, ['m7', 'm7cdtu', 'm7cdug', 'm7cdwg', 'm7wls']);
        $this->newPhone($manager, 'HTC', 'Desire 500', 5.29, null, ['z4u', 'z4dug']);
        $this->newPhone($manager, 'HTC', 'Desire 510', 5.29, null, ['htc_a11ul8x26', 'htc_a11chl', 'htc_a11ul']);
        $this->newPhone($manager, 'HTC', 'Desire 620', 5.29, null, ['htc_a31dtul']);
        $this->newPhone($manager, 'HTC', 'Desire 610', 5.29, null, ['htc_a3qhdul', 'htc_a3ul']);
        $this->newPhone($manager, 'HTC', 'Desire 816', 5.79, null, ['htc_a5chl', 'htc_a5ul', 'htc_a5dug']);
        $this->newPhone($manager, 'HTC', 'Desire 820', 5.79, null, ['htc_a51ul', 'htc_a51dtul']);
        $this->newPhone($manager, 'Motorola', 'Moto X', 5.79, null, ['ghost', 'victara']);
        $this->newPhone($manager, 'Motorola', 'Moto G', 4.29, null, ['falcon_cdma', 'falcon_umts', 'falcon_umtsds', 'titan_udstv', 'titan_umts', 'titan_umtsds', 'osprey_cdma', 'osprey_u2', 'osprey_uds', 'osprey_udstv', 'osprey_umts']);
        $this->newPhone($manager, 'Motorola', 'Moto E', 4.29, null, ['condor_cdma', 'condor_udstv', 'condor_umts', 'condor_umtsds', 'otus', 'otus_ds']);
        $this->newPhone($manager, 'OnePlus', 'One', 5.29, 16, ['A0001']);
        $this->newPhone($manager, 'OnePlus', 'One', 5.79, 64, ['A0001']);

        // 10/10
        // $this->newPhone($manager, 'LG', 'Optimus One', 5.79, null, ['thunderg']);
        // 6/12
        // $this->newPhone($manager, 'LG', 'Optimus 4x', 6.29, null, ['x3']);
        // 10/12
        // $this->newPhone($manager, 'Motorola', 'Razr HD', 6.29, null, ['vanquish']);
        // dual sim
        // $this->newPhone($manager, 'HTC', 'One Dual Sim', 7.29, null, ['m7cdwg']);
        // 3/10
        // $this->newPhone($manager, 'HTC', 'Desire', 5.79, null, ['bravo']);
        
        /*
        $this->newPhone($manager, 'Amazon', 'Fire', 5.79, 32);
        $this->newPhone($manager, 'Amazon', 'Fire', 6.29, 64);

        $this->newPhone($manager, 'Blackberry', 'Leap', 5.29);
        $this->newPhone($manager, 'Blackberry', 'Classic', 5.79);
        $this->newPhone($manager, 'Blackberry', 'Passport', 6.29);
        $this->newPhone($manager, 'Blackberry', 'Z30', 5.29);
        $this->newPhone($manager, 'Blackberry', 'Q10', 5.79);
        $this->newPhone($manager, 'Blackberry', 'Q5', 4.29);
        $this->newPhone($manager, 'Blackberry', 'Z10', 5.29);
        
        $this->newPhone($manager, 'Nokia', 'Lumia 640', 5.29);
        $this->newPhone($manager, 'Nokia', 'Lumia 640XL', 5.29);
        $this->newPhone($manager, 'Nokia', 'Lumia 735', 5.29);
        $this->newPhone($manager, 'Nokia', 'Lumia 830', 5.79);
        $this->newPhone($manager, 'Nokia', 'Lumia 930', 5.79);
        $this->newPhone($manager, 'Nokia', 'Lumia 925', 5.79);
        $this->newPhone($manager, 'Nokia', 'Lumia 1020', 5.79);
        $this->newPhone($manager, 'Nokia', 'Lumia 1320', 5.29);
        $this->newPhone($manager, 'Nokia', 'Lumia 1520', 5.79);
        $this->newPhone($manager, 'Nokia', 'Lumia 2520', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 640', 5.29);
        $this->newPhone($manager, 'Microsoft', 'Lumia 640XL', 5.29);
        $this->newPhone($manager, 'Microsoft', 'Lumia 735', 5.29);
        $this->newPhone($manager, 'Microsoft', 'Lumia 830', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 930', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 925', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 1020', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 1320', 5.29);
        $this->newPhone($manager, 'Microsoft', 'Lumia 1520', 5.79);
        $this->newPhone($manager, 'Microsoft', 'Lumia 2520', 5.79);
        */

        $manager->flush();
    }

    private function setSuggestedReplacement($manager, $data)
    {
        if (!$data[24]) {
            return;
        }

        $phoneRepo = $manager->getRepository(Phone::class);
        $replacementQuery = ['make' => trim($data[24]), 'model' => trim($data[25]), 'memory' => (int)trim($data[26])];
        $replacement = $phoneRepo->findOneBy($replacementQuery);
        if ($replacement) {
            $phone = $phoneRepo->findOneBy(['make' => $data[0], 'model' => $data[1], 'memory' => (int)$data[3]]);
            $phone->setSuggestedReplacement($replacement);
        }
    }

    private function newPhoneFromRow($manager, $data)
    {
        if (!$data[4]) {
            return;
        }

        $devices = str_getcsv($data[4], ",", "'");
        foreach ($devices as $device) {
            if (stripos($device, "‘") !== false || stripos($device, "’") !== false) {
                throw new \Exception(sprintf('Invalid apple quote for device %s', $device));
            }
        }

        $phone = new Phone();
        $phone->init(
            $data[0], // $make
            $data[1], // $model
            $data[5] + 1.5, // $premium
            $data[3], // $memory
            $devices, // $devices
            str_replace('£', '', $data[7]), // $initialPrice
            str_replace('£', '', $data[6]), // $replacementPrice
            $data[8] // $initialPriceUrl
        );

        $resolution = explode('x', str_replace(' ', '', $data[17]));
        $releaseDate = null;
        $releaseDateText = str_replace(' ', '', $data[21]);
        if (strlen($releaseDateText) > 0) {
            $releaseDate = \DateTime::createFromFormat('m/y', $releaseDateText);
            $releaseDate->setTime(0, 0);
            // otherwise is current day
            $releaseDate->modify('first day of this month');
        }
        $phone->setDetails(
            $data[9], // $os,
            $data[10], // $initialOsVersion,
            $data[11], // $upgradeOsVersion,
            $data[12], // $processorSpeed,
            $data[13], // $processorCores,
            $data[14], // $ram,
            $data[15] == 'Y' ? true : false, // $ssd,
            round($data[16]), // $screenPhysical,
            $resolution[0], // $screenResolutionWidth,
            $resolution[1], // $screenResolutionHeight,
            round($data[18]), // $camera
            $data[19] == 'Y' ? true : false, // $lte
            $releaseDate // $releaseDate
        );

        $manager->persist($phone);
        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Failed to init phone');
        }
    }

    private function newPhone($manager, $make, $model, $policyPrice, $memory = null, $devices = null)
    {
        $phone = new Phone();
        $phone->init($make, $model, $policyPrice + 1.5, $memory, $devices);
        $manager->persist($phone);

        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Failed to init phone');
        }
        /*
        \Doctrine\Common\Util\Debug::dump($phone->getCurrentPhonePrice());
        
        $repo = $manager->getRepository(Phone::class);
        $compare = $repo->find($phone->getId());
        \Doctrine\Common\Util\Debug::dump($compare->getCurrentPhonePrice());
        */
    }
}
