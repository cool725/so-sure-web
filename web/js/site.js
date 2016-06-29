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

