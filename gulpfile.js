var gulp = require('gulp');
var concat = require('gulp-concat');

gulp.task('default', function() {
    gulp.src('node_modules/viewerjs/dist/viewer.min.*')
        .pipe(gulp.dest('web/components/viewerjs'));

    gulp.src('node_modules/jquery-viewer/dist/jquery-viewer.min.*')
        .pipe(gulp.dest('web/components/jquery-viewer'));

    gulp.src(['node_modules/jquery/dist/jquery.min.js', 'node_modules/bootstrap/dist/js/bootstrap.min.js', 'node_modules/popper.js/dist/popper.min.js'] )
        .pipe(concat('vendor.js'))
        .pipe(gulp.dest('web/components'));
});
