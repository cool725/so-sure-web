$(function(){

    // Chart.defaults.global.defaultFontFamily = '"Avenir LT Std 45 Book","Helvetica Neue",Helvetica,Arial,sans-serif';
    // Chart.defaults.global.tooltips.enabled = false;
    // var ctx = document.getElementById("myChart");
    // var maxConnections = $('.doughnut-outer').data('max-connections');
    // var connections = $('.doughnut-outer').data('connections');
    // var invites = $('.doughnut-outer').data('invites');

    // // a complete 0 value for data total will result in display not show
    // var data = [1, 1];
    // if (connections + invites > 0 && maxConnections != 0) {
    //     data = [
    //         Math.min(connections, maxConnections) / maxConnections,
    //         Math.min(connections + invites, maxConnections) / maxConnections
    //     ];
    // }
    // var myChart = new Chart(ctx, {
    //     type: 'doughnut',
    //     data: {
    //         labels: ["Connections [" + connections + "]", "Invites [" + invites + "]"],
    //         datasets: [{
    //             data: data,
    //             backgroundColor: [
    //                 'rgba(51, 153, 255, 0.8)',
    //                 'rgba(51, 204, 204, 0.8)'
    //             ]
    //         }]
    //     },
    //     options: {
    //       cutoutPercentage: 85
    //     }
    // });

    var rewardPot = $('#reward-pot-chart');
    var potValue  = $(rewardPot).data('pot-value');
    var maxPot    = $(rewardPot).data('max-pot');
    var percent   = Math.round((potValue / maxPot) * 100);
    var potDisp   = Math.round(potValue);

    // console.log(percent);

    // $(rewardPot).circliful({
    //     percent: percent,
    //     backgroundColor: '#efefef',
    //     foregroundColor: '#3399ff',
    //     backgroundBorderWidth: 10,
    //     foregroundBorderWidth: 10,

    //     fontColor: '#3399ff',
    //     replacePercentageByText: '£'+potDisp,
    //     textAdditionalCss: 'font-weight: bold; font-family: "Avenir LT Std 85 Heavy";',
    //     // text: 'of a maximum of £'+maxPot
    // });

    $('.btn-clipboard').tooltip({'title':'Copied', 'trigger': 'manual'});
    var clipboard = new Clipboard('.btn-clipboard');

    clipboard.on('success', function(e) {
        $('.btn-clipboard').tooltip('show');
        setTimeout(function() { $('.btn-clipboard').tooltip('hide'); }, 1500);
    });
    $("#share").jsSocials({
        shares: ["twitter", "facebook"],
        url: $('.btn-clipboard').data('clipboard-text'),
        text: $('.btn-clipboard').data('share-text'),
        shareIn: 'popup',
        showCount: false,
    });

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
