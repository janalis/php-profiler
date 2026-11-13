use std::{env, path::PathBuf, process::Command};

fn main() {
    for variable in [
        "PROFILER_TEST_CLONE_BAILOUT",
        "PROFILER_TEST_EXCEPTION_BAILOUT",
        "PHP_CONFIG",
        "CC",
    ] {
        println!("cargo:rerun-if-env-changed={variable}");
    }
    if env::var("CARGO_CFG_TARGET_OS").as_deref() == Ok("macos") {
        println!("cargo:rustc-link-arg=-Wl,-undefined,dynamic_lookup");
    } else if env::var("PROFILER_TEST_CLONE_BAILOUT").as_deref() == Ok("1")
        || env::var("PROFILER_TEST_EXCEPTION_BAILOUT").as_deref() == Ok("1")
    {
        let (fixture, symbol) = if env::var("PROFILER_TEST_EXCEPTION_BAILOUT").as_deref() == Ok("1")
        {
            ("exception-bailout", "zend_throw_exception")
        } else {
            ("clone-bailout", "zend_throw_error")
        };
        // Test-only GNU linker wrapping affects this auxiliary module, never PHP
        // itself or the production extension. No runtime fault switch is added.
        let source = PathBuf::from(env::var_os("CARGO_MANIFEST_DIR").unwrap())
            .join(format!("../../native/{fixture}.c"));
        println!("cargo:rerun-if-changed={}", source.display());
        let config = env::var_os("PHP_CONFIG").unwrap_or_else(|| "php-config".into());
        let includes = Command::new(config).arg("--includes").output().unwrap();
        assert!(includes.status.success(), "PHP include lookup failed");
        let object = PathBuf::from(env::var_os("OUT_DIR").unwrap()).join(format!("{fixture}.o"));
        let compiler = env::var_os("CC").unwrap_or_else(|| "cc".into());
        let status = Command::new(compiler)
            .args(
                String::from_utf8(includes.stdout)
                    .unwrap()
                    .split_whitespace(),
            )
            .args(["-fPIC", "-c"])
            .arg(source)
            .arg("-o")
            .arg(&object)
            .status()
            .unwrap();
        assert!(status.success(), "bailout wrapper compilation failed");
        println!("cargo:rustc-link-arg={}", object.display());
        println!("cargo:rustc-link-arg=-Wl,--wrap={symbol}");
    }
}
