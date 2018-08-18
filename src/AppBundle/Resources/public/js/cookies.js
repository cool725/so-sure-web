window.addEventListener("load", function(){
  if (window.cookieconsent) {
    window.cookieconsent.initialise({
      container: document.getElementsByTagName("body"),
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
      }
    })
  }
});
