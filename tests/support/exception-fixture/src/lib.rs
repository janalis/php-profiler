//! Test-only extension exercising the locally patched upstream exception paths.
mod allocation;
#[global_allocator]
static ALLOCATOR: allocation::Counted = allocation::Counted;
use ext_php_rs::{
    prelude::*,
    types::Zval,
    zend::{ExecutorGlobals, ce},
};
#[php_class]
#[php(name="ProfilerFixture\\AbstractException",extends(ce=ce::exception,stub="Exception"),flags=ext_php_rs::flags::ClassFlags::Abstract)]
pub struct AbstractException;
#[php_class]
#[php(name="ProfilerFixture\\Uncloneable")]
pub struct Uncloneable {
    _identity: i64,
}
#[php_function]
pub fn profiler_fixture_uncloneable() -> Uncloneable {
    Uncloneable { _identity: 1 }
}
#[php_function]
pub fn profiler_fixture_throw(kind: i64) -> PhpResult<()> {
    match kind {
        0 => Err(PhpException::from_message("ordinary exception".into())),
        1 => Err(PhpException::from_message("invalid\0message".into())),
        2 => {
            Err(PhpException::from_message("non-object attachment".into()).with_object(Zval::new()))
        }
        3 => {
            ext_php_rs::exception::throw(ce::exception(), "original pending exception")?;
            Err(PhpException::from_message(
                "replacement must not win".into(),
            ))
        }
        4 => {
            ext_php_rs::exception::throw(ce::exception(), "attached exception")?;
            let mut object =
                ExecutorGlobals::take_exception().expect("fixture just threw an exception");
            let mut value = Zval::new();
            value.set_object(&mut object);
            Err(PhpException::from_message("unused message".into()).with_object(value))
        }
        5 => Err(PhpException::from_class::<AbstractException>(
            "abstract exception".into(),
        )),
        6 => Err(PhpException::from_message("m".repeat(8 * 1024 * 1024))),
        7 => Err(PhpException::from_message("f".repeat(8 * 1024 * 1024)).with_object(Zval::new())),
        _ => Err(PhpException::from_message("unknown fixture case".into())),
    }
}
#[php_function]
pub fn profiler_fixture_live_bytes() -> i64 {
    allocation::live() as i64
}
#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<AbstractException>()
        .class::<Uncloneable>()
        .function(wrap_function!(profiler_fixture_uncloneable))
        .function(wrap_function!(profiler_fixture_throw))
        .function(wrap_function!(profiler_fixture_live_bytes))
}
