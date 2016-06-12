
var ctx = $("#connectionsChart").get(0).getContext("2d");
var max_value = $('#connection-value').data('slider-max');
var maxpot_value = $('#connection-value').data('slider-maxpot');
var initial_value = $('#connection-value').data('slider-value');
var adjusted_potential_value = maxpot_value - (max_value * 10);
var data = [
    {
        value: initial_value,
        color:"#3399FF",
        highlight: "#3399FF",
        // If changing text, verify tooltipTemplate isn't affected
        label: "You get back"
    },
    {
        value: max_value - initial_value,
        color:"#202532",
        highlight: "#6F6F6F",
        // If changing text, verify tooltipTemplate isn't affected
        label: "You'll pay"
    },
]

function roundToTwo(num) {
    return +(Math.round(num + "e+2")  + "e-2");
}

var connectionsDoughnutChart = new Chart(ctx).Doughnut(data, {
    tooltipTemplate: "<%if (label){%><%=label%>: <%}%>£" +
                     "<% if (label && label.indexOf('Pot ') > -1) { " +
                         "if (value * 10 > " + maxpot_value + "){%>" +
                             maxpot_value +
                          "<%} else {%>" +
                             "<%= value * 10 %>" +
                          "<% }" +
                     "} else { %>" +
                         "<%= roundToTwo(" + adjusted_potential_value + " + value * 10 ) %>" +
                     "<% }%>",
    legendTemplate : '<ul>'
                  +'<% for (var i=0; i<data.length; i++) { %>'
                    +'<li>'
                    +'<span style=\"color:<%=data[i].color%>\"><i class=\"fa fa-circle\"></i></span><span id=\"connectionChartLegend-<%= i %>\">'
                    +'<% if (data[i].label) { %><%= data[i].label %>: £<% } %>'
                  +'</span></li>'
                +'<% } %>'
              +'</ul>'
});

var setConnectionText = function() {
    var save_value = slider.getValue() * 10;
    if (save_value > maxpot_value) {
        save_value = maxpot_value;
    }
    var potential_value = roundToTwo(maxpot_value - save_value);
    var connectionText = "Connect with " + slider.getValue() + " friend(s) and provided none of you claim you'll get £" + save_value + " back at the end of the year.";
    $('#connectionLegend').text(connectionText);
    var potText = $('#connectionChartLegend-0').text().replace(/£.*/, '£' + save_value);
    $('#connectionChartLegend-0').text(potText);
    var potentialText = $('#connectionChartLegend-1').text().replace(/£.*/, '£' + potential_value);
    $('#connectionChartLegend-1').text(potentialText);
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

$('#connectionsChartLegend').html(connectionsDoughnutChart.generateLegend());
setConnectionText();
