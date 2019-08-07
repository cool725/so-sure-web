var Encore = require('@symfony/webpack-encore');
var webpack = require('webpack');

Encore
    .enableSingleRuntimeChunk()

    // the project directory where all compiled assets will be stored
    .setOutputPath('web/css-js/')

    // the public path used by the web server to access the previous directory
    .setPublicPath('/css-js')

    // this creates a 'vendor.js' file with jquery and the bootstrap JS module plus popper
    .createSharedEntry('vendor', './web/components/vendor.js')

    // List all files here
    .addEntry('fontawesome', './src/AppBundle/Resources/public/rebrand/js/fontawesome.js')
    .addEntry('global', './src/AppBundle/Resources/public/rebrand/js/global.js')
    .addEntry('cookie', './src/AppBundle/Resources/public/rebrand/js/cookie.js')
    .addEntry('dev', './src/AppBundle/Resources/public/rebrand/js/dev.js')
    .addEntry('error', './src/AppBundle/Resources/public/rebrand/js/pages/error.js')
    .addEntry('login', './src/AppBundle/Resources/public/rebrand/js/pages/login.js')
    .addEntry('homepage', './src/AppBundle/Resources/public/rebrand/js/pages/homepage.js')
    .addEntry('homepage-xmas', './src/AppBundle/Resources/public/rebrand/js/pages/homepage-xmas.js')
    .addEntry('homepage-vday', './src/AppBundle/Resources/public/rebrand/js/pages/homepage-vday.js')
    .addEntry('quotepage', './src/AppBundle/Resources/public/rebrand/js/pages/quotepage.js')
    .addEntry('purchase', './src/AppBundle/Resources/public/rebrand/js/pages/purchase.js')
    .addEntry('purchase-personal', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-personal.js')
    .addEntry('purchase-phone', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-phone.js')
    .addEntry('purchase-pledge', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-pledge.js')
    .addEntry('purchase-payment', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-payment.js')
    .addEntry('purchase-bacs', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-bacs.js')
    .addEntry('purchase-remainder', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-remainder.js')
    .addEntry('landing', './src/AppBundle/Resources/public/rebrand/js/pages/landing.js')
    .addEntry('faq', './src/AppBundle/Resources/public/rebrand/js/pages/faq.js')
    .addEntry('onboarding', './src/AppBundle/Resources/public/rebrand/js/pages/onboarding.js')
    .addEntry('social-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/social-insurance.js')
    .addEntry('contact', './src/AppBundle/Resources/public/rebrand/js/pages/contact.js')
    .addEntry('careers', './src/AppBundle/Resources/public/rebrand/js/pages/careers.js')
    .addEntry('seo-pages', './src/AppBundle/Resources/public/rebrand/js/pages/seo-pages.js')
    .addEntry('phone-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/phone-insurance.js')
    .addEntry('landing-snapchat', './src/AppBundle/Resources/public/rebrand/js/pages/landing-snapchat.js')
    .addEntry('landing-snapchat-b', './src/AppBundle/Resources/public/rebrand/js/pages/landing-snapchat-b.js')
    .addEntry('landing-twitter', './src/AppBundle/Resources/public/rebrand/js/pages/landing-twitter.js')
    .addEntry('landing-facebook', './src/AppBundle/Resources/public/rebrand/js/pages/landing-facebook.js')
    .addEntry('landing-youtube', './src/AppBundle/Resources/public/rebrand/js/pages/landing-youtube.js')
    .addEntry('invite', './src/AppBundle/Resources/public/rebrand/js/pages/invite.js')
    .addEntry('company-phones', './src/AppBundle/Resources/public/rebrand/js/pages/company-phones.js')
    .addEntry('usa', './src/AppBundle/Resources/public/rebrand/js/pages/usa.js')

    // Admin files
    .addEntry('admin', './src/AppBundle/Resources/public/rebrand/js/pages/admin/admin.js')
    .addEntry('picsure', './src/AppBundle/Resources/public/rebrand/js/pages/admin/picsure.js')
    .addEntry('detected-imei', './src/AppBundle/Resources/public/rebrand/js/pages/admin/detected-imei.js')
    .addEntry('accounts', './src/AppBundle/Resources/public/rebrand/js/pages/admin/accounts.js')
    .addEntry('banking', './src/AppBundle/Resources/public/rebrand/js/pages/admin/banking.js')
    .addEntry('rewards', './src/AppBundle/Resources/public/rebrand/js/pages/admin/rewards.js')
    .addEntry('company', './src/AppBundle/Resources/public/rebrand/js/pages/admin/company.js')
    .addEntry('admin-users', './src/AppBundle/Resources/public/rebrand/js/pages/admin/admin-users.js')
    .addEntry('features', './src/AppBundle/Resources/public/rebrand/js/pages/admin/features.js')
    .addEntry('phone', './src/AppBundle/Resources/public/rebrand/js/pages/admin/phone.js')
    .addEntry('kpi', './src/AppBundle/Resources/public/rebrand/js/pages/admin/kpi.js')
    .addEntry('bacs', './src/AppBundle/Resources/public/rebrand/js/pages/admin/bacs.js')
    .addEntry('policy-validations', './src/AppBundle/Resources/public/rebrand/js/pages/admin/policy-validations.js')

    // Admin Extras
    .addEntry('datepicker-month', './src/AppBundle/Resources/public/rebrand/js/pages/admin/datepicker-month.js')
    .addEntry('datepicker-day', './src/AppBundle/Resources/public/rebrand/js/pages/admin/datepicker-day.js')
    .addEntry('datepicker-day-time', './src/AppBundle/Resources/public/rebrand/js/pages/admin/datepicker-day-time.js')
    .addEntry('confirm-modal', './src/AppBundle/Resources/public/rebrand/js/pages/admin/confirm-modal.js')

    // User files
    .addEntry('user', './src/AppBundle/Resources/public/rebrand/js/pages/user/user.js')
    .addEntry('user-dashboard', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-dashboard.js')
    .addEntry('user-unpaid', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-unpaid.js')
    .addEntry('user-purchase-bacs', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-purchase-bacs.js')
    .addEntry('user-payment', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-payment.js')
    .addEntry('user-renew', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-renew.js')
    .addEntry('user-cashback', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-cashback.js')
    .addEntry('opt-out', './src/AppBundle/Resources/public/rebrand/js/pages/opt-out.js')
    .addEntry('user-claim', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-claim.js')
    .addEntry('user-claim-damage', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-claim-damage.js')
    .addEntry('user-claim-theft-loss', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-claim-theft-loss.js')
    .addEntry('user-claim-submitted', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-claim-submitted.js')
    .addEntry('user-cancel', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-cancel.js')

    // Claim
     .addEntry('make-a-claim', './src/AppBundle/Resources/public/rebrand/js/pages/make-a-claim.js')

    // Pos
    .addEntry('pos', './src/AppBundle/Resources/public/rebrand/js/pages/pos.js')

    // Dev
    .addEntry('ops', './src/AppBundle/Resources/public/rebrand/js/pages/ops.js')
    .addEntry('rollbar-js-error', './src/AppBundle/Resources/public/rebrand/js/pages/rollbar-js-error.js')

    // .enableSingleRuntimeChunk()

    // allow legacy applications to use $/jQuery as a global variable
    .autoProvidejQuery()

    // enable source maps during development
    .enableSourceMaps(!Encore.isProduction())

    // empty the outputPath dir before each build
    .cleanupOutputBeforeBuild()

    // show OS notifications when builds finish/fail
    .enableBuildNotifications()

    // create hashed filenames (e.g. app.abc123.css)
    .enableVersioning()

    // allow sass/scss files to be processed
    .enableSassLoader()

    .addPlugin(
        new webpack.ProvidePlugin({
            $: "jquery",
            jQuery: "jquery",
            Bloodhound: "corejs-typeahead/dist/bloodhound.js",
            doT: "dot/doT.js",
            moment: "moment",
            fitText: "fitText",

        })
    )
;

var config = Encore.getWebpackConfig();

// disable amd, for datatable
config.module.rules.unshift({
  parser: {
    amd: false
  }
});

module.exports = config;

// export the final configuration
// module.exports = Encore.getWebpackConfig();
