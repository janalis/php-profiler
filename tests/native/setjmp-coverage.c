/* Standalone coverage-tool reproducer; not part of the extension. */
#include <assert.h>
#include <setjmp.h>
#include <stdio.h>
int main(void) {
    jmp_buf guard;
    volatile int observed = 0;
    if (setjmp(guard) == 0) {
        longjmp(guard, 1);
    } else {
        observed = 1;
    }
    assert(observed == 1);
    printf("catch executed: %d\n", observed);
    return 0;
}
