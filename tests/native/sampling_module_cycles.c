#define _GNU_SOURCE
#include <Zend/zend_exceptions.h>
#include <pthread.h>
#include <sapi/embed/php_embed.h>
#include <stdint.h>
#include <stdio.h>
#include <sys/wait.h>
#include <unistd.h>
static const char *ini;
static int ordinary_fork(void) {
    pid_t child = fork();
    if (child < 0)
        return 1;
    if (!child)
        _exit(0);
    int status;
    return waitpid(child, &status, 0) != child || !WIFEXITED(status) || WEXITSTATUS(status);
}
static void *owner(void *unused) {
    (void)unused;
    char *args[] = {"module-cycle", NULL};
    for (int cycle = 0; cycle < 2; cycle++) {
        php_embed_module.php_ini_path_override = (char *)ini;
        php_embed_module.php_ini_ignore_cwd = 1;
        if (php_embed_init(1, args) != SUCCESS)
            return (void *)2;
        SG(headers_sent) = 1;
        SG(request_info).no_headers = 1;
        volatile int failed = 0;
        zend_first_try {
            if (zend_eval_string("$s=PhpProfiler\\Profiler::start(null,['sampling'=>true]);usleep("
                                 "30000);$s->stop();if($s->getId()!==1||$s->getReport()['status']!"
                                 "=='complete')throw new RuntimeException('cycle isolation');",
                                 NULL, "actual repeated module cycle") != SUCCESS ||
                EG(exception))
                failed = 1;
        }
        zend_catch { failed = 1; }
        zend_end_try();
        if (failed)
            return (void *)3;
        php_embed_shutdown();
        if (ordinary_fork())
            return (void *)4;
        printf("module cycle %d + retained-DSO fork passed\n", cycle + 1);
        fflush(stdout);
    }
    return NULL;
}
int main(int argc, char **argv) {
    if (argc != 2)
        return 2;
    ini = argv[1];
    pthread_t thread;
    if (pthread_create(&thread, NULL, owner, NULL))
        return 3;
    void *result;
    if (pthread_join(thread, &result))
        return 4;
    return (int)(intptr_t)result;
}
