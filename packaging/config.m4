PHP_ARG_ENABLE([php_profiler], [whether to enable php_profiler],
  [AS_HELP_STRING([--enable-php_profiler], [Enable the Rust PHP profiler])], [yes])
if test "$PHP_PHP_PROFILER" != "no"; then
  AC_PATH_PROG([CARGO], [cargo], [no])
  if test "$CARGO" = "no"; then
    AC_MSG_ERROR([Rust cargo is required])
  fi
  PHP_SUBST([CARGO])
  PHP_SUBST([PHP_CONFIG])
  PHP_ADD_MAKEFILE_FRAGMENT([$srcdir/Makefile.frag], [$srcdir], [$ac_pwd])
fi
