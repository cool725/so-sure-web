// turn on (or off) all the choices in a select-multiple
function selectAll(selectBox, selectAll) {
    // have we been passed an ID
    if (typeof selectBox == "string") {
        selectBox = document.getElementById(selectBox);
    }
    // is the select box a multiple select box?
    if (selectBox.type == "select-multiple") {
        for (var i = 0; i < selectBox.options.length; i++) {
            selectBox.options[i].selected = selectAll;
        }
    }
}

$(document).ready(function () {
    $('#claim_search_status-all').on("click", function () {
        selectAll(document.getElementById('claim_search_status'), true)
    });
    $('#claim_search_status-none').on("click", function () {
        selectAll(document.getElementById('claim_search_status'), false)
    });
});
var clipboard = new Clipboard('.btn-copy');

$('.btn-copy').tooltip({
    'title': 'Copied',
    'trigger': 'manual'
});

$('.btn-copy').click(function (e) {
    e.preventDefault();
});

clipboard.on('success', function (event) {
    $('.btn-copy').tooltip('show');
    setTimeout(function () {
        $('.btn-copy').tooltip('hide');
    }, 1500);
});

var ajax;

$('#claimsModal').on('hide.bs.modal', function (event) {
    var modal = $(this);

    ajax.abort();

    modal.find('.modal-content').html(
        '<div class="modal-body">\
            <div class="container-fluid">\
                <div class="row text-center">\
                    <i class="fa fa-spinner fa-pulse fa-5x fa-fw"></i>\
                </div>\
                <div class="row text-center">\
                    <h3>Loading...</h3>\
                </div>\
            </div>\
        </div>'
    );
});

$('#claimsModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    var modal = $(this);

    ajax = $.ajax({
        url: button.data('route'),
        type: "GET"
    });

    ajax.success(function (form) {
        modal.find('.modal-content').html(form);

        modal.find('#claims_form_approvedDate').datetimepicker({
            format: 'YYYY-MM-DD'
        });

        modal.find("#delete-claim").click(function(){
            if (confirm('Are you sure you want to delete this claim?')) {
                $("#delete-claim-form").submit();
            }
        });

        modal.find('.set-replacement-phone').click(function() {
            var id = $('.set-replacement-phone').data('id');
            $('#claims_form_replacementPhone').val(id).prop('selected', true);;
        });

        modal.find('.img-preview').viewer({
            inline: false,
            navbar: false
        });
    });
});

$('#flagsModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget) // Button that triggered the modal
    var flags = button.data('flags');
    var update = button.data('update');
    var modal = $(this);
    if (flags) {
        $('form[name="claimflags"]').attr('action', update);
        $.each(flags, function (item) {
            $('#claimflags_ignoreWarningFlags').find($("input[value='" + item + "']")).prop('checked', flags[item]);
        });
    } else {
        $('#claimFlags').attr('action', null);
        $('#claimflags_ignoreWarningFlags').find($("input[value='" + item + "']")).prop('checked', false);
    }
});
