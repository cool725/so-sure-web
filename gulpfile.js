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

    gulp.src('node_modules/cookieconsent/build/cookieconsent.min.css')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));

    gulp.src('node_modules/cookieconsent/src/styles/themes/classic.css')
        .pipe(gulp.dest('src/AppBundle/Resources/public/rebrand/sass/vendor'));
});
