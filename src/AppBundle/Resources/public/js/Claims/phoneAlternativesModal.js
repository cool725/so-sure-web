var compareValue = function(key, check, source, compare, display, offset) {
  if (!display) {
    display = check[key];
  }

  if (!source) {
    return display;
  }

  var color = 'text-danger';
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

  return '<span class="' + color + '">' + display + '</span>';
}


var phoneToRow = function(item, compare) {
  var row_data = [
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
  var row = "<tr><td>" + row_data.join('</td><td>') + "</td></tr>";

  return row;
}

$('#alternativeModal').on('show.bs.modal', function (event) {
  var button = $(event.relatedTarget) // Button that triggered the modal
  var query = button.data('query');
  var phone = button.data('phone');
  var modal = $(this);
  //modal.find('#phone-alternative-form').attr("action", update);
  if (phone) {
    modal.find('.modal-title').text('Alternatives to ' + phone.name);
  }

  var current_table_body = modal.find('#current-table-body');
  current_table_body.empty();
  current_table_body.append(phoneToRow(phone));
  var replacement_table_body = modal.find('#replacement-table-body');
  replacement_table_body.empty();
  var table_body = modal.find('#alternative-table-body');
  table_body.empty();

  $.getJSON(query, function( data ) {
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
