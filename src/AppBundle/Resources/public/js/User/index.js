$(function(){

    // Reward pot chart
    var rewardPot = $('#reward-pot-chart');
    var potValue  = $(rewardPot).data('pot-value');
    var maxPot    = $(rewardPot).data('max-pot');

    var totalInit = Math.round((potValue / maxPot) * 100);
    var total = totalInit / 100;

    $(rewardPot).circleProgress({
        value: total,
        size: 180,
        startAngle: -1.5,
        lineCap: 'round',
        emptyFill: '#efefef',
        fill: '#3399ff',
    });

    // Connection bonus chart
    var connectionChart = $('#connection-bonus-chart');
    var totalBonusDays  = $(connectionChart).data('bonus-days-total');
    var bonusDaysLeft   = $(connectionChart).data('bonus-days-remaining');

    var totalBonus = Math.round((bonusDaysLeft / totalBonusDays) * 100);
    var bonus = totalBonus / 100; 

    // console.log(bonus);

    $(connectionChart).circleProgress({
        value: bonus,
        size: 180,
        startAngle: -1.5,
        lineCap: 'round',
        emptyFill: '#efefef',
        fill: '#ff6666',
    });

    // Connect with
    $('#connect-with').click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#connect-with-box').offset().top - 100
        }, 1500);

        $('#connect-with-box').addClass('box-border--highlight');
    });    

    // Connect with
    $('#scode-link').click(function(event) {

        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#scode-box').offset().top - 100
        }, 1500);

        $('#scode-box').addClass('box-border--highlight');
    });    
    
    // Copy button on scode
    var clipboard = new Clipboard('.btn-clipboard');
    $('.btn-clipboard').tooltip({'title':'Copied', 'trigger':'manual'});

    clipboard.on('success', function(e) {
        $('.btn-clipboard').tooltip('show');
        setTimeout(function() { $('.btn-clipboard').tooltip('hide'); }, 1500);
    });    

    // Share buttons
    $("#share").jsSocials({
        shares: ["twitter", "facebook"],
        url: $('.btn-clipboard').data('clipboard-text'),
        text: $('.btn-clipboard').data('share-text'),
        shareIn: 'popup',
        showCount: false,
    });

    // Rollover control
    // var connection = $('.coffee-stamp');
    // var claim      = $('.coffee-stamp').data('has-claim');

    $('.coffee-stamp').each(function(){

        var hasClaim = $(this).data('has-claim');

        if(hasClaim == true) {
            
            console.log('Claims');
            $(this).popover('hide');
        
        } else {

            console.log('No Claims');
            $(this).popover();

        }

    });


    // if ($('.coffee-stamp').each().data('has-claim', 'false')) {

    //     console.log('No claims');

    // } else {

    //     console.log('Claimed');
    
    // }

});
