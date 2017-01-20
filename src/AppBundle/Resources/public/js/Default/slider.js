
var max_value = $('#slider-value').data('slider-max');
var maxpot_value = $('#slider-value').data('slider-maxpot');
var initial_value = $('#slider-value').data('slider-value');
var connection_value = $('#slider-value').data('slider-connection-value');
var adjusted_potential_value = maxpot_value - (max_value * connection_value);
var data = [
    {
        value: initial_value,
        color:"#3399FF",
        highlight: "#3399FF",
        // If changing text, verify tooltipTemplate isn't affected
        label: "Value of Your Reward Pot"
    },
    {
        value: max_value - initial_value,
        color:"#202532",
        highlight: "#6F6F6F",
        // If changing text, verify tooltipTemplate isn't affected
        label: "Remaining Reward Pot Value"
    },
]

function roundToTwo(num) {
    return +(Math.round(num + "e+2")  + "e-2");
}


var setConnectionText = function() {
    var save_value = slider.getValue() * connection_value;
    if (save_value > maxpot_value) {
        save_value = maxpot_value;
    }
    var potential_value = roundToTwo(maxpot_value - save_value);
    $('#num_friends').text(slider.getValue() + (slider.getValue() == 1 ? ' friend' : ' friends'));
    $('#money_back').text('£' + save_value);
}

var updateValue = function() {
    setConnectionText();
}

var slider = $('#slider-value').slider({
    formatter: function(value) {
        return value + ' connections';
    }})
    .on('change', updateValue)
    .data('slider');

//setConnectionText();
