window.addEventListener("load", function(){
    window.cookieconsent.initialise({
        theme: "Corporate",
        position: "top",
        static: "true",
        palette: {
            popup: {
                background: "#202532",
                text: "#efefef"
            },
            button: {
                background: "transparent",
                border: "#efefef",
                text: "#efefef"
            }
        },
        content: {
            message: "<small>This website uses cookies to ensure you get the best experience on our website.</small>",
            dismiss: "<i class='fa fa-times' aria-hidden='true'></i> <small>Close</small>",
            link: "<small>Learn More</small>",
            href: "https://www.iubenda.com/privacy-policy/7805295/cookie-policy"
        }
    });
});
