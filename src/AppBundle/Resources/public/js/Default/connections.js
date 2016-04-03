
var ctx = $("#connectionsChart").get(0).getContext("2d");
var max_value = $('#connection-value').data('slider-max');
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
        label: "Possible additional savings"
    },
]

var connectionsDoughnutChart = new Chart(ctx).Doughnut(data, {
    tooltipTemplate: "<%if (label){%><%=label%>: <%}%>£<%= value * 10 %>",    
});

var setConnectionText = function() {
    var connectionText = "With " + slider.getValue() + " connections, you could save £" + slider.getValue() * 10 + " if you and your friend don't claim in the year";
    $('#connectionLegend').text(connectionText);
}

var updateValue = function() {
    connectionsDoughnutChart.segments[0].value = slider.getValue();
    connectionsDoughnutChart.segments[1].value = max_value - slider.getValue();
    connectionsDoughnutChart.update();
    setConnectionText();
}

var slider = $('#connection-value').slider()
        .on('change', updateValue)
		.data('slider');

setConnectionText();
