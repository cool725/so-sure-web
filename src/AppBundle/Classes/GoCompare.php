<?php
namespace AppBundle\Classes;

class GoCompare
{
    public static $models = [
        26 => ['make' => 'Apple', 'model' => 'iPhone 6S', 'memory' => 128],
        27 => ['make' => 'Apple', 'model' => 'iPhone 6S', 'memory' => 16],
        28 => ['make' => 'Apple', 'model' => 'iPhone 6S', 'memory' => 32],
        29 => ['make' => 'Apple', 'model' => 'iPhone 6S', 'memory' => 64],
        30 => ['make' => 'Apple', 'model' => 'iPhone 6S Plus', 'memory' => 128],
        31 => ['make' => 'Apple', 'model' => 'iPhone 6S Plus', 'memory' => 16],
        32 => ['make' => 'Apple', 'model' => 'iPhone 6S Plus', 'memory' => 32],
        33 => ['make' => 'Apple', 'model' => 'iPhone 6S Plus', 'memory' => 64],
        34 => ['make' => 'Apple', 'model' => 'iPhone 7', 'memory' => 128],
        35 => ['make' => 'Apple', 'model' => 'iPhone 7', 'memory' => 256],
        36 => ['make' => 'Apple', 'model' => 'iPhone 7', 'memory' => 32],
        37 => ['make' => 'Apple', 'model' => 'iPhone 7 Plus', 'memory' => 128],
        38 => ['make' => 'Apple', 'model' => 'iPhone 7 Plus', 'memory' => 256],
        39 => ['make' => 'Apple', 'model' => 'iPhone 7 Plus', 'memory' => 32],
        40 => ['make' => 'Apple', 'model' => 'iPhone SE', 'memory' => 16],
        41 => ['make' => 'Apple', 'model' => 'iPhone SE', 'memory' => 64],
        814 => ['make' => 'Apple', 'model' => 'iPhone 8', 'memory' => 64],
        815 => ['make' => 'Apple', 'model' => 'iPhone 8', 'memory' => 256],
        816 => ['make' => 'Apple', 'model' => 'iPhone 8 Plus', 'memory' => 64],
        817 => ['make' => 'Apple', 'model' => 'iPhone 8 Plus', 'memory' => 256],
        818 => ['make' => 'Apple', 'model' => 'iPhone X', 'memory' => 64],
        819 => ['make' => 'Apple', 'model' => 'iPhone X', 'memory' => 256],
        806 => ['make' => 'Apple', 'model' => 'iPhone SE', 'memory' => 32],
        807 => ['make' => 'Apple', 'model' => 'iPhone SE', 'memory' => 128],
        1440 => ['make' => 'Apple', 'model' => 'iPhone XR', 'memory' => 64],
        1441 => ['make' => 'Apple', 'model' => 'iPhone XR', 'memory' => 128],
        1442 => ['make' => 'Apple', 'model' => 'iPhone XR', 'memory' => 256],
        1443 => ['make' => 'Apple', 'model' => 'iPhone XS', 'memory' => 64],
        1444 => ['make' => 'Apple', 'model' => 'iPhone XS', 'memory' => 256],
        1445 => ['make' => 'Apple', 'model' => 'iPhone XS', 'memory' => 512],
        1446 => ['make' => 'Apple', 'model' => 'iPhone XS Max', 'memory' => 64],
        1447 => ['make' => 'Apple', 'model' => 'iPhone XS Max', 'memory' => 256],
        1448 => ['make' => 'Apple', 'model' => 'iPhone XS Max', 'memory' => 512],

        215 => ['make' => 'Samsung', 'model' => 'Galaxy S6', 'memory' => 128],
        216 => ['make' => 'Samsung', 'model' => 'Galaxy S6', 'memory' => 32],
        217 => ['make' => 'Samsung', 'model' => 'Galaxy S6', 'memory' => 64],
        218 => ['make' => 'Samsung', 'model' => 'Galaxy S6 Edge', 'memory' => 128],
        219 => ['make' => 'Samsung', 'model' => 'Galaxy S6 Edge', 'memory' => 32],
        220 => ['make' => 'Samsung', 'model' => 'Galaxy S6 Edge', 'memory' => 64],
        221 => ['make' => 'Samsung', 'model' => 'Galaxy S6 Edge+', 'memory' => 32],
        222 => ['make' => 'Samsung', 'model' => 'Galaxy S6 Edge+', 'memory' => 64],
        223 => ['make' => 'Samsung', 'model' => 'Galaxy S7', 'memory' => 32],
        224 => ['make' => 'Samsung', 'model' => 'Galaxy S7 Edge', 'memory' => 32],
        808 => ['make' => 'Samsung', 'model' => 'Galaxy S8', 'memory' => 64],
        809 => ['make' => 'Samsung', 'model' => 'Galaxy S8+', 'memory' => 64],
        820 => ['make' => 'Samsung', 'model' => 'Galaxy Note 8', 'memory' => 64],
        1432 => ['make' => 'Samsung', 'model' => 'Galaxy Note 9', 'memory' => 128],
        1433 => ['make' => 'Samsung', 'model' => 'Galaxy Note 9', 'memory' => 512],
        1449 => ['make' => 'Samsung', 'model' => 'Galaxy J6', 'memory' => 32],
        1239 => ['make' => 'Samsung', 'model' => 'Galaxy S9', 'memory' => 64],
        1240 => ['make' => 'Samsung', 'model' => 'Galaxy S9+', 'memory' => 128],
        1463 => ['make' => 'Samsung', 'model' => 'Galaxy A6', 'memory' => 32],
        1479 => ['make' => 'Samsung', 'model' => 'Galaxy A7 (2018)', 'memory' => 64],
        1480 => ['make' => 'Samsung', 'model' => 'Galaxy A9 (2018)', 'memory' => 128],
        1699 => ['make' => 'Samsung', 'model' => 'Galaxy Note 10', 'memory' => 256],
        1700 => ['make' => 'Samsung', 'model' => 'Galaxy Note 10 Plus', 'memory' => 256],
        1701 => ['make' => 'Samsung', 'model' => 'Galaxy Note 10 Plus 5G', 'memory' => 256],
        1706 => ['make' => 'Samsung', 'model' => 'Galaxy Note 10 Plus 5G', 'memory' => 512],

        1412 => ['make' => 'Huawei', 'model' => 'P20', 'memory' => 128],
        1413 => ['make' => 'Huawei', 'model' => 'P20 Lite', 'memory' => 64],
        1414 => ['make' => 'Huawei', 'model' => 'P20 Pro', 'memory' => 128],
        1450 => ['make' => 'Huawei', 'model' => 'Honor 9', 'memory' => 64],
        1453 => ['make' => 'Huawei', 'model' => 'Honor 7X', 'memory' => 64],
        1454 => ['make' => 'Huawei', 'model' => 'P Smart', 'memory' => 32],
        1456 => ['make' => 'Huawei', 'model' => 'Honor Play', 'memory' => 64],
        1457 => ['make' => 'Huawei', 'model' => 'Mate 20 Pro', 'memory' => 128],
        1429 => ['make' => 'Huawei', 'model' => 'P8 Lite (2017)', 'memory' => 16],
        1430 => ['make' => 'Huawei', 'model' => 'P Smart', 'memory' => 32],
        1424 => ['make' => 'Huawei', 'model' => 'Honor 10', 'memory' => 64],
        1475 => ['make' => 'Huawei', 'model' => 'Honor 8', 'memory' => 32],
        1483 => ['make' => 'Huawei', 'model' => 'Honor 10', 'memory' => 128],

        1417 => ['make' => 'Motorola', 'model' => 'Moto X4', 'memory' => 64],
        1418 => ['make' => 'Motorola', 'model' => 'Moto G5s', 'memory' => 32],
        1419 => ['make' => 'Motorola', 'model' => 'Moto G5s Plus', 'memory' => 64],
        1420 => ['make' => 'Motorola', 'model' => 'Moto Z2 Force', 'memory' => 64],
        1421 => ['make' => 'Motorola', 'model' => 'Moto E4 Plus', 'memory' => 16],

        1428 => ['make' => 'LG', 'model' => 'G7 ThinQ', 'memory' => 64],

        1416 => ['make' => 'Sony', 'model' => 'Xperia XA2', 'memory' => 32],
        1462 => ['make' => 'Sony', 'model' => 'Xperia XZ3', 'memory' => 64],

        827 => ['make' => 'Google', 'model' => 'Pixel 2', 'memory' => 64],
        835 => ['make' => 'Google', 'model' => 'Pixel 2', 'memory' => 128],
        828 => ['make' => 'Google', 'model' => 'Pixel 2 XL', 'memory' => 64],
        836 => ['make' => 'Google', 'model' => 'Pixel 2 XL', 'memory' => 128],
        1464 => ['make' => 'Google', 'model' => 'Pixel 3', 'memory' => 64],
        1465 => ['make' => 'Google', 'model' => 'Pixel 3 XL', 'memory' => 64],
        1645 => ['make' => 'Google', 'model' => 'Pixel 3a', 'memory' => 64],
        1646 => ['make' => 'Google', 'model' => 'Pixel 3a XL', 'memory' => 64],

        1477 => ['make' => 'Nokia', 'model' => '7.1', 'memory' => 32],

        182 => ['make' => 'OnePlus', 'model' => 'Three', 'memory' => 64],
        183 => ['make' => 'OnePlus', 'model' => 'Two', 'memory' => 16],
        184 => ['make' => 'OnePlus', 'model' => 'X', 'memory' => 16],
        824 => ['make' => 'OnePlus', 'model' => '5', 'memory' => 64],
        825 => ['make' => 'OnePlus', 'model' => '5T', 'memory' => 64],
        1425 => ['make' => 'OnePlus', 'model' => '6', 'memory' => 64],
        1426 => ['make' => 'OnePlus', 'model' => '6', 'memory' => 128],
        1427 => ['make' => 'OnePlus', 'model' => '6', 'memory' => 256],
        1466 => ['make' => 'OnePlus', 'model' => '6T', 'memory' => 128],
        1467 => ['make' => 'OnePlus', 'model' => '6T', 'memory' => 256],
        1647 => ['make' => 'OnePlus', 'model' => '7 Pro', 'memory' => 128],
        1648 => ['make' => 'OnePlus', 'model' => '7 Pro', 'memory' => 256],
    ];
}
