<?php

/** Runtime controls are request-local. Reports contain no argument values. */
namespace PhpProfiler;

class StateException extends \Exception {}

class ConfigurationException extends \Exception {}

class PersistenceException extends \Exception {}

final class Profiler
{
    /** Options: timeline/sampling(bool, default false), max_events(int 1..8192, default 512). */
    public static function start(?string $path = null, ?array $options = null): Session {}

    public static function isEnabled(): bool {}

    /** Platform capability; resource admission may still fail. */
    public static function isSamplingSupported(): bool {}

    public static function enable(): void {}

    public static function disable(): void {}

    public static function stop(?Session $session = null): void {}

    public static function reset(?Session $session = null): void {}

    public static function resetAll(): void {}

    /** An omitted session sets the output default for future sessions. */
    public static function setOutputPath(?string $path = null, ?Session $session = null): void {}

    public static function getReport(?Session $session = null): array {}

    public static function getReportJson(?Session $session = null): string {}

    public static function getReportPath(?Session $session = null): ?string {}
}

final class Session
{
    /** @internal Direct construction throws StateException. */
    public function __construct() {}

    public function getId(): int {}

    public function stop(): void {}

    public function reset(): void {}

    public function setOutputPath(?string $path = null): void {}

    public function getReport(): array {}

    public function getReportJson(): string {}

    public function getReportPath(): ?string {}
}
