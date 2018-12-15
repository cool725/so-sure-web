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
    $('#scode-link').click(function(event) {
        event.preventDefault();

        $('html, body').animate({
            scrollTop: $('#scode-box').offset().top - 100
        }, 1500);

        $('#scode-box').addClass('box-border--highlight');
    });

    // Copy button on scode
    var clipboard = new Clipboard('.btn-copy');

    $('.btn-copy').tooltip({
        'title':'Copied',
        'trigger':'manual'
    });

    $('.btn-copy').click(function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(event) {
        $('.btn-copy').tooltip('show');

        sosure.track.byScodeCopied('user-home');

        setTimeout(function() { $('.btn-copy').tooltip('hide'); }, 1500);
    });

    // Share buttons
    $("#share").jsSocials({
        shares: ["twitter", "facebook", "whatsapp", "messenger", "googleplus"],
        url: $('#modal-connect').data('share-link'),
        text: $('#modal-connect').data('share-text'),
        shareIn: 'popup',
        showLabel: false,
        showCount: false,
        on: {
            click: function(e) {
                sosure.track.byInvite(this.share, 'user-home');
            }
        }
    });

    // Rollover control
    $('.coffee-stamp').each(function(){
        $(this).popover();
    });

});
