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
    .addEntry('global', './src/AppBundle/Resources/public/rebrand/js/global.js')
    .addEntry('cookie', './src/AppBundle/Resources/public/rebrand/js/cookie.js')
    .addEntry('dev', './src/AppBundle/Resources/public/rebrand/js/dev.js')
    .addEntry('error', './src/AppBundle/Resources/public/rebrand/js/pages/error.js')
    .addEntry('login', './src/AppBundle/Resources/public/rebrand/js/pages/login.js')
    .addEntry('homepage', './src/AppBundle/Resources/public/rebrand/js/pages/homepage.js')
    .addEntry('homepage-xmas', './src/AppBundle/Resources/public/rebrand/js/pages/homepage-xmas.js')
    .addEntry('quotepage', './src/AppBundle/Resources/public/rebrand/js/pages/quotepage.js')
    .addEntry('purchase', './src/AppBundle/Resources/public/rebrand/js/pages/purchase.js')
    .addEntry('purchase-personal', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-personal.js')
    .addEntry('purchase-phone', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-phone.js')
    .addEntry('purchase-pledge', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-pledge.js')
    .addEntry('purchase-payment', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-payment.js')
    .addEntry('purchase-bacs', './src/AppBundle/Resources/public/rebrand/js/pages/purchase-bacs.js')
    .addEntry('landing', './src/AppBundle/Resources/public/rebrand/js/pages/landing.js')
    .addEntry('faq', './src/AppBundle/Resources/public/rebrand/js/pages/faq.js')
    .addEntry('onboarding', './src/AppBundle/Resources/public/rebrand/js/pages/onboarding.js')
    .addEntry('social-insurance', './src/AppBundle/Resources/public/rebrand/js/pages/social-insurance.js')
    .addEntry('contact', './src/AppBundle/Resources/public/rebrand/js/pages/contact.js')

    // Admin files
    .addEntry('admin', './src/AppBundle/Resources/public/rebrand/js/pages/admin/admin.js')
    .addEntry('picsure', './src/AppBundle/Resources/public/rebrand/js/pages/admin/picsure.js')

    // Portal
    .addEntry('pos', './src/AppBundle/Resources/public/rebrand/js/pages/pos.js')

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
            doT: "dot/doT.js"
        })
    )
;

// export the final configuration
module.exports = Encore.getWebpackConfig();
