<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

/**
 * Command-line parsing like argparse for the shapes the tools use: positionals, and
 * "--name value" or "--name=value" options anywhere. getopt() is unsuitable because it
 * stops at the first positional argument, silently ignoring every later option.
 */
final class Arguments
{
    /**
     * @param list<string> $arguments the script's argv, including argv[0]
     * @param list<string> $valued option names taking a value; repeated ones become lists
     * @param list<string> $flags option names taking no value
     * @return array{options: array<string, string|list<string>|true>, positionals: list<string>}
     */
    public static function parse(array $arguments, array $valued, array $flags = [], string $usage = ''): array
    {
        $options = [];
        $positionals = [];
        $count = count($arguments);
        for ($index = 1; $index < $count; $index++) {
            $argument = $arguments[$index];
            if ($argument === '--') {
                array_push($positionals, ...array_slice($arguments, $index + 1));
                break;
            }
            if (!str_starts_with($argument, '--') || $argument === '-') {
                $positionals[] = $argument;
                continue;
            }
            [$name, $value] = str_contains($argument, '=')
                ? explode('=', substr($argument, 2), 2)
                : [substr($argument, 2), null];
            if (in_array($name, $flags, true) && $value === null) {
                $options[$name] = true;
                continue;
            }
            if (!in_array($name, $valued, true)) {
                self::fail("unrecognized argument: {$argument}", $usage);
            }
            $value ??= ($index + 1) < $count ? $arguments[++$index] : self::fail("--{$name} expects a value", $usage);
            $options[$name] = isset($options[$name]) ? [...((array) $options[$name]), $value] : $value;
        }
        return ['options' => $options, 'positionals' => $positionals];
    }

    public static function fail(string $message, string $usage = ''): never
    {
        fwrite(STDERR, ($usage === '' ? '' : "usage: {$usage}\n") . "error: {$message}\n");
        exit(2);
    }
}
