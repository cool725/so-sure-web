// Heap Analytics
window.heap = window.heap || [], heap.load = function(e, t) {
  window.heap.appid = e, window.heap.config = t = t || {};
  var n = t.forceSSL || "https:" === document.location.protocol,
    a = document.createElement("script");
  a.type = "text/javascript", a.async = !0, a.src = (n ? "https:" : "http:") + "//cdn.heapanalytics.com/js/heap-" + e + ".js";
  var o = document.getElementsByTagName("script")[0];
  o.parentNode.insertBefore(a, o);
  for (var r = function(e) {
      return function() {
        heap.push([e].concat(Array.prototype.slice.call(arguments, 0)))
      }
    }, p = ["clearEventProperties", "identify", "setEventProperties", "track", "unsetEventProperty"], c = 0; c < p.length; c++) heap[p[c]] = r(p[c])
};
heap.load("95184740");

// Google Anayltics
(function(i, s, o, g, r, a, m) {
  i['GoogleAnalyticsObject'] = r;
  i[r] = i[r] || function() {
    (i[r].q = i[r].q || []).push(arguments)
  }, i[r].l = 1 * new Date();
  a = s.createElement(o),
    m = s.getElementsByTagName(o)[0];
  a.async = 1;
  a.src = g;
  m.parentNode.insertBefore(a, m)
})(window, document, 'script', '//www.google-analytics.com/analytics.js', 'ga');

ga('create', 'UA-73109263-1', 'auto');
ga('send', 'pageview');

//Google Tag Manager
(function(w, d, s, l, i) {
  w[l] = w[l] || [];
  w[l].push({
    'gtm.start': new Date().getTime(),
    event: 'gtm.js'
  });
  var f = d.getElementsByTagName(s)[0],
    j = d.createElement(s),
    dl = l != 'dataLayer' ? '&l=' + l : '';
  j.async = true;
  j.src =
    '//www.googletagmanager.com/gtm.js?id=' + i + dl;
  f.parentNode.insertBefore(j, f);
})(window, document, 'script', 'dataLayer', 'GTM-5KBSPK');

// TypeKit
try {
  Typekit.load({
    async: true
  });
} catch (e) {};

// Twitter
window.twttr = (function(d, s, id) {
  var js, fjs = d.getElementsByTagName(s)[0],
    t = window.twttr || {};
  if (d.getElementById(id)) return t;
  js = d.createElement(s);
  js.id = id;
  js.src = "https://platform.twitter.com/widgets.js";
  fjs.parentNode.insertBefore(js, fjs);

  t._e = [];
  t.ready = function(f) {
    t._e.push(f);
  };

  return t;
}(document, "script", "twitter-wjs"));
