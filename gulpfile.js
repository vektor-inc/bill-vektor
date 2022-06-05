'use strict';

var gulp = require('gulp');
var runSequence = require('run-sequence'); // 同期的に処理してくれる

gulp.task('copy', function() {
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

gulp.task('build:dist',function(){
    /* ここで、CSS とか JS をコンパイルする */
});

gulp.task('dist', function(cb){
    // return runSequence( 'build:dist', 'copy', cb );
    return runSequence( 'build:dist', 'copy', cb );
});
