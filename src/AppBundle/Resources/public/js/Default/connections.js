
var ctx = $("#connectionsChart").get(0).getContext("2d");
var max_value = $('#connection-value').data('slider-max');
var maxpot_value = $('#connection-value').data('slider-maxpot');
var initial_value = $('#connection-value').data('slider-value');
var adjusted_potential_value = maxpot_value - (max_value * 10);
var data = [
    {
        value: initial_value,
        color:"#1B262D",
        highlight: "#3399FF",
        // If changing text, verify tooltipTemplate isn't affected
        label: "Pot Value"
    },
    {
        value: max_value - initial_value,
        color:"#EFEFEF",
        highlight: "#6F6F6F",
        // If changing text, verify tooltipTemplate isn't affected
        label: "Potential Value"
    },
]

function roundToTwo(num) {
    return +(Math.round(num + "e+2")  + "e-2");
}

var connectionsDoughnutChart = new Chart(ctx).Doughnut(data, {
    tooltipTemplate: "<%if (label){%><%=label%>: <%}%>£<% if (label && label.indexOf('Pot ') > -1) { if (value * 10 > " + maxpot_value + "){%>" + maxpot_value + "<%} else {%><%= value * 10 %><% }} else { %><%= roundToTwo(" + adjusted_potential_value + " + value * 10 ) %><% }%>"
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
