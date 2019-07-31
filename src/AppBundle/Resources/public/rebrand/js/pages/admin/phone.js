// phone.js

// require('../../../sass/pages/picsure.scss');

// Require BS component(s)
// e.g. require('bootstrap/js/dist/carousel');

// Require components
require('datatables.net')(window, $);
require('datatables.net-dt')(window, $);
require('moment');
require('tempusdominus-bootstrap-4');

let compareValue = (key, check, source, compare, display, offset) => {
    if (!display) {
        display = check[key];
    }
    if (!source) {
        return display;
    }
    let color = 'text-danger';
    if (compare == 'gte') {
        if (check[key] >= source[key]) {
          color = 'text-success';
        }
    } else if (compare == 'eq') {
        if (check[key] == source[key]) {
          color = 'text-success';
        }
    } else if (compare == 'lte') {
        if (check[key] <= source[key]) {
          color = 'text-success';
        }
    } else if (compare == 'within') {
        if (check[key] >= source[key] - offset && check[key] <= source[key] + offset) {
          color = 'text-success';
        }
    } else if (compare == 'lto') {
        if (check[key] <= source[key] + offset) {
          color = 'text-success';
        }
    }
    // TODO: Put class to td
    return '<span class="' + color + '">' + display + '</span>';
}

let phoneToRow = (item, compare) => {
    let row_data = [
        compareValue('memory', item, compare, 'gte', item['name']),
        item['replacement_price'],
        item['os'],
        compareValue('processor_speed', item, compare, 'gte'),
        compareValue('processor_cores', item, compare, 'gte'),
        compareValue('ram', item, compare, 'gte'),
        compareValue('ssd', item, compare, 'eq'),
        compareValue('screen_physical', item, compare, 'within', item['screen_physical_inch'], 13),
        item['screen_resolution'],
        compareValue('camera', item, compare, 'gte'),
        compareValue('lte', item, compare, 'eq'),
        compareValue('age', item, compare, 'lte'),
        compareValue('initial_price', item, compare, 'lto', null, 30)
    ];
    let row = "<tr><td>" + row_data.join('</td><td>') + "</td></tr>";

    return row;
}

$(function(){

    // Enable tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Alternative Models Modal
    $('#alternative_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget),
            query = button.data('query'),
            phone = button.data('phone'),
            modal = $(this);

        if (phone) {
            modal.find('.modal-title').text('Alternatives to ' + phone.name);
        }

        let current_table_body = modal.find('#current_table_body');
        current_table_body.empty();
        current_table_body.append(phoneToRow(phone));

        let replacement_table_body = modal.find('#replacement_table_body');
        replacement_table_body.empty();

        let table_body = modal.find('#alternative_table_body');
        table_body.empty();

        $.getJSON(query, function(data){
            if (data['suggestedReplacement']) {
                replacement_table_body.append(phoneToRow(data['suggestedReplacement'], phone));
            } else {
                replacement_table_body.append('<td colspan="13"><strong>No suggested replacement</strong></td>')
            }
            $.each( data['alternatives'], function( key, item ) {
                table_body.append(phoneToRow(item, phone));
            });
        });

    });

    // Phone Modal date change
    $('#date_time_picker_from').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
    });

    $('#date_time_picker_to').datetimepicker({
        format: "DD-MM-YYYY HH:mm",
        useCurrent: false
    });

    $('#date_time_picker_from').on('change.datetimepicker', function(e) {
        $('#date_time_picker_to').datetimepicker('minDate', e.date);
    });

    $('#date_time_picker_to').on('change.datetimepicker', function(e) {
        $('#date_time_pickr_from').datetimepicker('maxDate', e.date);
    });

    // Phone Modal
    $('#phone_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget),
            update = button.data('update'),
            phone = button.data('phone'),
            ipt_rate = 1 + button.data('ipt-rate'),
            salva_standard = button.data('salva-standard'),
            salva_min = button.data('salva-min'),
            latest = phone.prices.slice(-1)[0];
            modal = $(this);

        modal.find('#phone_update_form').attr('action', update);

        if (phone) {
            modal.find('#prices').DataTable({
                ordering: false,
                destroy: true,
                paging: false,
                searching: false,
                data: phone.prices,
                columns: [
                    { title: 'Valid From', data: 'valid_from' },
                    { title: 'Valid To', data: 'valid_to' },
                    { title: 'GWP', data: 'gwp' },
                    { title: 'Premium (current ipt rate)', data: 'premium' },
                    { title: 'Premium (ipt rate @ from date)', data: 'initial_premium' },
                    { title: 'Premium (ipt rate @ to date)', data: 'final_premium' },
                    { title: 'Excess (Damage/Theft)', data: 'excess_detail' },
                    { title: 'picsure approved Excess (Damage/Theft)', data: 'picsure_excess_detail' },
                    { title: 'Notes', data: 'notes' }
                ]
            });

            modal.find('.modal-title').text(phone.make + ' ' + phone.model + ' ' + phone.memory + 'GB');
            modal.find('#phone_gwp').val(phone.gwp);
            modal.find('#phone_salva').html('£' + salva_standard + ' / £' + salva_min);
            modal.find('#phone_desired_premium').val('');

            // Add excess to fields as defaults overwrite
            modal.find('#damage_excess').val(latest.excess.damage);
            modal.find('#warranty_excess').val(latest.excess.warranty);
            modal.find('#extended_warranty_excess').val(latest.excess.extendedWarranty);
            modal.find('#loss_excess').val(latest.excess.loss);
            modal.find('#theft_excess').val(latest.excess.theft);

            // Add pic-sure excess
            modal.find('#picsure_loss_excess').val(latest.picsure_excess[0].amount);
            modal.find('#picsure_theft_excess').val(latest.picsure_excess[1].amount);
            modal.find('#picsure_damage_excess').val(latest.picsure_excess[2].amount);
            modal.find('#picsure_warranty_excess').val(latest.picsure_excess[3].amount);
            modal.find('#picsure_extended_warranty_excess').val(latest.picsure_excess[3].amount);

            modal.find('#phone_gwp').keyup(function() {
                monthly = (modal.find('#phone_gwp').val() * ipt_rate).toFixed(2);
                modal.find('#phone_premium').val(monthly);
            });
            modal.find('#phone_gwp').keyup();

            modal.find('#phone_desired_premium_btn').on('click', function(e) {
                monthly = (modal.find('#phone_desired_premium').val() / ipt_rate).toFixed(2);
                $('#phone_gwp').val(monthly)
                modal.find('#phone_gwp').keyup();
            });
        }
    });

    // Details Modal
    $('#details_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget),
            update = button.data('update'),
            phone = button.data('phone'),
            modal = $(this);

        modal.find('#details_update_form').attr('action', update);

        if (phone) {
            modal.find('.modal-title').text('Edit ' + phone.make + ' ' + phone.model + ' ' + phone.memory + 'GB');
            modal.find('#details_description').val(phone.description);
            modal.find('#details_fun_facts').val(phone.funFacts);
            modal.find('#details_canonical_path').val(phone.canonicalPath);
        }
    });

    // Retail Modal
    $('#retail_modal').on('show.bs.modal', function (e) {
        let button = $(e.relatedTarget);
        let update = button.data('update');
        let phone = button.data('phone');
        let modal = $(this);
        modal.find('#retail_update_form').attr('action', update);
        if (phone) {
            modal.find('#retail_price').val(phone.currentRetailPrice);
            modal.find('#retail_url').val(phone.currentRetailPriceUrl);
        }
    });

    // Active/deactivate device
    $('.phone-active').on('click', function(e) {
        e.preventDefault();

        if (confirm('Are you sure you want to make this phone active/inactive?')) {
            let url = $(this).data('active'),
                token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
                success: function(result) {
                    window.location.reload(false);
                }
            });
        }
    });

    // Highlight/de-highlight device
    $('.phone-highlight').on('click', function(e) {
        e.preventDefault();

        if (confirm('Are you sure you want to make this phone (un)highlighted?')) {
            let url = $(this).data('highlight'),
                token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
                success: function(result) {
                    window.location.reload(false);
                }
            });
        }
    });

    // High demand phones
    $('.phone-newhighdemand').on('click', function(e) {
        e.preventDefault();

        if (confirm('Are you sure you want to set/unset this phone new & in high demand?')) {
            let url = $(this).data('newhighdemand'),
                token = $(this).data('token');
            $.ajax({
                url: url,
                type: 'POST',
                data: { token: token },
                success: function(result) {
                    window.location.reload(false);
                }
            });
        }
    });

    // Calculate premium
    // TODO: Clear if closed
    $('#check_premium_button').on('click', function(e) {
        $('#check_calculated_premium').val('Checking premium...');
        $.ajax({
            url: '/admin/phone/checkpremium/' + $('#check_initial_price').val(),
            type: 'POST',
            data: {
                price: $("#check_initial_price").val(),
                access_token: $(this).data('token')
            },
        })
        .done(function(result) {
            let data = $.parseJSON(result);
            $('#check_calculated_premium').val('£' + data.calculatedPremium);
        })
        .fail(function(result) {
            $('#check_calculated_premium').val('Error...');
        });
    });

});
