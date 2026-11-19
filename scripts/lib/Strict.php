<?php

declare(strict_types=1);

// Prepended to every tooling script by scripts/php-tool.sh. Python raised where PHP only
// warns, for example on a missing key or a failed read: such a diagnostic now throws, so no
// check can pass on the null it would otherwise yield. Diagnostics silenced with @ stay
// silent, and deprecations stay warnings, so a newer PHP cannot break the tooling.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0 || in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
