'use strict';

var gulp = require('gulp');

gulp.task('dist', function() {
    return gulp.src(
            [
                './**/*.png',
                './**/*.jpg',
                './**/*.gif',
                './**/*.php',
                './assets/**',
                './inc/**',
                './template-parts/**',
                './readme.md',
                './style.css',
                "!./tests/**",
                "!./dist/**",
                "!./node_modules/**/*.*"
            ],
            { base: './' }
        )
        .pipe( gulp.dest( 'dist/bill-vektor' ) ); // distディレクトリに出力
} );