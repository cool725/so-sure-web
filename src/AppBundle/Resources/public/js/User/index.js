$(function(){ 
    var ctx = document.getElementById("myChart");
    var connections = $('.doughnut-outer').data('connections');
    var invites = $('.doughnut-outer').data('invites');
    var data = [connections, invites];
    var myChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ["Connections", "Invites"],
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
});