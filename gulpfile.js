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
            // encoding: false を指定し、バイナリファイル（画像・フォント等）を
            // UTF-8テキストとして誤処理して破損させないようにする
            { base: './', encoding: false }
        )
        .pipe( gulp.dest( 'dist/bill-vektor', { encoding: false } ) ); // distディレクトリに出力
} );