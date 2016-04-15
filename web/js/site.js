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
fb_pixel_event = $('#ss-root').data('fb-pixel-event');
if (fb_pixel_id !== '') {
  fbq('init', fb_pixel_id);
  fbq('track', "PageView");
  if (fb_pixel_event) {
    fbq('track', fb_pixel_event);
    //console.log('fb_pixel_event ' + fb_pixel_event);
  }
} else {
  //console.log('no fbpx');
}

// Facebook SDK + Send Button
window.fbAsyncInit = function() {
  FB.init({
    appId: $('#ss-root').data('fb-id'),
    xfbml: true,
    version: 'v2.5',
    status     : true
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

// Smooch
Smooch.init({
  appToken: $('#ss-root').data('smooch-key'),
  emailCaptureEnabled: true,
  customText: {
    settingsText: 'In case we\'re slow to respond you can leave us your email & we\'ll get back to you as soon as possible. Thanks.'
  }
});

// Branch
(function(b,r,a,n,c,h,_,s,d,k){if(!b[n]||!b[n]._q){for(;s<_.length;)c(h,_[s++]);d=r.createElement(a);d.async=1;d.src="https://cdn.branch.io/branch-latest.min.js";k=r.getElementsByTagName(a)[0];k.parentNode.insertBefore(d,k);b[n]=h}})(window,document,"script","branch",function(b,r){b[r]=function(){b._q.push([r,arguments])}},{_q:[],_v:1},"addListener applyCode banner closeBanner creditHistory credits data deepview deepviewCta first getCode init link logout redeem referrals removeListener sendSMS setIdentity track validateCode".split(" "), 0);
branch.init($('#ss-root').data('branch-key'));
branch_banner = $('#ss-root').data('branch-banner');
if (branch_banner !== '') {
  var data = {};
  var referral = $('#ss-root').data('referral');
  if (referral) {
    data = { data: { 'referral': referral }};
  }
  branch.banner({
      icon: 'https://cdn.so-sure.com/images/favicons/apple-touch-icon-180x180.png',
      title: 'so-sure - Quick Quote',
      description: 'Download our app for an instant quote.',
      phonePreviewText: '+44 XXXXX XXX XXX',
      sendLinkText: 'Text me a link',
  }, data);
} else {
  //console.log('no branch banner');
}

