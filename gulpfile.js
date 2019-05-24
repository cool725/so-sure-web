const gulp   = require('gulp'),
      concat = require('gulp-concat');

gulp.task('default', function() {
    gulp.src('node_modules/viewerjs/dist/viewer.min.*')
        .pipe(gulp.dest('web/components/viewerjs'));

    gulp.src('node_modules/jquery-viewer/dist/jquery-viewer.min.*')
        .pipe(gulp.dest('web/components/jquery-viewer'));

    gulp.src(['node_modules/jquery/dist/jquery.min.js', 'node_modules/popper.js/dist/umd/popper.min.js'] )
        .pipe(concat('vendor.js'))
        .pipe(gulp.dest('web/components'));

    gulp.src('node_modules/bootstrap/scss/**/**')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor/bs4'));

    // Cookies style > vendor
    gulp.src('node_modules/cookieconsent/build/cookieconsent.min.css')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // Cookies style > vendor
    gulp.src('node_modules/cookieconsent/src/styles/themes/classic.css')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // Animate.css > vendor
    gulp.src('node_modules/animate.css/animate.css')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // JSSocials
    gulp.src(['node_modules/jssocials/dist/jssocials.css', 'node_modules/jssocials/dist/jssocials-theme-flat.css'])
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // Fancybox.css
    gulp.src(['node_modules/@fancyapps/fancybox/dist/jquery.fancybox.css'])
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // tempusdominus-bootstrap-4
    gulp.src(['node_modules/tempusdominus-bootstrap-4/build/css/tempusdominus-bootstrap-4.css'])
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // Fancybox.css
    gulp.src(['node_modules/flickity/css/flickity.css'])
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    // DataTables.css
    gulp.src(['node_modules/datatables.net-dt/css/jquery.dataTables.css'])
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

});
