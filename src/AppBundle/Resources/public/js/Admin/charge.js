$(function() {
    // Makes it so that when the charge type option is not affiliate, the affiliate box is disabled
    $("#typeMenu").on("change", function() {
        if ($(this).val() != "affiliate") {
            $("#affiliateMenu").attr("disabled", "disabled");
        } else {
            $("#affiliateMenu").removeAttr("disabled");
        }
    });

    // if you change the charge type box before this script has loaded, weird stuff happens, so it
    // shall be hidden until then
    $("#reportForm").removeAttr("hidden");
});
