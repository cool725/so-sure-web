
var ctx = $("#connectionsChart").get(0).getContext("2d");
var max_value = $('#connection-value').data('slider-max');
var maxpot_value = $('#connection-value').data('slider-maxpot');
var initial_value = $('#connection-value').data('slider-value');

var data = [
    {
        value: initial_value,
        color:"#1B262D",
        highlight: "#3399FF",
        label: "Pot Value"
    },
    {
        value: max_value - initial_value,
        color:"#EFEFEF",
        highlight: "#6F6F6F",
        label: "Potential Value"
    },
]

var connectionsDoughnutChart = new Chart(ctx).Doughnut(data, {
    tooltipTemplate: "<%if (label){%><%=label%>: <%}%>£<%if (value * 10 > " + maxpot_value + "){%>" + maxpot_value + "<%} else {%><%= value * 10 %><% }%>",
});

var setConnectionText = function() {
    var save_value = slider.getValue() * 10;
    if (save_value > maxpot_value) {
        save_value = maxpot_value;
    }
    var connectionText = "With " + slider.getValue() + " connection(s), you could get £" + save_value + " back at the end of the year if you and your friend(s) don't claim.";
    $('#connectionLegend').text(connectionText);
}

var updateValue = function() {
    connectionsDoughnutChart.segments[0].value = slider.getValue();
    connectionsDoughnutChart.segments[1].value = max_value - slider.getValue();
    connectionsDoughnutChart.update();
    setConnectionText();
}

var slider = $('#connection-value').slider({
    formatter: function(value) {
        return value + ' connections';
    }})
    .on('change', updateValue)
    .data('slider');

setConnectionText();
