<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/VendorMutations.php';

use PhpProfiler\Tooling\VendorMutations;

/** Each mutant must apply to the real vendored sources exactly as the Python driver did. */
return [
    'every mutant applies once to the vendored sources' => static function (): void {
        $exception = (string) file_get_contents(ROOT . '/vendor/ext-php-rs/src/exception.rs');
        $handlers = (string) file_get_contents(ROOT . '/vendor/ext-php-rs/src/zend/handlers.rs');
        foreach (['exception', 'message'] as $variable) {
            $mutant = VendorMutations::withoutGuard($exception, $variable);
            same(
                1,
                substr_count($exception, 'BailoutGuard::new(') - substr_count($mutant, 'BailoutGuard::new('),
                $variable,
            );
        }
        same(false, VendorMutations::withoutFallbackGuard($exception) === $exception);
        same(1, substr_count(VendorMutations::withoutAttachedObject($exception), 'match None'));
        same(true, str_contains(
            VendorMutations::withOwnedCloneMessage($handlers),
            'Self::released_placeholder::<T>()',
        ));
    },
    'guards that are not single-line are refused' => static function (): void {
        throws(InvalidArgumentException::class, static fn() => VendorMutations::withoutGuard(
            "let message = BailoutGuard::new(\n    x,\n);\n",
            'message',
        ));
        throws(InvalidArgumentException::class, static fn() => VendorMutations::withoutGuard('', 'exception'));
    },
];
