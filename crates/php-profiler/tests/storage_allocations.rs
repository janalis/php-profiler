//! Real allocator denial after admission: adapter capture storage must not grow.
#[path = "../src/storage.rs"]
mod storage;
use profiler_core::Budget;
use std::{
    alloc::{GlobalAlloc, Layout, System},
    cell::Cell,
    fmt::Write,
};
use storage::{
    FixedPath, GeneratedNames, IDENTITY_CAPACITY, INPUT_PATH_BYTES, Identities, OUTPUT_PATH_BYTES,
    SESSION_CAPACITY, Slots, SymbolBuffer,
};
thread_local! {
    static DENY:Cell<bool>=const{Cell::new(false)};
    static ATTEMPTS:Cell<usize>=const{Cell::new(0)};
}
struct DeniedCaptureAllocator;
fn deny() -> bool {
    let denied = DENY.try_with(Cell::get).unwrap_or(false);
    if denied {
        let _ = ATTEMPTS.try_with(|value| value.set(value.get() + 1));
    }
    denied
}
// SAFETY: every successful operation forwards its original pointer/layout to
// System. Test denial returns null, the documented GlobalAlloc failure result.
unsafe impl GlobalAlloc for DeniedCaptureAllocator {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        if deny() {
            std::ptr::null_mut()
        } else {
            unsafe { System.alloc(layout) }
        }
    }
    unsafe fn alloc_zeroed(&self, layout: Layout) -> *mut u8 {
        if deny() {
            std::ptr::null_mut()
        } else {
            unsafe { System.alloc_zeroed(layout) }
        }
    }
    unsafe fn realloc(&self, pointer: *mut u8, layout: Layout, size: usize) -> *mut u8 {
        if deny() {
            std::ptr::null_mut()
        } else {
            unsafe { System.realloc(pointer, layout, size) }
        }
    }
    unsafe fn dealloc(&self, pointer: *mut u8, layout: Layout) {
        unsafe { System.dealloc(pointer, layout) }
    }
}
#[global_allocator]
static ALLOCATOR: DeniedCaptureAllocator = DeniedCaptureAllocator;
#[test]
fn capture_storage_keeps_working_when_every_further_allocation_is_denied() {
    let budget = Budget::new(64 * 1024 * 1024);
    let mut frames = Identities::default();
    frames.initialize(&budget).unwrap();
    let mut names = GeneratedNames::default();
    names.initialize(&budget).unwrap();
    let mut paths = Slots::<FixedPath<OUTPUT_PATH_BYTES>>::default();
    paths.initialize(SESSION_CAPACITY, &budget).unwrap();
    let input = FixedPath::<INPUT_PATH_BYTES>::copy(b"bounded-profile.json").unwrap();
    let admitted = budget.used();
    ATTEMPTS.set(0);
    DENY.set(true);
    for _ in 0..SESSION_CAPACITY {
        paths
            .push(FixedPath::copy(input.bytes()).unwrap())
            .ok()
            .unwrap();
    }
    for id in 0..IDENTITY_CAPACITY as u64 {
        assert!(frames.insert(id, id + 1));
        assert_eq!(
            names.identity(b"private closure site", id),
            Some(id as usize + 1)
        );
        let mut text = SymbolBuffer::new();
        text.identifier(b"Raw_\x80").unwrap();
        write!(text, "::{{closure#{}}}", id + 1).unwrap();
        assert!(text.as_str().starts_with("{bytes:5261775f80}::"));
    }
    assert!(!frames.insert(u64::MAX, 1));
    assert_eq!(names.identity(b"capacity", 0), None);
    for id in (0..IDENTITY_CAPACITY as u64).rev() {
        assert_eq!(frames.get(&id), Some(&(id + 1)));
        assert_eq!(frames.remove(&id), Some(id + 1));
        assert_eq!(
            names.identity(b"private closure site", id),
            Some(id as usize + 1)
        );
    }
    assert_eq!(budget.used(), admitted);
    DENY.set(false);
    assert_eq!(ATTEMPTS.get(), 0);
}
