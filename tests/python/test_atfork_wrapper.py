"""Atfork proof follows addresses and argument registers, not objdump aliases."""
import importlib.util
from pathlib import Path
import sys
import unittest
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
spec = importlib.util.spec_from_file_location('zts_sampling', ROOT / 'scripts/zts-sampling-tests.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class AtforkWrapperTests(unittest.TestCase):
    def test_alias_is_accepted_only_at_local_handle_address(self):
        symbols = '00000000000d7058 d __dso_handle\n'
        assembly = '00000000000d05c0 <__pthread_atfork>:\n d05c0: 48 8b mov 0x6a91(%rip),%rcx # d7058 <__TMC_END__>\n d05c7: e9 jmp d0980 <__register_atfork@plt>\n\n'
        module.verify_atfork_wrapper(symbols, assembly)
        for bad in [assembly.replace('d7058', 'd7059'), assembly.replace('%rcx', '%rdx'), assembly.replace('__register_atfork', 'wrong'), assembly.replace('__pthread_atfork', 'wrong')]:
            with self.assertRaises(RuntimeError):
                module.verify_atfork_wrapper(symbols, bad)
        with self.assertRaises(RuntimeError):
            module.verify_atfork_wrapper(symbols.replace(' d ', ' D '), assembly)
