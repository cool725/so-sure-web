window.addEventListener("load", function(){
    window.cookieconsent.initialise({
        "palette": {
            "popup": {
                "background": "#3399ff",
                "text": "#efefef"
            },
            "button": {
                "background": "transparent",
                "border": "#efefef",
                "text": "#efefef"
            }
        },
        "theme": "Corporate",
        "position": "top",
        "static": true,
        "content": {
            "message": "<small>This website uses cookies to ensure you get the best experience on our website.</small>",
            "dismiss": "<i class='fa fa-times' aria-hidden='true'></i> Close",
            "link": "<small>Learn More</small>",
            "href": "https://www.iubenda.com/privacy-policy/7805295/cookie-policy"
        }
    });
});