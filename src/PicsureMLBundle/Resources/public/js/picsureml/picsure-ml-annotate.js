$(function() {

    $('#annotate').on('load', function(){
        if ($('#picsure_annotation_form_x').val() && $('#picsure_annotation_form_y').val() && $('#picsure_annotation_form_width').val() && $('#picsure_annotation_form_height').val()) {
            $('#annotate').Jcrop({
                setSelect: [parseInt($('#picsure_annotation_form_x').val()), parseInt($('#picsure_annotation_form_y').val()), parseInt($('#picsure_annotation_form_width').val()), parseInt($('#picsure_annotation_form_height').val())],
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
      $('#picsure_annotation_form_x').val(parseInt(c.x))
      $('#picsure_annotation_form_y').val(parseInt(c.y))
      $('#picsure_annotation_form_width').val(parseInt(c.w))
      $('#picsure_annotation_form_height').val(parseInt(c.h))
    });

    if ($('#annotate')[0].complete) {
        $('#annotate').load();
    }

});
