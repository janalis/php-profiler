<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

/** Coherent source mutants of the local exception-ownership patch in vendor/ext-php-rs. */
final class VendorMutations
{
    /** Removes one complete single-line ownership wrapper, preserving its value. */
    public static function withoutGuard(string $source, string $variable): string
    {
        $prefix = 'let ' . ($variable === 'exception' ? 'mut ' : '') . $variable . ' = BailoutGuard::new(';
        $matching = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $source),
            static fn(string $line): bool => str_contains($line, $prefix),
        ));
        if (count($matching) !== 1 || !str_ends_with($matching[0], ');')) {
            throw new InvalidArgumentException("expected exactly one single-line guard for {$variable}");
        }
        $old = $matching[0];
        $new = self::replaceOnce($old, $prefix, str_replace('BailoutGuard::new(', '', $prefix));
        // Remove only the final parenthesis of the guard, leaving nested calls intact.
        $new = substr($new, 0, -2) . ';';
        $changed = self::replaceOnce($source, $old, $new);
        if ($variable === 'err') {
            // Error does not Deref: preserve formatting semantics in this coherent mutant.
            if (substr_count($changed, '&*err') !== 1) {
                throw new InvalidArgumentException('expected one guarded Error formatting reference');
            }
            $changed = self::replaceOnce($changed, '&*err', '&err');
        }
        return $changed;
    }

    public static function withoutFallbackGuard(string $source): string
    {
        $start = 'let fallback = BailoutGuard::new(format!(';
        $end = '            let _ = throw_with_code(ce::error(), 0, &fallback);';
        if (substr_count($source, $start) !== 1 || substr_count($source, $end) !== 1) {
            throw new InvalidArgumentException('unexpected fallback formatting source');
        }
        $beginning = (int) strpos($source, $start);
        $ending = strpos($source, $end, $beginning);
        if ($ending === false) {
            throw new InvalidArgumentException('unexpected fallback formatting source');
        }
        $block = substr($source, $beginning, $ending - $beginning);
        $close = strrpos($block, '));');
        if ($close === false) {
            throw new InvalidArgumentException('missing fallback wrapper terminator');
        }
        $block = substr($block, 0, $close) . ');' . substr($block, $close + 3);
        $block = self::replaceOnce($block, $start, 'let fallback = format!(');
        return substr($source, 0, $beginning) . $block . substr($source, $ending);
    }

    public static function withoutAttachedObject(string $source): string
    {
        $original = 'match exception.object.take()';
        if (substr_count($source, $original) !== 1) {
            throw new InvalidArgumentException('expected one attached-object ownership transfer');
        }
        return self::replaceOnce($source, $original, 'match None');
    }

    public static function withOwnedCloneMessage(string $source): string
    {
        $match = strpos($source, 'match cloned_val');
        $start = $match === false ? false : strpos($source, '            Ok(None) => {', $match);
        $end = $start === false ? false : strpos($source, '            Err(panic) => {', $start);
        if ($start === false || $end === false) {
            throw new InvalidArgumentException('substring not found');
        }
        if (!str_contains(
            substr($source, $start, $end - $start),
            'c"Trying to clone an uncloneable object of class %s"',
        )) {
            throw new InvalidArgumentException('expected the borrowed uncloneable-handler message');
        }
        $restored = <<<'RUST'
                        Ok(None) => {
                            let msg = CString::new(format!(
                                "Trying to clone an uncloneable object of class {}",
                                T::CLASS_NAME
                            ))
                            .expect("Failed to create error message");
                            unsafe { zend_throw_error(ptr::null_mut(), msg.as_ptr()) };
                            Self::released_placeholder::<T>()
                        }

            RUST;
        return substr($source, 0, $start) . $restored . substr($source, $end);
    }

    private static function replaceOnce(string $subject, string $search, string $replacement): string
    {
        $position = strpos($subject, $search);
        return $position === false ? $subject : substr_replace($subject, $replacement, $position, strlen($search));
    }
}
