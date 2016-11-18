$(function(){
    Chart.defaults.global.defaultFontFamily = '"Avenir LT Std 45 Book","Helvetica Neue",Helvetica,Arial,sans-serif';
    Chart.defaults.global.tooltips.enabled = false;
    var ctx = document.getElementById("myChart");
    var maxConnections = $('.doughnut-outer').data('max-connections');
    var connections = $('.doughnut-outer').data('connections');
    var invites = $('.doughnut-outer').data('invites');

    // a complete 0 value for data total will result in display not show
    var data = [1, 1];
    if (connections + invites > 0) {
        data = [
            Math.min(connections + invites, maxConnections) / maxConnections,
            Math.min(invites, maxConnections) / maxConnections
        ];
    }
    var myChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ["Connections [" + connections + "]", "Invites [" + invites + "]"],
            datasets: [{
                data: data,
                backgroundColor: [
                    'rgba(51, 153, 255, 0.8)',
                    'rgba(51, 204, 204, 0.8)'
                ]
            }]
        },
        options: {
          cutoutPercentage: 85
        }
    });

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
        shareIn: 'popup'
    })
});
