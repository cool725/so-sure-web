$(function(){
    var phones = $('#select-phone-data').data('phones');
    var updatePhones = function() {
        var make = $('.select-phone-make').val();
        var options = $(".select-phones");
        options.empty();
        if (make) {
            options.append($("<option />").val("").text('Select your ' + make + ' device'));
        } else {
            options.append($("<option />").val("").text('Select your phone make first'));
        }
        $.each(phones[make], function(key, value) {
            options.append($("<option />").val(key).text(value));
        });
    }
    $('.select-phone-make').on('change', function(e) {
        updatePhones();
    });
    updatePhones();

    $('#launch_phone_make').change(function() {
        $('#launch_phone_make').removeClass('has-error')
        $('#launch_phone_phoneId').removeClass('has-error')
    });

    $('#launch_phone_phoneId').change(function() {
        $('#launch_phone_phoneId').removeClass('has-error')
    });

    $("#launch_phone_next").click(function(event) {

        event.preventDefault();

        if ($('#launch_phone_make').val() == "") {
            $('#launch_phone_make').addClass('has-error')
        }
        else {
            $('#launch_phone_make').removeClass('has-error')
        }

        if ($('#launch_phone_phoneId').val() == "") {
            $('#launch_phone_phoneId').addClass('has-error')
        }
        else {
            $('#launch_phone_phoneId').removeClass('has-error')
        }

        if ($('#launch_phone_make').val() != "" && $('#launch_phone_phoneId').val() != "") {
            $('form[name="launch_phone"]').submit()
        }
    });

    // Twitter Typeahead
    function preventDefault(e) {
        e.preventDefault();
    }

    // If the form action is already defined, then allow the form to submit
    if (!$('#search-phone-form').attr('action')) {
        $('#search-phone-form').bind('submit', preventDefault);
    }

    function mySort(a, b) {
        if (a < b) {
            return -1;
        } else if (a > b) {
            return 1;
        } else {
            return 0;
        }
    }

    var data = [

    {
        "id": "59356fea636239643921e25a",
        "name": "Alcatel Idol 3 (8 GB)"
    },
    {
        "id": "59356fea636239643921e25b",
        "name": "Alcatel Idol 3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e26a",
        "name": "Apple iPhone 5C (8 GB)"
    },
    {
        "id": "59356fea636239643921e268",
        "name": "Apple iPhone 5C (16 GB)"
    },
    {
        "id": "59356fea636239643921e269",
        "name": "Apple iPhone 5C (32 GB)"
    },
    {
        "id": "59356fea636239643921e26b",
        "name": "Apple iPhone 5S (16 GB)"
    },
    {
        "id": "59356fea636239643921e26c",
        "name": "Apple iPhone 5S (32 GB)"
    },
    {
        "id": "59356fea636239643921e26d",
        "name": "Apple iPhone 5S (64 GB)"
    },
    {
        "id": "59356fea636239643921e26f",
        "name": "Apple iPhone 6 (16 GB)"
    },
    {
        "id": "59356fea636239643921e270",
        "name": "Apple iPhone 6 (64 GB)"
    },
    {
        "id": "59356fea636239643921e26e",
        "name": "Apple iPhone 6 (128 GB)"
    },
    {
        "id": "59356fea636239643921e272",
        "name": "Apple iPhone 6 Plus (16 GB)"
    },
    {
        "id": "59356fea636239643921e273",
        "name": "Apple iPhone 6 Plus (64 GB)"
    },
    {
        "id": "59356fea636239643921e271",
        "name": "Apple iPhone 6 Plus (128 GB)"
    },
    {
        "id": "59356fea636239643921e275",
        "name": "Apple iPhone 6S (16 GB)"
    },
    {
        "id": "59356feb636239643921e374",
        "name": "Apple iPhone 6S (32 GB)"
    },
    {
        "id": "59356fea636239643921e276",
        "name": "Apple iPhone 6S (64 GB)"
    },
    {
        "id": "59356fea636239643921e274",
        "name": "Apple iPhone 6S (128 GB)"
    },
    {
        "id": "59356fea636239643921e278",
        "name": "Apple iPhone 6S Plus (16 GB)"
    },
    {
        "id": "59356feb636239643921e371",
        "name": "Apple iPhone 6S Plus (32 GB)"
    },
    {
        "id": "59356fea636239643921e279",
        "name": "Apple iPhone 6S Plus (64 GB)"
    },
    {
        "id": "59356fea636239643921e277",
        "name": "Apple iPhone 6S Plus (128 GB)"
    },
    {
        "id": "59356fea636239643921e362",
        "name": "Apple iPhone 7 (32 GB)"
    },
    {
        "id": "59356fea636239643921e363",
        "name": "Apple iPhone 7 (128 GB)"
    },
    {
        "id": "59356fea636239643921e364",
        "name": "Apple iPhone 7 (256 GB)"
    },
    {
        "id": "59356fea636239643921e365",
        "name": "Apple iPhone 7 Plus (32 GB)"
    },
    {
        "id": "59356fea636239643921e366",
        "name": "Apple iPhone 7 Plus (128 GB)"
    },
    {
        "id": "59356fea636239643921e367",
        "name": "Apple iPhone 7 Plus (256 GB)"
    },
    {
        "id": "59356fea636239643921e27a",
        "name": "Apple iPhone SE (16 GB)"
    },
    {
        "id": "59356fea636239643921e27b",
        "name": "Apple iPhone SE (64 GB)"
    },
    {
        "id": "59356feb636239643921e368",
        "name": "Google Pixel (32 GB)"
    },
    {
        "id": "59356feb636239643921e369",
        "name": "Google Pixel (128 GB)"
    },
    {
        "id": "59356feb636239643921e36a",
        "name": "Google Pixel XL (32 GB)"
    },
    {
        "id": "59356feb636239643921e36b",
        "name": "Google Pixel XL (128 GB)"
    },
    {
        "id": "59356fea636239643921e358",
        "name": "HTC 10 (32 GB)"
    },
    {
        "id": "59356feb636239643921e38e",
        "name": "HTC 10 evo (32 GB)"
    },
    {
        "id": "59356fea636239643921e28f",
        "name": "HTC Desire 500 (4 GB)"
    },
    {
        "id": "59356fea636239643921e290",
        "name": "HTC Desire 510 (8 GB)"
    },
    {
        "id": "59356fea636239643921e291",
        "name": "HTC Desire 516 (4 GB)"
    },
    {
        "id": "59356fea636239643921e292",
        "name": "HTC Desire 601 (8 GB)"
    },
    {
        "id": "59356fea636239643921e293",
        "name": "HTC Desire 610 (8 GB)"
    },
    {
        "id": "59356fea636239643921e294",
        "name": "HTC Desire 620 (8 GB)"
    },
    {
        "id": "59356fea636239643921e295",
        "name": "HTC Desire 626 (16 GB)"
    },
    {
        "id": "59356fea636239643921e296",
        "name": "HTC Desire 816 (8 GB)"
    },
    {
        "id": "59356fea636239643921e297",
        "name": "HTC Desire 820 (16 GB)"
    },
    {
        "id": "59356fea636239643921e299",
        "name": "HTC Desire Eye (16 GB)"
    },
    {
        "id": "59356fea636239643921e2a2",
        "name": "HTC One A9 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2a3",
        "name": "HTC One M8 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2a4",
        "name": "HTC One M8 (32 GB)"
    },
    {
        "id": "59356fea636239643921e2a5",
        "name": "HTC One M8s (16 GB)"
    },
    {
        "id": "59356fea636239643921e2a6",
        "name": "HTC One M8s (32 GB)"
    },
    {
        "id": "59356fea636239643921e2a7",
        "name": "HTC One M9 (32 GB)"
    },
    {
        "id": "59356feb636239643921e385",
        "name": "HTC One X9 (32 GB)"
    },
    {
        "id": "59356fea636239643921e2aa",
        "name": "HTC One mini (16 GB)"
    },
    {
        "id": "59356fea636239643921e2ab",
        "name": "HTC One mini 2 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2b8",
        "name": "Huawei Ascend G6 4G (8 GB)"
    },
    {
        "id": "59356fea636239643921e2b9",
        "name": "Huawei Ascend P6 (8 GB)"
    },
    {
        "id": "59356fea636239643921e2ba",
        "name": "Huawei Ascend P6 (16 GB)"
    },
    {
        "id": "59356feb636239643921e36f",
        "name": "Huawei G8 (16 GB)"
    },
    {
        "id": "59356feb636239643921e38a",
        "name": "Huawei Honor 5C (16 GB)"
    },
    {
        "id": "59356feb636239643921e36e",
        "name": "Huawei Honor 5X (16 GB)"
    },
    {
        "id": "59356feb636239643921e389",
        "name": "Huawei Honor 7 (16 GB)"
    },
    {
        "id": "59356feb636239643921e37e",
        "name": "Huawei Honor 8 (32 GB)"
    },
    {
        "id": "59356fea636239643921e350",
        "name": "Huawei Mate 8 (32 GB)"
    },
    {
        "id": "59356feb636239643921e38b",
        "name": "Huawei Mate 9 (64 GB)"
    },
    {
        "id": "59356fea636239643921e2bb",
        "name": "Huawei/Google Nexus 6P (32 GB)"
    },
    {
        "id": "59356fea636239643921e2bc",
        "name": "Huawei/Google Nexus 6P (64 GB)"
    },
    {
        "id": "59356fea636239643921e2bd",
        "name": "Huawei/Google Nexus 6P (128 GB)"
    },
    {
        "id": "59356feb636239643921e36c",
        "name": "Huawei Nova Plus (32 GB)"
    },
    {
        "id": "59356feb636239643921e392",
        "name": "Huawei P10 Lite (32 GB)"
    },
    {
        "id": "59356feb636239643921e37c",
        "name": "Huawei P8 (16 GB)"
    },
    {
        "id": "59356feb636239643921e377",
        "name": "Huawei P9 (32 GB)"
    },
    {
        "id": "59356feb636239643921e37a",
        "name": "Huawei P9 Plus (64 GB)"
    },
    {
        "id": "59356feb636239643921e36d",
        "name": "Huawei P9 lite (16 GB)"
    },
    {
        "id": "59356feb636239643921e386",
        "name": "Kodak Ektra (32 GB)"
    },
    {
        "id": "59356fea636239643921e2c4",
        "name": "LG G Flex (32 GB)"
    },
    {
        "id": "59356fea636239643921e2c5",
        "name": "LG G Flex2 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2be",
        "name": "LG G2 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2bf",
        "name": "LG G2 (32 GB)"
    },
    {
        "id": "59356fea636239643921e2c0",
        "name": "LG G2 mini (8 GB)"
    },
    {
        "id": "59356fea636239643921e2c1",
        "name": "LG G3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2c2",
        "name": "LG G3 S (8 GB)"
    },
    {
        "id": "59356fea636239643921e2c3",
        "name": "LG G4 (32 GB)"
    },
    {
        "id": "59356fea636239643921e355",
        "name": "LG G5 (32 GB)"
    },
    {
        "id": "59356feb636239643921e391",
        "name": "LG G6 (32 GB)"
    },
    {
        "id": "59356feb636239643921e37b",
        "name": "LG K8 (16 GB)"
    },
    {
        "id": "59356feb636239643921e375",
        "name": "LG Leon (8 GB)"
    },
    {
        "id": "59356fea636239643921e2c8",
        "name": "LG/Google Nexus 5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2c9",
        "name": "LG/Google Nexus 5 (32 GB)"
    },
    {
        "id": "59356fea636239643921e2ca",
        "name": "LG/Google Nexus 5X (16 GB)"
    },
    {
        "id": "59356fea636239643921e2cb",
        "name": "LG/Google Nexus 5X (32 GB)"
    },
    {
        "id": "59356fea636239643921e2cc",
        "name": "LG Spirit (8 GB)"
    },
    {
        "id": "59356fea636239643921e2d5",
        "name": "Motorola Moto E (4 GB)"
    },
    {
        "id": "59356feb636239643921e37d",
        "name": "Motorola Moto E2 (8 GB)"
    },
    {
        "id": "59356fea636239643921e35a",
        "name": "Motorola Moto E3 (8 GB)"
    },
    {
        "id": "59356fea636239643921e2d3",
        "name": "Motorola Moto G (8 GB)"
    },
    {
        "id": "59356fea636239643921e2d4",
        "name": "Motorola Moto G (16 GB)"
    },
    {
        "id": "59356fea636239643921e2d2",
        "name": "Motorola Moto G (2nd gen) (8 GB)"
    },
    {
        "id": "59356fea636239643921e2d0",
        "name": "Motorola Moto G (3rd gen) (8 GB)"
    },
    {
        "id": "59356fea636239643921e2d1",
        "name": "Motorola Moto G 4G (8 GB)"
    },
    {
        "id": "59356fea636239643921e35b",
        "name": "Motorola Moto G4 Play (8 GB)"
    },
    {
        "id": "59356fea636239643921e35c",
        "name": "Motorola Moto G4 Play (16 GB)"
    },
    {
        "id": "59356feb636239643921e393",
        "name": "Motorola Moto G5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2d6",
        "name": "Motorola Moto X (32 GB)"
    },
    {
        "id": "59356fea636239643921e2d7",
        "name": "Motorola Moto X (64 GB)"
    },
    {
        "id": "59356fea636239643921e360",
        "name": "Motorola Moto Z (32 GB)"
    },
    {
        "id": "59356fea636239643921e361",
        "name": "Motorola Moto Z (64 GB)"
    },
    {
        "id": "59356feb636239643921e380",
        "name": "Motorola Moto Z Play (32 GB)"
    },
    {
        "id": "59356fea636239643921e2d8",
        "name": "Motorola/Google Nexus 6 (32 GB)"
    },
    {
        "id": "59356fea636239643921e2d9",
        "name": "Motorola/Google Nexus 6 (64 GB)"
    },
    {
        "id": "59356feb636239643921e372",
        "name": "Motorola X Force (32 GB)"
    },
    {
        "id": "59356feb636239643921e373",
        "name": "Motorola X Force (64 GB)"
    },
    {
        "id": "59356feb636239643921e378",
        "name": "Motorola X Play (16 GB)"
    },
    {
        "id": "59356feb636239643921e379",
        "name": "Motorola X Play (32 GB)"
    },
    {
        "id": "59356fea636239643921e351",
        "name": "OnePlus 2 (16 GB)"
    },
    {
        "id": "59356fea636239643921e352",
        "name": "OnePlus 2 (64 GB)"
    },
    {
        "id": "59356fea636239643921e353",
        "name": "OnePlus 3 (64 GB)"
    },
    {
        "id": "59356feb636239643921e383",
        "name": "OnePlus 3T (64 GB)"
    },
    {
        "id": "59356feb636239643921e384",
        "name": "OnePlus 3T (128 GB)"
    },
    {
        "id": "59356fea636239643921e2eb",
        "name": "OnePlus One (16 GB)"
    },
    {
        "id": "59356fea636239643921e2ec",
        "name": "OnePlus One (64 GB)"
    },
    {
        "id": "59356feb636239643921e382",
        "name": "OnePlus X (16 GB)"
    },
    {
        "id": "59356fea636239643921e2ef",
        "name": "Samsung Galaxy A3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e2f0",
        "name": "Samsung Galaxy A5 (16 GB)"
    },
    {
        "id": "59356feb636239643921e37f",
        "name": "Samsung Galaxy A7 (2016) (16 GB)"
    },
    {
        "id": "59356fea636239643921e2f2",
        "name": "Samsung Galaxy Ace 3 (4 GB)"
    },
    {
        "id": "59356fea636239643921e2f3",
        "name": "Samsung Galaxy Ace 3 (8 GB)"
    },
    {
        "id": "59356fea636239643921e2f4",
        "name": "Samsung Galaxy Ace 4 (8 GB)"
    },
    {
        "id": "59356fea636239643921e2f6",
        "name": "Samsung Galaxy Ace Style (4 GB)"
    },
    {
        "id": "59356fea636239643921e2f7",
        "name": "Samsung Galaxy Alpha (32 GB)"
    },
    {
        "id": "59356fea636239643921e2f8",
        "name": "Samsung Galaxy Core Prime (8 GB)"
    },
    {
        "id": "59356feb636239643921e370",
        "name": "Samsung Galaxy J3 (2016) (8 GB)"
    },
    {
        "id": "59356fea636239643921e34d",
        "name": "Samsung Galaxy J5 (8 GB)"
    },
    {
        "id": "59356fea636239643921e34e",
        "name": "Samsung Galaxy J5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e34f",
        "name": "Samsung Galaxy J5 (2016) (16 GB)"
    },
    {
        "id": "59356fea636239643921e2fa",
        "name": "Samsung Galaxy Mega 6.3 (8 GB)"
    },
    {
        "id": "59356fea636239643921e2fb",
        "name": "Samsung Galaxy Mega 6.3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e300",
        "name": "Samsung Galaxy Note 3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e301",
        "name": "Samsung Galaxy Note 3 (32 GB)"
    },
    {
        "id": "59356fea636239643921e302",
        "name": "Samsung Galaxy Note 3 (64 GB)"
    },
    {
        "id": "59356fea636239643921e303",
        "name": "Samsung Galaxy Note 4 (32 GB)"
    },
    {
        "id": "59356feb636239643921e376",
        "name": "Samsung Galaxy Note 5 (32 GB)"
    },
    {
        "id": "59356fea636239643921e359",
        "name": "Samsung Galaxy Note 7 (64 GB)"
    },
    {
        "id": "59356fea636239643921e304",
        "name": "Samsung Galaxy Note Edge (32 GB)"
    },
    {
        "id": "59356fea636239643921e305",
        "name": "Samsung Galaxy Note Edge (64 GB)"
    },
    {
        "id": "59356fea636239643921e317",
        "name": "Samsung Galaxy S4 mini (8 GB)"
    },
    {
        "id": "59356fea636239643921e318",
        "name": "Samsung Galaxy S4 zoom (8 GB)"
    },
    {
        "id": "59356fea636239643921e319",
        "name": "Samsung Galaxy S5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e31a",
        "name": "Samsung Galaxy S5 (32 GB)"
    },
    {
        "id": "59356fea636239643921e31c",
        "name": "Samsung Galaxy S5 Neo (16 GB)"
    },
    {
        "id": "59356fea636239643921e31b",
        "name": "Samsung Galaxy S5 mini (16 GB)"
    },
    {
        "id": "59356fea636239643921e31e",
        "name": "Samsung Galaxy S6 (32 GB)"
    },
    {
        "id": "59356fea636239643921e31f",
        "name": "Samsung Galaxy S6 (64 GB)"
    },
    {
        "id": "59356fea636239643921e31d",
        "name": "Samsung Galaxy S6 (128 GB)"
    },
    {
        "id": "59356fea636239643921e321",
        "name": "Samsung Galaxy S6 Edge (32 GB)"
    },
    {
        "id": "59356fea636239643921e322",
        "name": "Samsung Galaxy S6 Edge (64 GB)"
    },
    {
        "id": "59356fea636239643921e320",
        "name": "Samsung Galaxy S6 Edge (128 GB)"
    },
    {
        "id": "59356fea636239643921e323",
        "name": "Samsung Galaxy S6 Edge+ (32 GB)"
    },
    {
        "id": "59356fea636239643921e324",
        "name": "Samsung Galaxy S6 Edge+ (64 GB)"
    },
    {
        "id": "59356fea636239643921e325",
        "name": "Samsung Galaxy S7 (32 GB)"
    },
    {
        "id": "59356fea636239643921e326",
        "name": "Samsung Galaxy S7 Edge (32 GB)"
    },
    {
        "id": "59356feb636239643921e38f",
        "name": "Samsung Galaxy S8 (64 GB)"
    },
    {
        "id": "59356feb636239643921e390",
        "name": "Samsung Galaxy S8+ (64 GB)"
    },
    {
        "id": "59356fea636239643921e30a",
        "name": "Samsung Galaxy SII (16 GB)"
    },
    {
        "id": "59356fea636239643921e312",
        "name": "Samsung Galaxy SIII mini VE (8 GB)"
    },
    {
        "id": "59356fea636239643921e313",
        "name": "Samsung Galaxy SIII mini VE (16 GB)"
    },
    {
        "id": "59356fea636239643921e329",
        "name": "Samsung Galaxy Young 2 (4 GB)"
    },
    {
        "id": "59356fea636239643921e356",
        "name": "Samsung J5 (8 GB)"
    },
    {
        "id": "59356fea636239643921e357",
        "name": "Samsung J5 (2016) (16 GB)"
    },
    {
        "id": "59356fea636239643921e32c",
        "name": "Sony Xperia E1 (4 GB)"
    },
    {
        "id": "59356fea636239643921e32d",
        "name": "Sony Xperia E3 (4 GB)"
    },
    {
        "id": "59356feb636239643921e38c",
        "name": "Sony Xperia E4 (8 GB)"
    },
    {
        "id": "59356fea636239643921e32e",
        "name": "Sony Xperia E4g (8 GB)"
    },
    {
        "id": "59356feb636239643921e38d",
        "name": "Sony Xperia E5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e332",
        "name": "Sony Xperia M (4 GB)"
    },
    {
        "id": "59356fea636239643921e333",
        "name": "Sony Xperia M2 (8 GB)"
    },
    {
        "id": "59356fea636239643921e334",
        "name": "Sony Xperia M2 Aqua (8 GB)"
    },
    {
        "id": "59356fea636239643921e335",
        "name": "Sony Xperia M4 Aqua (8 GB)"
    },
    {
        "id": "59356fea636239643921e336",
        "name": "Sony Xperia M5 (16 GB)"
    },
    {
        "id": "59356fea636239643921e33c",
        "name": "Sony Xperia T2 Ultra (8 GB)"
    },
    {
        "id": "59356fea636239643921e33d",
        "name": "Sony Xperia T3 (8 GB)"
    },
    {
        "id": "59356fea636239643921e35d",
        "name": "Sony Xperia X (32 GB)"
    },
    {
        "id": "59356fea636239643921e35e",
        "name": "Sony Xperia XA (16 GB)"
    },
    {
        "id": "59356fea636239643921e35f",
        "name": "Sony Xperia XA Ultra (16 GB)"
    },
    {
        "id": "59356fea636239643921e340",
        "name": "Sony Xperia Z Ultra (16 GB)"
    },
    {
        "id": "59356fea636239643921e341",
        "name": "Sony Xperia Z1 (16 GB)"
    },
    {
        "id": "59356fea636239643921e342",
        "name": "Sony Xperia Z1 Compact (16 GB)"
    },
    {
        "id": "59356fea636239643921e343",
        "name": "Sony Xperia Z2 (16 GB)"
    },
    {
        "id": "59356fea636239643921e345",
        "name": "Sony Xperia Z3 (16 GB)"
    },
    {
        "id": "59356fea636239643921e346",
        "name": "Sony Xperia Z3 (32 GB)"
    },
    {
        "id": "59356fea636239643921e344",
        "name": "Sony Xperia Z3 Compact (16 GB)"
    },
    {
        "id": "59356fea636239643921e347",
        "name": "Sony Xperia Z3+ (32 GB)"
    },
    {
        "id": "59356fea636239643921e349",
        "name": "Sony Xperia Z5 (32 GB)"
    },
    {
        "id": "59356fea636239643921e348",
        "name": "Sony Xperia Z5 Compact (32 GB)"
    },
    {
        "id": "59356fea636239643921e34a",
        "name": "Sony Xperia Z5 Premium (32 GB)"
    },
    {
        "id": "59356fea636239643921e354",
        "name": "Vodafone Smart ultra 6 (16 GB)"
    },
    {
        "id": "59356feb636239643921e381",
        "name": "WileyFox Spark X (16 GB)"
    },
    {
        "id": "59356fea636239643921e34c",
        "name": "WileyFox Storm (32 GB)"
    },
    {
        "id": "59356fea636239643921e34b",
        "name": "WileyFox Swift (16 GB)"
    },
    {
        "id": "59356feb636239643921e387",
        "name": "WileyFox Swift 2 (16 GB)"
    },
    {
        "id": "59356feb636239643921e388",
        "name": "WileyFox Swift 2 (32 GB)"
    }

];
            var options = {
              keys: ['name'],
              shouldSort: true,
              threshold: 0.4,
            }
    var fuse = new Fuse(data, options);
    /*
    $.ajax({
        url: '/search-phone',
        type: 'GET',
        success: function(result, fuse) {
            var options = {
              keys: ['name'],
              id: 'id'
            }
            this.fuse = new Fuse(result, options);
        }.bind(this)
    });
*/
    function fuseSearchPhones(query, syncResults, asyncResults) {
        syncResults = fuse.search(query);
    }

    var searchPhones = new Bloodhound({
        datumTokenizer: Bloodhound.tokenizers.obj.whitespace('name'),
        queryTokenizer: Bloodhound.tokenizers.whitespace,
        prefetch: {
            'url': '/search-phone',
            'ttl': 1800000  // 30 minutes
        },
        identify: function(obj) { return obj.id; },
        sorter: function(a, b) {
            var rxA = /(.*)\(([0-9]+)\s?GB\)/g;
            var rxB = /(.*)\(([0-9]+)\s?GB\)/g;
            var arrA = rxA.exec(a.name);
            var arrB = rxB.exec(b.name);
            if (arrA === null || arrB === null) {
                return mySort(a.name, b.name);
            } else if (arrA[1] == arrB[1]) {
                return mySort(parseInt(arrA[2]), parseInt(arrB[2]));
            } else {
                return mySort(arrA[1], arrB[1]);
            }
        }
    });

    var delayTimer;
    function sendSearch(page) {
        clearTimeout(delayTimer);
        delayTimer = setTimeout(function() {
            dataLayer.push({
              event: 'Search',
                'GAPage':page
            });
            //console.log(page);
        }, 1000);
    }

    var searchPhonesWithGa = function (q, sync) {
        var page = '/search?q=' + q;
        sendSearch(page);
        if (typeof fuse !== 'undefined') {
            results = fuse.search(q);
            console.log(results);
            sync(results);
        } else {
            alert('no fuse');
        }
    }

    $('#search-phone').typeahead({
        highlight: true,
        minLength: 1,
        hint: true,
    },
    {
        name: 'searchPhonesWithGa',
        source: searchPhonesWithGa.bind(this),
        display: 'name',
        limit: 100,
        templates: {
            notFound: [
              '<div class="empty-message">',
                'We couldn\x27t find that phone. Try searching for the make (e.g. iPhone 7), or <a href="mailto:hello@wearesosure.com" class="open-intercom">ask us</a>',
              '</div>'
            ].join('\n')
        }
    });

    // Stop the content flash when rendering the input
    $('#loading-search-phone').fadeOut('fast', function() {

        $('#search-phone-form').fadeIn();

        if(window.location.href.indexOf('?quote=1') != -1) {
            $('#search-phone').focus();
            sosuretrack('Get A Quote Link');
        }
    });

    $('#search-phone').bind('typeahead:selected', function(ev, suggestion) {
        $('#search-phone-form').unbind('submit', preventDefault);
    });

    $('#search-phone').bind('typeahead:select', function(ev, suggestion) {
        $('#search-phone-form').attr('action', '/phone-insurance/' + suggestion.id);
    });

});
