// webpack.config.js

var Encore = require('@symfony/webpack-encore');

Encore
    // the project directory where all compiled assets will be stored
    .setOutputPath('web/css-js/')

    // the public path used by the web server to access the previous directory
    .setPublicPath('/web')

    // this creates a 'vendor.js' file with jquery and the bootstrap JS module
    .createSharedEntry('vendor', [
        'jquery',
        'popper.js',
        'bootstrap'
    ])

    // List all files here
    .addEntry('global', './src/AppBundle/Resources/public/rebrand/js/global.js')
    .addEntry('homepage', './src/AppBundle/Resources/public/rebrand/js/pages/homepage.js')
    .addEntry('quotepage', './src/AppBundle/Resources/public/rebrand/js/pages/quotepage.js')

    // allow legacy applications to use $/jQuery as a global variable
    .autoProvidejQuery()

    // enable source maps during development
    .enableSourceMaps(!Encore.isProduction())

    // empty the outputPath dir before each build
    .cleanupOutputBeforeBuild()

    // show OS notifications when builds finish/fail
    .enableBuildNotifications()

    // create hashed filenames (e.g. app.abc123.css)
    // TODO: Get working in config!
    // .enableVersioning()

    // allow sass/scss files to be processed
    .enableSassLoader()
;

// export the final configuration
module.exports = Encore.getWebpackConfig();
