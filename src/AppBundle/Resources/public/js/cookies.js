window.addEventListener("load", function(){
  if (window.cookieconsent) {
    window.cookieconsent.initialise({
      palette: {
        popup: {
          background: "#237afc"
        },
        button: {
          background: "#fff",
          text: "#237afc"
        }
      },
      theme: "classic",
      position: "bottom-left",
      content: {
        dismiss: "Close",
        href: "https://www.iubenda.com/privacy-policy/7805295/cookie-policy"
      },
      dismissOnScroll: 500,
      onStatusChange: function(status) {
          this.element.parentNode.removeChild(this.element);
      }
    })
  }
});
