$('.confirm-cancel').on("click",function() {
    if ($('.cancellation-reason').val() == 'user-requested') {
        var confirm_cancel = prompt('Are you sure you want to cancel this policy immediately? User Requested cancellations typically are set one month in advance.  Type YES to cancel immediately.');
        return confirm_cancel === "YES";
    } else {
        return confirm('Are you sure you want to cancel this policy?');
    }
});

$('.confirm-pending-abort').on("click",function() {
    return confirm('Are you sure you want to abort the policy cancellation? The policy will remain active and the user will keep being charged.');
});

$('.confirm-pending-cancel').on("click",function() {
    return confirm('Are you sure you want to schedule this policy for cancellation?');
});

$('.confirm-imei-update').on("click",function() {
    return confirm('Are you sure you want to update this imei?  This should only be done if the phone imei was incorrectly entered or post claim once the IMEI has changed.  This will trigger a Salva Policy Update.');
});

$('.confirm-phone-update').on("click",function() {
    return confirm('Are you sure you want to update this phone?  This should only be done if the phone was incorrectly selected or post claim if the phone make/model has changed.  This will trigger a Salva Policy Update, and possibly affect the pricing at Salva, however, it will not update the policy premium charge to the customer.');
});

$('.confirm-facebook-clear').on("click",function() {
    return confirm('Are you sure you want to clear this facebook user??? They will not be able to login with facebook anymore.');
});

$('.confirm-imei-rerun').on("click",function() {
    return confirm('Are you sure you want to rerun the imei/serial number checks? This costs £0.07.');
});

$('.confirm-resend-email').on("click",function() {
    return confirm('Are you sure you want to resend the Policy Welcome Email?');
});

$('.confirm-regenerate-schedule').on("click",function() {
    return confirm('Are you sure you want to re-generate the Policy Schedule?');
});

$('.confirm-picsure').on("click",function() {
    return confirm('Are you sure you want to approve pic-sure?');
});

$('.confirm-pay-policy').on("click",function() {
    return confirm('Are you sure you want to pay for this partial policy?');
});

$('.confirm-salva-update').on("click",function() {
    return confirm('Are you sure you want to trigger an update with salva?');
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
