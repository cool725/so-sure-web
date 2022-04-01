// checkoutV2.js

require('../../sass/components/_checkoutV2.scss');

// Config
const form = $('#checkout-form');
const payButton = $('#checkout-pay-btn');
const cdn = $('#checkout-form').data('cdn-url');
const publicKey = $('#checkout-form').data('checkout-public-key');
const debug = $('#checkout-form').data('checkout-debug');
const csrf = $('#checkout-form').data('checkout-csrf');

function initCheckout() {
  Frames.init({
    publicKey,
    debug,
    acceptedPaymentMethods: [
      "Visa",
      "Mastercard"
    ]
  });
}

// Logos
const logos = generateLogos();

function generateLogos() {
  let logos = {};
  logos['card-number'] = {
    src: 'card',
    alt: 'card number logo',
  };
  logos['expiry-date'] = {
    src: 'exp-date',
    alt: 'expiry date logo',
  };
  logos['cvv'] = {
    src: 'cvv',
    alt: 'cvv logo',
  };
  return logos;
}

// Errors
let errors = {};

errors['card-number'] = 'Please enter a valid card number';
errors['expiry-date'] = 'Please enter a valid expiry date';
errors['cvv'] = 'Please enter a valid cvv code';

Frames.addEventHandler(
  Frames.Events.FRAME_VALIDATION_CHANGED,
  onValidationChanged
);
function onValidationChanged(event) {
  let e = event.element;

  if (event.isValid || event.isEmpty) {
    if (e === 'card-number' && !event.isEmpty) {
      showPaymentMethodIcon();
    }
    setDefaultIcon(e);
    clearErrorIcon(e);
    clearErrorMessage(e);
  } else {
    if (e === 'card-number') {
      clearPaymentMethodIcon();
    }
    setDefaultErrorIcon(e);
    setErrorIcon(e);
    setErrorMessage(e);
  }
}

function clearErrorMessage(el) {
  let selector = '.error-message__' + el;
  let message = document.querySelector(selector);
  message.textContent = '';
}

function clearErrorIcon(el) {
  let logo = document.getElementById('icon-' + el + '-error');
  logo.style.removeProperty('display');
}

function showPaymentMethodIcon(parent, pm) {
  if (parent) parent.classList.add('show');

  let logo = document.getElementById('logo-payment-method');
  if (pm) {
    let name = pm.toLowerCase();
    logo.setAttribute('src', cdn + name + '.svg');
    logo.setAttribute('alt', pm || 'payment method');
  }
  logo.style.removeProperty('display');
}

function clearPaymentMethodIcon(parent) {
  if (parent) parent.classList.remove('show');

  let logo = document.getElementById('logo-payment-method');
  logo.style.setProperty('display', 'none');
}

function setErrorMessage(el) {
  let selector = '.error-message__' + el;
  let message = document.querySelector(selector);
  message.textContent = errors[el];
}

function setDefaultIcon(el) {
  let selector = 'icon-' + el;
  let logo = document.getElementById(selector);
  logo.setAttribute('src', cdn + logos[el].src + '.svg');
  logo.setAttribute('alt', logos[el].alt);
}

function setDefaultErrorIcon(el) {
  let selector = 'icon-' + el;
  let logo = document.getElementById(selector);
  logo.setAttribute('src', cdn + logos[el].src + '-error.svg');
  logo.setAttribute('alt', logos[el].alt);
}

function setErrorIcon(el) {
  let logo = document.getElementById('icon-' + el + '-error');
  logo.style.setProperty('display', 'block');
}

Frames.addEventHandler(
  Frames.Events.CARD_VALIDATION_CHANGED,
  cardValidationChanged
);
function cardValidationChanged() {
  if (Frames.isCardValid()) {
    payButton.prop('disabled', false);
  }
}

Frames.addEventHandler(
  Frames.Events.CARD_TOKENIZATION_FAILED,
  onCardTokenizationFailed
);
function onCardTokenizationFailed(error) {
  alert(error);
  $('.loading-screen').fadeOut();
  payButton.prop('disabled', true);
  Frames.enableSubmitForm();
}

Frames.addEventHandler(
  Frames.Events.CARD_SUBMITTED,
  onCardSubmitted
);
function onCardSubmitted() {
  $('.loading-screen').fadeIn();
  payButton.prop('disabled', true);
}

Frames.addEventHandler(Frames.Events.CARD_TOKENIZED, onCardTokenized);
function onCardTokenized(data) {
  $('html, body').animate({ scrollTop: 0 }, 'fast');
  let scode = form.data('scode');
  let amount = form.data('checkout-transaction-value');
  let url  = form.data('checkout-url');
  let redirect = form.data('checkout-redirect');
  let paymentData;
  if (scode) {
    paymentData = {
      'csrf': csrf,
      'token': data.token,
      'pennies': amount,
      'scode': scode,
      '3ds': true
    }
  } else {
    paymentData = {
      'csrf': csrf,
      'token': data.token,
      'pennies': amount,
      '3ds': true
    }
  }
  $.post(url, paymentData, function(resp) {
    if (resp.code === 333) {
      window.location.href = resp.description;
    } else if (redirect) {
      window.location.href = redirect;
    } else {
      window.location.reload(false);
    }
  }).fail(function() {
    $('.loading-screen').fadeOut();
    // if (redirect) {
    //   window.location.href = redirect;
    // } else {
    //   window.location.reload(false);
    // }
  })
}

Frames.addEventHandler(
  Frames.Events.PAYMENT_METHOD_CHANGED,
  paymentMethodChanged
);
function paymentMethodChanged(event) {
  let pm = event.paymentMethod;
  let container = document.querySelector('.icon-container.payment-method');

  if (!pm) {
    clearPaymentMethodIcon(container);
  } else {
    clearErrorIcon('card-number');
    showPaymentMethodIcon(container, pm);
  }
}

$(function(){
  initCheckout();

  form.on('submit', function(event){
    event.preventDefault();
    Frames.submitCard()
  });
});