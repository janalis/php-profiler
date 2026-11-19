<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

require_once __DIR__ . '/Paths.php';

/** Fails closed on build failures that are not an explicit Rust compile rejection. */
final class MutationUnviable
{
    private const string ANSI = '/\x1b\[[0-?]*[ -\/]*[@-~]/';
    // Explicit source-language diagnostic families observed in real proposals.
    // New compiler errors require review; loader, linker and cache failures never qualify.
    private const array SOURCE_ERRORS = ['E0080', 'E0271', 'E0277', 'E0308', 'E0599', 'E0790'];

    /** @return array<string, mixed> */
    public static function classifyLog(string $text): array
    {
        // Ignore the printed mutation diff, which can itself contain string literals.
        if (!preg_match('/^\*\*\* .*(?:cargo|nextest).*$/m', $text, $command, PREG_OFFSET_CAPTURE)) {
            return ['classification' => 'infrastructure_or_unknown', 'reason' => 'Missing build command boundary'];
        }
        $rest = preg_replace(self::ANSI, '', substr($text, $command[0][1] + strlen($command[0][0])));
        preg_match_all('/^error(?:\[(E[0-9]{4})\])?: (.*)$/m', $rest, $diagnostics, PREG_SET_ORDER);
        $genuine = [];
        $unknown = [];
        foreach ($diagnostics as $diagnostic) {
            $code = $diagnostic[1];
            $message = $diagnostic[2];
            if (in_array($code, self::SOURCE_ERRORS, true)) {
                $genuine[] = $code;
            } elseif ($code !== '') {
                $unknown[] = $code . ': ' . $message;
            } elseif ($message === '`||` operators are not supported in let chain conditions') {
                $genuine[] = 'let_chain_syntax';
            } elseif (str_starts_with($message, 'could not compile `') && str_contains($message, 'due to ')) {
                continue;
            } elseif (str_starts_with($message, 'command `') && str_ends_with($message, 'exited with code 101')) {
                continue;
            } else {
                $unknown[] = $message;
            }
        }
        return [
            'classification' => $genuine !== [] && $unknown === [] ? 'compiler_rejected' : 'infrastructure_or_unknown',
            'diagnostics' => $genuine,
            'unrecognized_errors' => $unknown,
        ];
    }

    /** @return array<string, mixed> */
    public static function audit(array $outcomes, string $directory): array
    {
        $directory = Paths::resolve($directory);
        $records = [];
        foreach ($outcomes['outcomes'] as $outcome) {
            if ($outcome['summary'] !== 'Unviable') {
                continue;
            }
            $path = Paths::resolve($directory . '/' . $outcome['log_path']);
            if ($path !== $directory && !str_starts_with($path, $directory . '/')) {
                throw new InvalidArgumentException('Mutation log escapes its owned result directory');
            }
            $content = (string) file_get_contents($path);
            $result = self::classifyLog($content);
            $build = $outcome['phase_results'] ?? [];
            if (
                count($build) !== 1
                || ($build[0]['phase'] ?? null) !== 'Build'
                || ($build[0]['process_status'] ?? null) !== ['Failure' => 101]
            ) {
                $result['classification'] = 'infrastructure_or_unknown';
                $result['reason'] = 'Unviable outcome has missing or unexpected phases';
            }
            $records[] = [
                'scenario' => $outcome['scenario'],
                'log_path' => $outcome['log_path'],
                'sha256' => hash('sha256', $content),
                ...$result,
            ];
        }
        if (count($records) !== $outcomes['unviable']) {
            throw new InvalidArgumentException('Unviable outcome count disagrees with records');
        }
        $rejected = count(array_filter(
            $records,
            static fn(array $record): bool => $record['classification'] === 'compiler_rejected',
        ));
        return [
            'compiler_rejected' => $rejected,
            'infrastructure_or_unknown' => count($records) - $rejected,
            'records' => $records,
        ];
    }
}
