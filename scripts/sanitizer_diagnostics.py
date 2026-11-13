"""Reject captured sanitizer failures even in intentionally nonzero PHP cases."""
import re

_ANSI_CSI = re.compile(r"\x1b\[[0-?]*[ -/]*[@-~]")
_PREFIX = r"^[ \t]*(?:==[0-9]+==[ \t]*)?"
_FAILURE = re.compile(
    _PREFIX + r"(?:ERROR|SUMMARY|WARNING):[ \t]*(?:AddressSanitizer|LeakSanitizer)\b"
    r"|" + _PREFIX + r"(?:AddressSanitizer|LeakSanitizer)[ \t]*:[ \t]*DEADLYSIGNAL\b"
    r"|" + _PREFIX + r"(?:AddressSanitizer|LeakSanitizer)[ \t]+CHECK failed\b"
    r"|" + _PREFIX + r"LeakSanitizer has encountered a fatal error\b"
    r"|" + _PREFIX + r"ASan runtime does not come first\b",
    re.IGNORECASE | re.MULTILINE,
)


def reject_sanitizer_diagnostics(output, context):
    """No environment switch or expected PHP fatal may hide a sanitizer report."""
    if _FAILURE.search(_ANSI_CSI.sub("", output)):
        raise RuntimeError(f"Sanitizer failure during {context}:\n{output}")
