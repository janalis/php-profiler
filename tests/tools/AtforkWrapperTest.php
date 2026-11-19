<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/AtforkWrapper.php';

use PhpProfiler\Tooling\AtforkWrapper;

/** The atfork proof follows addresses and argument registers, not objdump aliases. */
return [
    'alias is accepted only at the local handle address' => static function (): void {
        $symbols = "00000000000d7058 d __dso_handle\n";
        $assembly = "00000000000d05c0 <__pthread_atfork>:\n d05c0: 48 8b mov 0x6a91(%rip),%rcx # d7058 <__TMC_END__>\n d05c7: e9 jmp d0980 <__register_atfork@plt>\n\n";
        AtforkWrapper::verify($symbols, $assembly);
        foreach ([
            str_replace('d7058', 'd7059', $assembly),
            str_replace('%rcx', '%rdx', $assembly),
            str_replace('__register_atfork', 'wrong', $assembly),
            str_replace('__pthread_atfork', 'wrong', $assembly),
        ] as $bad) {
            throws(RuntimeException::class, static fn() => AtforkWrapper::verify($symbols, $bad));
        }
        throws(RuntimeException::class, static fn() => AtforkWrapper::verify(
            str_replace(' d ', ' D ', $symbols),
            $assembly,
        ));
    },
];
