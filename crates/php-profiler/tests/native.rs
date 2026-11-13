//! Rebuild the extension under test: mutation tests must never load a stale artifact.
use std::{path::PathBuf, process::Command};

#[test]
fn real_zend_runtime() {
    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../..");
    let cargo = std::env::var("CARGO").unwrap_or_else(|_| "cargo".into());
    let build = Command::new(&cargo)
        .args(["build", "-p", "php-profiler", "--lib"])
        // Private callback contracts require a real, initialized Zend host on
        // every platform. This opt-in affects only this debug test artifact.
        .env("PROFILER_ADAPTER_CONTRACT_TEST", "1")
        .env("PROFILER_SAMPLING_UNIT", "1")
        .current_dir(&root)
        .status()
        .expect("launch extension rebuild");
    assert!(build.success(), "mutated extension must build");
    let metadata = Command::new(&cargo)
        .args(["metadata", "--format-version", "1", "--no-deps"])
        .current_dir(&root)
        .output()
        .expect("locate rebuilt artifact");
    assert!(metadata.status.success());
    let metadata: serde_json::Value = serde_json::from_slice(&metadata.stdout).unwrap();
    let target = PathBuf::from(metadata["target_directory"].as_str().unwrap());
    let suffix = if cfg!(target_os = "macos") {
        "dylib"
    } else {
        "so"
    };
    let extension = target
        .join("debug")
        .join(format!("libphp_profiler.{suffix}"));
    let status = Command::new("bash")
        .env("PROFILER_EXTENSION", &extension)
        .arg("scripts/php-tests.sh")
        .current_dir(&root)
        .status()
        .expect("launch native PHP fixtures");
    assert!(status.success(), "native PHP fixtures must pass");
    let faults = Command::new("bash")
        .arg("scripts/native-fault-tests.sh")
        .env("PROFILER_EXTENSION", &extension)
        .current_dir(&root)
        .status()
        .expect("launch OS clock fault fixtures");
    assert!(faults.success(), "native clock fault fixtures must pass");
    let contracts = Command::new("python3")
        .arg("scripts/adapter-contract-tests.py")
        .env("PROFILER_CONTRACT_EXTENSION", &extension)
        .current_dir(&root)
        .status()
        .expect("launch real Zend private adapter contracts");
    assert!(
        contracts.success(),
        "native private adapter contracts must pass"
    );
    let sampling = Command::new("python3")
        .arg("scripts/sampling-controller-tests.py")
        .env("PROFILER_SAMPLING_EXTENSION", &extension)
        .current_dir(&root)
        .status()
        .expect("launch real sampling controller contracts");
    assert!(
        sampling.success(),
        "native sampling controller contracts must pass"
    );
}
