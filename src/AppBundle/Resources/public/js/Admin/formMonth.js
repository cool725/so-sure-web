/* This file works similarly to month.js, but it doesn't mess around with the url */
$(function() {
    var picker = $('.month');
    picker.datetimepicker({
        format: 'MM-YYYY',
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false
    });
    if (picker.data("DateTimePicker").getDate() == '') {
        picker.data("DateTimePicker").date(new Date());
    }
});
