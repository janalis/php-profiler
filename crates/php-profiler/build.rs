use std::process::Command;
/// Query the actual target C headers; linux-gnu alone also includes glibc < 2.32.
/// This controls private test transports only, never the runtime backend choice.
fn sampling_test_transports_supported(build: &cc::Build) -> bool {
    const PROBE: &[u8] = br#"
#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif
#if defined(__linux__)
#include <features.h>
#if defined(__GLIBC__)
#if __GLIBC_PREREQ(2, 32)
"profiler_sampling_test_supported"
#else
"profiler_sampling_test_unsupported"
#endif
#else
"profiler_sampling_test_unsupported"
#endif
#else
"profiler_sampling_test_unsupported"
#endif
"#;
    let mut compiler = build.get_compiler().to_command();
    compiler.args(["-E", "-P", "-x", "c", "-"]);
    let mut child = compiler
        .stdin(std::process::Stdio::piped())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .spawn()
        .expect("launch C compiler for sampling test platform probe");
    {
        use std::io::Write;
        let mut input = child.stdin.take().expect("sampling platform probe stdin");
        input
            .write_all(PROBE)
            .expect("write sampling platform probe");
    }
    let output = child
        .wait_with_output()
        .expect("wait for sampling platform probe");
    assert!(
        output.status.success(),
        "sampling test platform preprocessing failed: {}",
        String::from_utf8_lossy(&output.stderr)
    );
    let marker = String::from_utf8(output.stdout).expect("sampling platform probe output is UTF-8");
    match marker.trim() {
        "\"profiler_sampling_test_supported\"" => true,
        "\"profiler_sampling_test_unsupported\"" => false,
        other => panic!("unexpected sampling test platform probe output: {other}"),
    }
}

fn main() {
    println!("cargo:rustc-check-cfg=cfg(profiler_adapter_contract_tests)");
    println!("cargo:rerun-if-env-changed=PROFILER_ADAPTER_CONTRACT_TEST");
    println!("cargo:rerun-if-changed=../../tests/support/adapter-contract.rs");
    println!("cargo:rerun-if-changed=../../tests/support/sampling-adapter-contract.rs");
    if std::env::var_os("PROFILER_ADAPTER_CONTRACT_TEST").is_some() {
        assert_eq!(
            std::env::var("PROFILE").as_deref(),
            Ok("debug"),
            "adapter contract probes are forbidden in release builds"
        );
        println!("cargo:rustc-cfg=profiler_adapter_contract_tests");
    }
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("macos") {
        println!("cargo:rustc-link-arg=-Wl,-undefined,dynamic_lookup");
    }
    println!("cargo:rerun-if-changed=src/bridge.c");
    println!("cargo:rerun-if-changed=src/sampling.c");
    println!("cargo:rerun-if-changed=src/sampling.h");
    println!("cargo:rerun-if-env-changed=PHP_CONFIG");
    let config = std::env::var("PHP_CONFIG").unwrap_or_else(|_| "php-config".into());
    let output = Command::new(config)
        .arg("--includes")
        .output()
        .expect("php-config required");
    assert!(output.status.success(), "php-config --includes failed");
    let mut build = cc::Build::new();
    println!("cargo:rerun-if-env-changed=PROFILER_C_GCOV");
    if std::env::var_os("PROFILER_C_GCOV").is_some() {
        build.flag("--coverage");
    }
    println!("cargo:rerun-if-env-changed=PROFILER_C_COVERAGE");
    if std::env::var_os("PROFILER_C_COVERAGE").is_some() {
        build.define("PROFILER_LLVM_COVERAGE", None);
        build
            .flag("-fprofile-instr-generate")
            .flag("-fcoverage-mapping")
            .flag("-fprofile-update=atomic");
        // The Rust instrumented cdylib supplies LLVM's profile runtime. Use a
        // Clang matching rustc's LLVM major when collecting mixed-language data.
    }
    for flag in String::from_utf8(output.stdout)
        .expect("PHP include flags")
        .split_whitespace()
    {
        if let Some(path) = flag.strip_prefix("-I") {
            build.include(path);
        }
    }
    println!("cargo:rerun-if-env-changed=PROFILER_C_UNIT_BOUNDARIES");
    if std::env::var_os("PROFILER_C_UNIT_BOUNDARIES").is_some() {
        assert_eq!(
            std::env::var("PROFILE").as_deref(),
            Ok("debug"),
            "native unit boundaries are forbidden in release builds"
        );
        // Isolated native-test translation unit: never enabled in release builds.
        println!("cargo:rerun-if-changed=../../tests/native/bridge-unit.c");
        build.file("../../tests/native/bridge-unit.c");
        build.define("PROFILER_GUARD_UNIT_PRESENT", None);
        if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux") {
            println!("cargo:rustc-link-arg=-Wl,--wrap=profiler_fiber_cleanup");
            println!("cargo:rustc-link-arg=-Wl,--wrap=profiler_fiber_destroy");
        }
    } else {
        build.file("src/bridge.c");
    }
    println!("cargo:rerun-if-env-changed=PROFILER_SAMPLING_UNIT");
    if std::env::var_os("PROFILER_SAMPLING_UNIT").is_some() {
        assert_eq!(
            std::env::var("PROFILE").as_deref(),
            Ok("debug"),
            "sampling unit boundaries are forbidden in release builds"
        );
        println!("cargo:rerun-if-changed=../../tests/native/sampling-unit.c");
        build.file("../../tests/native/sampling-unit.c");
        build.file("../../tests/native/frames-native.c");
        println!("cargo:rerun-if-changed=../../tests/native/frames-native.c");
        if sampling_test_transports_supported(&build) {
            build.define("PROFILER_SAMPLING_TEST_TRANSPORTS", None);
            build.flag("-finstrument-functions");
            build.file("../../tests/native/sampling-lifecycle-native.c");
            println!("cargo:rerun-if-changed=../../tests/native/sampling-lifecycle-native.c");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_mutex_lock");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_mutex_unlock");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_attr_destroy");
            println!("cargo:rustc-link-arg=-Wl,--wrap=__sysconf");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_mutex_init");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_mutex_destroy");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_cond_init");
            println!("cargo:rustc-link-arg=-Wl,--wrap=profiler_sampling_observe");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_cond_destroy");
            println!("cargo:rustc-link-arg=-Wl,--wrap=profiler_sampling_closure_identity");
            println!("cargo:rustc-link-arg=-Wl,--wrap=ts_allocate_id");
            println!("cargo:rustc-link-arg=-Wl,--wrap=sched_yield");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_condattr_init");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_condattr_setclock");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_attr_init");
            println!("cargo:rustc-link-arg=-Wl,--wrap=sysconf");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_attr_setstacksize");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_attr_setguardsize");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_attr_setsigmask_np");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_create");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_join");
            println!("cargo:rustc-link-arg=-Wl,--wrap=clock_gettime");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_cond_timedwait");
            println!("cargo:rustc-link-arg=-Wl,--wrap=pthread_atfork");
        }
    } else {
        build.file("src/sampling.c");
    }
    build.flag_if_supported("-std=c11");
    build.compile("profiler_bridge");
    if std::env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("linux") {
        println!("cargo:rustc-link-lib=pthread");
    }
    if std::env::var_os("PROFILER_C_GCOV").is_some() {
        println!("cargo:rustc-link-lib=gcov");
    }
}
