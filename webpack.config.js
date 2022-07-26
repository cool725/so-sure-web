const Encore = require('@symfony/webpack-encore');
const webpack = require('webpack');
const BundleAnalyzerPlugin = require('webpack-bundle-analyzer').BundleAnalyzerPlugin;

Encore
    // Using runtime in it's own js
    .enableSingleRuntimeChunk()

    // the project directory where all compiled assets will be stored
    .setOutputPath('web/css-js/')

    // the public path used by the web server to access the previous directory
    .setPublicPath('/css-js')

    // List all files here
    .addEntry('global', './src/AppBundle/Resources/public/rebrand/js/global.js')
    .addEntry('dev', './src/AppBundle/Resources/public/rebrand/js/dev.js')
    .addEntry('error', './src/AppBundle/Resources/public/rebrand/js/pages/error.js')
    .addEntry('login', './src/AppBundle/Resources/public/rebrand/js/pages/login.js')
    .addEntry('homepage', './src/AppBundle/Resources/public/rebrand/js/pages/homepage.js')
    .addEntry('quotepage', './src/AppBundle/Resources/public/rebrand/js/pages/quotepage.js')
    .addEntry('purchase-quote', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-quote.js')
    .addEntry('purchase', './src/AppBundle/Resources/public/rebrand/js/pages/purchase.js')
    .addEntry('purchase-personal', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-personal.js')
    .addEntry('purchase-phone', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-phone.js')
    .addEntry('purchase-pledge', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-pledge.js')
    .addEntry('purchase-payment', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-payment.js')
    .addEntry('purchase-bacs', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-bacs.js')
    .addEntry('purchase-remainder', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-remainder.js')
    .addEntry('landing', './src/AppBundle/Resources/public/rebrand/js/pages/landing.js')
    .addEntry('landing-search', './src/AppBundle/Resources/public/rebrand/js/pages/landing-search.js')
    .addEntry('content-pages', './src/AppBundle/Resources/public/rebrand/js/pages/content-pages.js')
    .addEntry('onboarding', './src/AppBundle/Resources/public/rebrand/js/pages/onboarding.js')
    .addEntry('social-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/social-insurance.js')
    .addEntry('contact', './src/AppBundle/Resources/public/rebrand/js/pages/contact.js')
    .addEntry('careers', './src/AppBundle/Resources/public/rebrand/js/pages/careers.js')
    .addEntry('seo-pages', './src/AppBundle/Resources/public/rebrand/js/pages/seo-pages.js')
    .addEntry('phone-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/phone-insurance.js')
    .addEntry('phone-insurance-make', './src/AppBundle/Resources/public/rebrand/js/pages/phone-insurance-make.js')
    .addEntry('phone-insurance-quote', './src/AppBundle/Resources/public/rebrand/js/pages/phone-insurance-quote.js')
    .addEntry('invite', './src/AppBundle/Resources/public/rebrand/js/pages/invite.js')
    .addEntry('company-phones', './src/AppBundle/Resources/public/rebrand/js/pages/company-phones.js')
    .addEntry('starling-business', './src/AppBundle/Resources/public/rebrand/js/pages/starling-business.js')
    .addEntry('usa', './src/AppBundle/Resources/public/rebrand/js/pages/usa.js')
    .addEntry('promo', './src/AppBundle/Resources/public/rebrand/js/pages/promo.js')
    .addEntry('blog', './src/AppBundle/Resources/public/rebrand/js/pages/blog.js')
    .addEntry('competition', './src/AppBundle/Resources/public/rebrand/js/pages/competition.js')
    .addEntry('competition-questions', './src/AppBundle/Resources/public/rebrand/js/pages/competition-questions.js')
    .addEntry('competition-confirm', './src/AppBundle/Resources/public/rebrand/js/pages/competition-confirm.js')
    .addEntry('invite-influencer', './src/AppBundle/Resources/public/rebrand/js/pages/invite-influencer.js')

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
    .addEntry('offer', './src/AppBundle/Resources/public/rebrand/js/pages/admin/offer.js')
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
    // .addEntry('user-competition', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-competition.js')
    .addEntry('user-referral', './src/AppBundle/Resources/public/rebrand/js/pages/user/user-referral.js')

    // Upgrades
    .addEntry('upgrades', './src/AppBundle/Resources/public/rebrand/js/pages/user/upgrades.js')
    .addEntry('upgrades-imei', './src/AppBundle/Resources/public/rebrand/js/pages/user/upgrades-imei.js')
    .addEntry('upgrades-pledge', './src/AppBundle/Resources/public/rebrand/js/pages/user/upgrades-pledge.js')

    // Claim
     .addEntry('make-a-claim', './src/AppBundle/Resources/public/rebrand/js/pages/make-a-claim.js')

    // Pos
    .addEntry('pos', './src/AppBundle/Resources/public/rebrand/js/pages/pos.js')

    // Signup
    .addEntry('signup', './src/AppBundle/Resources/public/rebrand/js/pages/signup.js')

    // Refer
    .addEntry('refer', './src/AppBundle/Resources/public/rebrand/js/pages/refer.js')

    // Dev
    .addEntry('ops', './src/AppBundle/Resources/public/rebrand/js/pages/ops.js')
    .addEntry('rollbar-js-error', './src/AppBundle/Resources/public/rebrand/js/pages/rollbar-js-error.js')

    // Home Contents
    .addEntry('contents-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/contents-insurance.js')
    .addEntry('phone-insurance-homepage', './src/AppBundle/Resources/public/rebrand/js/pages/phone-insurance-homepage.js')
    .addEntry('contents-insurance-comparison', './src/AppBundle/Resources/public/rebrand/js/pages/contents-insurance-comparison.js')

    // Student
    .addEntry('students-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/students-insurance.js')

    // Checkout
    .addEntry('checkoutV2', './src/AppBundle/Resources/public/rebrand/js/common/checkoutV2.js')

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

    .splitEntryChunks()

    .configureImageRule({
        // tell Webpack it should consider inlining
        type: 'asset',
        //maxSize: 4 * 1024, // 4 kb - the default is 8kb
    })

    .configureFontRule({
        type: 'asset',
        // maxSize: 4 * 1024
    })

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

    .addPlugin(new BundleAnalyzerPlugin({
        analyzerMode: 'disabled'
    }))
;

var config = Encore.getWebpackConfig();

config.watchOptions = { poll: true, ignored: /node_modules/ };

config.module.rules.unshift({ parser: { amd: false }});

module.exports = config;
