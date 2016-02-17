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
heap_id = $('#ss-root').data('heap-id');
if (heap_id !== '') {
    heap.load(heap_id);
} else {
  //console.log('no heap');
}

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

ga_id = $('#ss-root').data('google-analytics');
if (ga_id !== '') {
  ga('create', ga_id, 'auto');
  ga('send', 'pageview');
} else {
  //console.log('no ga');
}

// Facebook Pixel
! function(f, b, e, v, n, t, s) {
  if (f.fbq) return;
  n = f.fbq = function() {
    n.callMethod ?
      n.callMethod.apply(n, arguments) : n.queue.push(arguments)
  };
  if (!f._fbq) f._fbq = n;
  n.push = n;
  n.loaded = !0;
  n.version = '2.0';
  n.queue = [];
  t = b.createElement(e);
  t.async = !0;
  t.src = v;
  s = b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t, s)
}(window,
  document, 'script', '//connect.facebook.net/en_US/fbevents.js');

fb_pixel_id = $('#ss-root').data('fb-pixel-id');
if (fb_pixel_id !== '') {
  fbq('init', fb_pixel_id);
  fbq('track', "PageView");
} else {
  //console.log('no fbpx');
}

// Facebook SDK + Send Button
window.fbAsyncInit = function() {
  FB.init({
    appId: $('#ss-root').data('fb-appid'),
    xfbml: true,
    version: 'v2.5'
  });
};

(function(d, s, id) {
  var js, fjs = d.getElementsByTagName(s)[0];
  if (d.getElementById(id)) {
    return;
  }
  js = d.createElement(s);
  js.id = id;
  js.src = "//connect.facebook.net/en_US/sdk.js";
  fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'facebook-jssdk'));

//Twitter Share Button
! function(d, s, id) {
  var js, fjs = d.getElementsByTagName(s)[0],
    p = /^http:/.test(d.location) ? 'http' : 'https';
  if (!d.getElementById(id)) {
    js = d.createElement(s);
    js.id = id;
    js.src = p + '://platform.twitter.com/widgets.js';
    fjs.parentNode.insertBefore(js, fjs);
  }
}(document, 'script', 'twitter-wjs');

// Twitter Embeds
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
