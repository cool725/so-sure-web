/* default bootstrap functionality stops tab links from behaving normally, so they are found and disconnected
 * from the bootstrap listeners */
$('#tabs li a').off();

$('.columnBtn').change(function() {
    $("."+$(this).data("column")).toggle($(this).prop("checked"));
});
