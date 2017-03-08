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

    console.log(bonus);

    $(connectionChart).circleProgress({
        value: bonus,
        size: 180,
        startAngle: -1.5,
        lineCap: 'round',
        emptyFill: '#efefef',
        fill: '#ff6666',
    });

    // Copy to clipboard
    $('.btn-clipboard').tooltip({'title':'Copied', 'trigger': 'manual'});
    var clipboard = new Clipboard('.btn-clipboard');

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

    // Scode form
    $('#scode-form-submit').click(function() {
        var url = '/user/scode/' + $('#scode-form-code').val();
        var token = $(this).data('token');
        $.ajax({
            url: url,
            type: 'POST',
            data: { token: token }
        }).done(function(result) {
            console.log(result);
            if (result.code == 0) {
                window.location = window.location;
            } else if (typeof result.description !== 'undefined') {
                $('#scode-form-code-err').text(result.description);
            } else {
                $('#scode-form-code-err').text('Unknown error, please try again');
            }
        }).fail(function(result) {
            if (typeof result.responseJSON !== 'undefined') {
                $('#scode-form-code-err').text(result.responseJSON.description);
            } else {
                $('#scode-form-code-err').text('Unknown error, please try again');
            }
        });
    });
});
