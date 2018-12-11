$(function() {

    $('#annotate').on('load', function(){
        if ($('#picsureml_edit_form_x').val() && $('#picsureml_edit_form_y').val() && $('#picsureml_edit_form_width').val() && $('#picsureml_edit_form_height').val()) {
            $('#annotate').Jcrop({
                setSelect: [parseInt($('#picsureml_edit_form_x').val()), parseInt($('#picsureml_edit_form_y').val()), parseInt($('#picsureml_edit_form_width').val()), parseInt($('#picsureml_edit_form_height').val())],
                boxWidth: 400
            });
        }
        else {            
            $('#annotate').Jcrop({
                boxWidth: 400
            });
        }
    });

    $('#crop-interface').on('cropstart cropmove cropend', function(e,s,c){
      $('#picsureml_edit_form_x').val(parseInt(c.x));
      $('#picsureml_edit_form_y').val(parseInt(c.y));
      $('#picsureml_edit_form_width').val(parseInt(c.w));
      $('#picsureml_edit_form_height').val(parseInt(c.h));
    });

    if ($('#annotate')[0].complete) {
        $('#annotate').load();
    }

    $('#picsureml_edit_form_clear').on('click', function(){
      $('#picsureml_edit_form_x').val('');
      $('#picsureml_edit_form_y').val('');
      $('#picsureml_edit_form_width').val('');
      $('#picsureml_edit_form_height').val('');
      $('form[name="picsureml_edit_form"]').submit();
    })

});
