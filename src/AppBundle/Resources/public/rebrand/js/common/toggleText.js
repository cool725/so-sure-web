// toggleText.js

$('.toggle-text[data-toggle="collapse"]').on('click', function(e){
    e.preventDefault();

    $(this)
    .data('text-original', $(this).html())
    .html($(this).data('text-swap') )
    .data('text-swap', $(this).data('text-original'));

});
