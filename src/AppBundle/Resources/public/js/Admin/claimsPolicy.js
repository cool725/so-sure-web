$('.confirm-cancel').on("click",function() {
    if ($('.cancellation-reason').val() == 'user-requested') {
        var confirm_cancel = prompt('Are you sure you want to cancel this policy immediately? User Requested cancellations typically are set one month in advance.  Type YES to cancel immediately.');
        return confirm_cancel === "YES";
    } else {
        return confirm('Are you sure you want to cancel this policy?');
    }
});

$('.confirm-click').on("click",function(e) {
    e.preventDefault();
    var msg = $(this).data('confirm-msg');
    if (!msg || msg.length == 0) {
        msg = 'Are you sure?';
    }
    return confirm(msg);
});

$('.confirm-submit').on("click",function(e) {
    e.preventDefault();
    var msg = $(this).data('confirm-msg');
    if (!msg || msg.length == 0) {
        msg = 'Are you sure?';
    }
    if (confirm(msg)) {
        this.submit();
    } else {
        return false;
    }
});

$(document).ready(function(){
    $('.datetimepicker').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false
    });

    // although policy cancellations can be done 30 days from notice, notice may have been given a few days ago
    $('.datetimepicker30').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        allowInputToggle: true,
        showTodayButton: true,
        useCurrent: false,
        minDate: moment().add(20, 'days')
    });

    // Copy button on scode
    var clipboard = new Clipboard('.btn-copy');

    $('.btn-copy').tooltip({
        'title':'Copied', 
        'trigger':'manual'
    });

    $('.btn-copy').click(function(e) {
        e.preventDefault();
    });

    clipboard.on('success', function(event) {
        console.log(event);
        $('.btn-copy').tooltip('show');
        setTimeout(function() { $('.btn-copy').tooltip('hide'); }, 1500);        
    });
});
