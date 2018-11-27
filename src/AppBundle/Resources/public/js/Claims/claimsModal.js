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
    console.log(event);
    $('.btn-copy').tooltip('show');
    setTimeout(function () {
        $('.btn-copy').tooltip('hide');
    }, 1500);
});

let ajax;

$('#claimsModal').on('hide.bs.modal', function (event) {
    let modal = $(this);

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
    let button = $(event.relatedTarget);
    let modal = $(this);

    console.log("this");

    ajax = $.ajax({
        url: '/admin/claim-info/' + button.data('id'),
        type: "GET"
    });

    ajax.success(function (form) {
        modal.find('.modal-content').html(form);
    });
});

$('#claimsModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget); // Button that triggered the modal
    var claim = button.data('claim');
    var documents = button.data('documents');
    var modal = $(this);

    if (claim) {
        if ($('#claims-detail-number')) {
            modal.find('#claims-detail-number').val(claim.number);
        }
        if ($('.modal-title')) {
            modal.find('.modal-title').text('Claim: ' + claim.number);
        }

        modal.find('#claims-detail-id').val(claim.id);
        modal.find('#claims-detail-delete-id').val(claim.id);
        modal.find('#claims-detail-policy').text(claim.policyNumber);
        modal.find('#claims-detail-type').val(claim.type);
        var status = claim.status;
        if (claim.daviesStatus) {
            status += ' (' + claim.daviesStatus + ')';
        }
        if (claim.status == 'fnol') {
            status = '<span class="text-warning" title="Claim not yet submitted to claims handler">' + status + '</span>';
        }
        modal.find('#claims-detail-status').html(status);
        modal.find('#claims-detail-handling-team').text(claim.handlingTeam);
        modal.find('#claims-detail-number-to-reach').text(claim.phoneToReach);
        modal.find('#claims-detail-time-to-reach').text(claim.timeToReach);
        modal.find('#claims-detail-initial-suspicion').text(claim.initialSuspicion);
        modal.find('#claims-detail-final-suspicion').text(claim.finalSuspicion);
        modal.find('#claims-detail-notes').text(claim.notes);
        modal.find('#claims-detail-incident-date').text(((claim.incidentDate) ? moment(claim.incidentDate).format('DD-MM-YYYY') : '') + ' at ' + claim.incidentTime);
        modal.find('#claims-detail-incident-location').text(claim.location);
        modal.find('#claims-detail-description').text(claim.description);

        modal.find('#claims-damage').hide();
        modal.find('#claims-theftloss').hide();

        if (claim.type == 'damage') {
            modal.find('#claims-damage').show();
            if (claim.typeDetails == 'other') {
                modal.find('#claims-detail-type-details').text(claim.typeDetailsOther);
            }
            else {
                modal.find('#claims-detail-type-details').text(claim.typeDetails);
            }
            modal.find('#claims-detail-bought').text(claim.monthOfPurchase + ' / ' + claim.yearOfPurchase);
            modal.find('#claims-detail-phone-status').text(claim.phoneStatus);
            modal.find('#claims-detail-warranty').text(claim.isUnderWarranty ? 'Yes' : 'No');

            if (documents) {
                if (claim.needProofOfUsage) {
                    if (documents.proofOfUsages.length > 0) {
                        var proofOfUsages = '';
                        $.each(documents.proofOfUsages, function (key, value) {
                            proofOfUsages += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-damage-proof-usages').html(proofOfUsages);
                    }
                    else {
                        modal.find('#claims-detail-damage-proof-usages').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-damage-proof-usages').html('Not requested');
                }

                if (claim.needProofOfPurchase) {
                    if (documents.proofOfPurchases.length > 0) {
                        var proofOfPurchases = '';
                        $.each(documents.proofOfPurchases, function (key, value) {
                            proofOfPurchases += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-proof-purchases').html(proofOfPurchases);
                    }
                    else {
                        modal.find('#claims-detail-proof-purchases').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-proof-purchases').html('Not requested');
                }

                if (claim.needPictureOfPhone) {
                    if (documents.damagePictures.length > 0) {
                        var damagePictures = '';
                        $.each(documents.damagePictures, function (key, value) {
                            damagePictures += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-pictures-phone').html(damagePictures);
                    }
                    else {
                        modal.find('#claims-detail-pictures-phone').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-pictures-phone').html('Not requested');
                }

                if (documents.others.length > 0) {
                    var others = '';
                    $.each(documents.others, function (key, value) {
                        others += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                    });
                    modal.find('#claims-detail-damage-others').html(others);
                }
                else {
                    modal.find('#claims-detail-damage-others').html('Not uploaded yet');
                }
            }
        }

        if (claim.type == 'theft' || claim.type == 'loss') {
            modal.find('#claims-theftloss').show();

            modal.find('#claims-detail-contacted').text(claim.hasContacted ? claim.contactedPlace : 'N/A');
            modal.find('#claims-detail-network').text(claim.network);
            modal.find('#claims-detail-blocked').text((claim.blockedDate) ? moment(claim.blockedDate).format('DD-MM-YYYY') : '');
            modal.find('#claims-detail-reported').text((claim.reportedDate) ? moment(claim.reportedDate).format('DD-MM-YYYY') : '');
            modal.find('#claims-detail-reported-to').text(claim.reportType);

            if (documents) {
                if (claim.needProofOfUsage) {
                    if (documents.proofOfUsages.length > 0) {
                        var proofOfUsages = '';
                        $.each(documents.proofOfUsages, function (key, value) {
                            proofOfUsages += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-theftloss-proof-usages').html(proofOfUsages);
                    }
                    else {
                        modal.find('#claims-detail-theftloss-proof-usages').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-theftloss-proof-usages').html('Not requested');
                }

                if (claim.needProofOfBarring) {
                    if (documents.proofOfBarrings.length > 0) {
                        var proofOfBarrings = '';
                        $.each(documents.proofOfBarrings, function (key, value) {
                            proofOfBarrings += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-proof-barrings').html(proofOfBarrings);
                    }
                    else {
                        modal.find('#claims-detail-proof-barrings').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-proof-barrings').html('Not requested');
                }

                if (claim.needProofOfPurchase) {
                    if (documents.proofOfPurchases.length > 0) {
                        var proofOfPurchases = '';
                        $.each(documents.proofOfPurchases, function (key, value) {
                            proofOfPurchases += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                        });
                        modal.find('#claims-detail-proof-purchases').html(proofOfPurchases);
                    }
                    else {
                        modal.find('#claims-detail-proof-purchases').html('Not uploaded yet');
                    }
                }
                else {
                    modal.find('#claims-detail-proof-purchases').html('Not requested');
                }

                if (claim.type == 'loss') {
                    if (claim.needProofOfLoss) {
                        if (documents.proofOfLosses.length > 0) {
                            var proofOfLosses = '';
                            $.each(documents.proofOfLosses, function (key, value) {
                                proofOfLosses += '<p>' + value.filename + ' <img class="img-preview" src="' + value.url + '" /> <a href="' + value.url_download + '"><i class="fa fa-download"></i></a></p>';
                            });
                            modal.find('#claims-detail-proof-losses').html(proofOfLosses);
                        }
                        else {
                            modal.find('#claims-detail-proof-losses').html('Not uploaded yet');
                        }
                    }
                    else {
                        modal.find('#claims-detail-proof-losses').html('Not requested');
                    }
                    modal.find('#claims-detail-proof-losses-container').show();
                }
                else {
                    modal.find('#claims-detail-proof-losses-container').hide();
                }

                if (documents.others.length > 0) {
                    var others = '';
                    $.each(documents.others, function (key, value) {
                        others += '<p><a href="' + value.url + '">' + value.filename + '</a></p>';
                    });
                    modal.find('#claims-detail-theftloss-others').html(others);
                }
                else {
                    modal.find('#claims-detail-theftloss-others').html('Not uploaded yet');
                }
            }

            var crimeRef = claim.crimeRef;
            if (!claim.validCrimeRef) {
                crimeRef += ' <i class="fa fa-warning" title="Invalid crime reference"></i>';
            }

            if (claim.type == 'theft') {
                modal.find('#claims-detail-crime-reference').html(crimeRef + ' / ' + claim.force);
                modal.find('#claims-detail-crime-reference-container').show();
                modal.find('#claims-detail-policy-report-container').hide();
            }
            else {
                modal.find('#claims-detail-policy-report').html(crimeRef + ' / ' + claim.force);
                modal.find('#claims-detail-crime-reference-container').hide();
                modal.find('#claims-detail-policy-report-container').show();
            }
        }

        modal.find('#claims-detail-replacement-imei').text(claim.replacementImei);
        if (!claim.validReplacementImei) {
            modal.find('#claims-detail-replacement-imei').html('<s>' + claim.replacementImei + '</s> <i class="fa fa-warning" title="Invalid IMEI Number (Luhn Failure)"></i>');
        }
        modal.find('#claims-detail-replacement-phone-details').text(claim.replacementPhoneDetails);
        modal.find('#claims-detail-replacement-phone').val(claim.replacementPhoneId);
        modal.find('#claims-detail-policy-phone').text(claim.policyPhone);
        modal.find('#claims-detail-loss').text((claim.lossDate) ? moment(claim.lossDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-notification').text((claim.notificationDate) ? moment(claim.notificationDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-recorded').text((claim.recordedDate) ? moment(claim.recordedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-approved').val((claim.approvedDate) ? moment(claim.approvedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-approved-show').text((claim.approvedDate) ? moment(claim.approvedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-replacement').text(
            (claim.replacementReceivedDate) ? moment(claim.replacementReceivedDate).format('DD-MM-YYYY') : ''
        );
        modal.find('#claims-detail-closed').text((claim.closedDate) ? moment(claim.closedDate).format('DD-MM-YYYY') : '');
        modal.find('#claims-detail-excess').text(claim.excess);
        modal.find('#claims-detail-unauthorized-calls').text(claim.unauthorizedCalls);
        modal.find('#claims-detail-accessories').text(claim.accessories);
        modal.find('#claims-detail-replacement-cost').text(claim.phoneReplacementCost);
        modal.find('#claims-detail-transaction').text(claim.transactionFees);
        modal.find('#claims-detail-handling').text(claim.claimHandlingFees);
        modal.find('#claims-detail-reserved').text(claim.reservedValue);
        modal.find('#claims-detail-total-incurred').text(claim.totalIncurred);
    } else {
        modal.find('.modal-title').text('Claim: Unknown');
        modal.find('#claims-detail-id').val('');
        modal.find('#claims-detail-delete-id').val('');
        modal.find('#claims-detail-policy').text('');
        modal.find('#claims-detail-type').text('');
        modal.find('#claims-detail-status').text('');
        modal.find('#claims-detail-initial-suspicion').text('');
        modal.find('#claims-detail-final-suspicion').text('');
        modal.find('#claims-detail-davies-status').text('');
        modal.find('#claims-detail-notes').text('');
        modal.find('#claims-detail-description').text('');
        modal.find('#claims-detail-replacement-imei').text('');
        modal.find('#claims-detail-replacement-phone-details').text('');
        modal.find('#claims-detail-replacement-phone').val('');
        modal.find('#claims-detail-policy-phone').text('');
        modal.find('#claims-detail-loss').text('');
        modal.find('#claims-detail-notification').text('');
        modal.find('#claims-detail-recorded').text('');
        modal.find('#claims-detail-approved').text('');
        modal.find('#claims-detail-replacement').text('');
        modal.find('#claims-detail-closed').text('');
        modal.find('#claims-detail-excess').text('');
        modal.find('#claims-detail-unauthorized-calls').text('');
        modal.find('#claims-detail-accessories').text('');
        modal.find('#claims-detail-replacement-cost').text('');
        modal.find('#claims-detail-transaction').text('');
        modal.find('#claims-detail-handling').text('');
        modal.find('#claims-detail-reserved').text('');
        modal.find('#claims-detail-total-incurred').text('');
    }

    $("#change-claim-number").change(function () {
        if (this.checked) {
            $('#claims-detail-number').attr('readonly', false);
        } else {
            $('#claims-detail-number').attr('readonly', true);
        }
    });

    $("#change-claim-type").change(function () {
        if (this.checked) {
            $('#claims-detail-type').attr('disabled', false);
        } else {
            $('#claims-detail-type').attr('disabled', 'disabled');
        }
    });

    $("#change-approved-date").change(function () {
        if (this.checked) {
            $('#claims-detail-approved').attr('readonly', false).datetimepicker({
                format: 'DD-MM-YYYY'
            });
        }
    });

    $("#delete-button").click(function () {
        if (confirm('Are you sure you want to delete this claim?')) {
            $("#delete-claim-form").submit();
        }
    });

    $("#update-button").click(function () {
        //data setup before submit
        if ($("#change-approved-date").is(':checked')) {
            $('#new-approved-date').val(moment($('#claims-detail-approved').text()).format('YYYY-MM-DD'));
        }
        $("#phone-alternative-form").submit();
    });

    $("#update-replacement-phone").change(function () {
        if (this.checked) {
            $('#claims-detail-replacement-phone').attr('disabled', false);
        } else {
            $('#claims-detail-replacement-phone').attr('disabled', 'disabled');
        }
    });

    $('.img-preview').viewer({
        inline: false,
        navbar: false
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
