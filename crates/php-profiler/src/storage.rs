//! Fallibly preallocated adapter storage. Capture never grows these vectors.
use profiler_core::{Budget, BudgetError, BudgetVec};
use std::{
    fmt,
    ops::{Deref, DerefMut},
};

pub const IDENTITY_CAPACITY: usize = 8192;
pub const SESSION_CAPACITY: usize = 16;
pub const SYMBOL_BYTES: usize = 1024;
pub const SYMBOL_BUFFER_BYTES: usize = 2064;
pub const INPUT_PATH_BYTES: usize = 4096;
pub const OUTPUT_PATH_BYTES: usize = 4352;

/// Keep request state allocation-free until capture is first requested.
pub struct Slots<T> {
    values: Option<BudgetVec<T>>,
}
impl<T> Default for Slots<T> {
    fn default() -> Self {
        Self { values: None }
    }
}
impl<T> Slots<T> {
    pub fn initialize(&mut self, limit: usize, budget: &Budget) -> Result<(), BudgetError> {
        if self.values.is_none() {
            self.values = Some(BudgetVec::try_with_capacity(limit, budget)?);
        }
        Ok(())
    }
    pub fn push(&mut self, value: T) -> Result<(), BudgetError> {
        self.values.as_mut().ok_or(BudgetError::Limit)?.push(value)
    }
    pub fn clear(&mut self) {
        if let Some(values) = &mut self.values {
            values.clear();
        }
    }
    pub fn retain(&mut self, keep: impl FnMut(&T) -> bool) {
        if let Some(values) = &mut self.values {
            values.retain(keep);
        }
    }
    pub fn remove(&mut self, index: usize) -> T {
        self.values
            .as_mut()
            .expect("slots initialized before removal")
            .swap_remove(index)
    }
}
impl<T> Deref for Slots<T> {
    type Target = [T];
    fn deref(&self) -> &[T] {
        self.values.as_deref().unwrap_or(&[])
    }
}
impl<T> DerefMut for Slots<T> {
    fn deref_mut(&mut self) -> &mut [T] {
        self.values.as_deref_mut().unwrap_or(&mut [])
    }
}
impl<'a, T> IntoIterator for &'a mut Slots<T> {
    type Item = &'a mut T;
    type IntoIter = std::slice::IterMut<'a, T>;
    fn into_iter(self) -> Self::IntoIter {
        self.deref_mut().iter_mut()
    }
}
#[derive(Default)]
pub struct Identities {
    entries: Slots<(u64, u64)>,
}
impl Identities {
    pub fn initialize(&mut self, budget: &Budget) -> Result<(), BudgetError> {
        self.entries.initialize(IDENTITY_CAPACITY, budget)
    }
    pub fn get(&self, key: &u64) -> Option<&u64> {
        self.entries
            .iter()
            .rev()
            .find(|entry| entry.0 == *key)
            .map(|entry| &entry.1)
    }
    pub fn insert(&mut self, key: u64, value: u64) -> bool {
        if let Some(entry) = self.entries.iter_mut().rev().find(|entry| entry.0 == key) {
            entry.1 = value;
            return true;
        }
        self.entries.push((key, value)).is_ok()
    }
    pub fn remove(&mut self, key: &u64) -> Option<u64> {
        let index = self.entries.iter().rposition(|entry| entry.0 == *key)?;
        Some(self.entries.remove(index).1)
    }
    pub fn len(&self) -> usize {
        self.entries.len()
    }
    pub fn clear(&mut self) {
        self.entries.clear();
    }
}
struct GeneratedName {
    bytes: [u8; SYMBOL_BYTES],
    length: usize,
    identity: u64,
}
#[derive(Default)]
pub struct GeneratedNames {
    entries: Slots<GeneratedName>,
}
impl GeneratedNames {
    pub fn initialize(&mut self, budget: &Budget) -> Result<(), BudgetError> {
        self.initialize_with_capacity(IDENTITY_CAPACITY, budget)
    }
    pub fn initialize_with_capacity(
        &mut self,
        limit: usize,
        budget: &Budget,
    ) -> Result<(), BudgetError> {
        self.entries.initialize(limit, budget)
    }
    pub fn len(&self) -> usize {
        self.entries.len()
    }
    pub fn clear(&mut self) {
        self.entries.clear();
    }
    pub fn identity(&mut self, bytes: &[u8], identity: u64) -> Option<usize> {
        if let Some(index) = self
            .entries
            .iter()
            .position(|entry| entry.identity == identity && &entry.bytes[..entry.length] == bytes)
        {
            return Some(index + 1);
        }
        if bytes.len() > SYMBOL_BYTES {
            return None;
        }
        let mut entry = GeneratedName {
            bytes: [0; SYMBOL_BYTES],
            length: bytes.len(),
            identity,
        };
        entry.bytes[..bytes.len()].copy_from_slice(bytes);
        self.entries.push(entry).ok()?;
        Some(self.len())
    }
}
/// All public symbol fragments are either validated UTF-8 or ASCII hex.
pub struct SymbolBuffer {
    bytes: [u8; SYMBOL_BUFFER_BYTES],
    length: usize,
}
impl SymbolBuffer {
    pub fn new() -> Self {
        Self {
            bytes: [0; SYMBOL_BUFFER_BYTES],
            length: 0,
        }
    }
    pub fn oversized() -> Self {
        let mut value = Self::new();
        value.bytes[..1025].fill(b'x');
        value.length = 1025;
        value
    }
    pub fn as_str(&self) -> &str {
        std::str::from_utf8(&self.bytes[..self.length])
            .expect("symbol buffer contains only UTF-8 fragments")
    }
    pub fn identifier(&mut self, bytes: &[u8]) -> fmt::Result {
        use fmt::Write;
        if let Ok(text) = std::str::from_utf8(bytes)
            && !text.starts_with("{bytes:")
            && !text.starts_with("anonymous-class#")
            && !text.starts_with("sample-anonymous-class#")
            && !text.starts_with("{sample-closure#")
        {
            return self.write_str(text);
        }
        self.write_str("{bytes:")?;
        const HEX: &[u8; 16] = b"0123456789abcdef";
        for byte in bytes {
            self.write_char(char::from(HEX[usize::from(byte >> 4)]))?;
            self.write_char(char::from(HEX[usize::from(byte & 15)]))?;
        }
        self.write_str("}")
    }
}
impl fmt::Write for SymbolBuffer {
    fn write_str(&mut self, text: &str) -> fmt::Result {
        if text.len() > self.bytes.len() - self.length {
            return Err(fmt::Error);
        }
        self.bytes[self.length..self.length + text.len()].copy_from_slice(text.as_bytes());
        self.length += text.len();
        Ok(())
    }
}
impl AsRef<str> for SymbolBuffer {
    fn as_ref(&self) -> &str {
        self.as_str()
    }
}
#[derive(Clone)]
pub struct FixedPath<const N: usize> {
    bytes: [u8; N],
    length: usize,
}
impl<const N: usize> FixedPath<N> {
    pub fn copy(bytes: &[u8]) -> Option<Self> {
        if bytes.len() > N || bytes.contains(&0) {
            return None;
        }
        let mut value = Self {
            bytes: [0; N],
            length: bytes.len(),
        };
        value.bytes[..bytes.len()].copy_from_slice(bytes);
        Some(value)
    }
    pub fn bytes(&self) -> &[u8] {
        &self.bytes[..self.length]
    }
    pub fn append(&mut self, bytes: &[u8]) -> bool {
        if bytes.len() > N - self.length || bytes.contains(&0) {
            return false;
        }
        self.bytes[self.length..self.length + bytes.len()].copy_from_slice(bytes);
        self.length += bytes.len();
        true
    }
    pub fn path(&self) -> &std::path::Path {
        use std::os::unix::ffi::OsStrExt;
        std::path::Path::new(std::ffi::OsStr::from_bytes(self.bytes()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fmt::Write;
    #[test]
    fn slots_charge_exact_capacity_and_preserve_admission_after_failure() {
        let control = Budget::control_bytes();
        let small = Budget::new(control + size_of::<u64>() - 1);
        let mut values = Slots::<u64>::default();
        let inspected = std::cell::Cell::new(0);
        let mut keep = |value: &u64| {
            inspected.set(inspected.get() + 1);
            *value == 20
        };
        values.retain(&mut keep);
        assert_eq!(inspected.get(), 0);
        assert!(matches!(
            values.initialize(1, &small),
            Err(BudgetError::Limit)
        ));
        assert_eq!(values.push(1), Err(BudgetError::Limit));
        assert_eq!(values.len(), 0);
        assert_eq!(small.used(), control);
        let budget = Budget::new(control + 2 * size_of::<u64>());
        values.initialize(2, &budget).unwrap();
        values.initialize(2, &budget).unwrap();
        assert_eq!(budget.used(), budget.limit());
        values.push(10).unwrap();
        values.push(20).unwrap();
        assert_eq!(values.push(30), Err(BudgetError::Limit));
        values.retain(&mut keep);
        assert_eq!(inspected.get(), 2);
        assert_eq!(&*values, &[20]);
        assert_eq!(values.remove(0), 20);
        values.push(40).unwrap();
        for value in &mut values {
            *value += 1;
        }
        assert_eq!(values[0], 41);
        values.clear();
        assert_eq!(values.len(), 0);
        drop(values);
        assert_eq!(budget.used(), control);
    }
    #[test]
    fn identity_and_generated_slots_remain_fixed_and_lossless() {
        let budget = Budget::new(64 * 1024 * 1024);
        let mut ids = Identities::default();
        assert!(!ids.insert(1, 2));
        assert_eq!(ids.remove(&1), None);
        ids.initialize(&budget).unwrap();
        let charged = budget.used();
        assert!(ids.insert(1, 2));
        assert!(ids.insert(1, 3));
        assert_eq!(ids.get(&1), Some(&3));
        assert_eq!(ids.len(), 1);
        assert_eq!(ids.remove(&1), Some(3));
        for id in 0..IDENTITY_CAPACITY as u64 {
            assert!(ids.insert(id, id + 1));
        }
        assert!(!ids.insert(u64::MAX, 0));
        assert_eq!(budget.used(), charged);
        ids.clear();
        let mut names = GeneratedNames::default();
        names.initialize(&budget).unwrap();
        let charged = budget.used();
        assert_eq!(names.identity(b"raw_\x80", 10), Some(1));
        assert_eq!(names.identity(b"raw_\x81", 10), Some(2));
        assert_eq!(names.identity(b"raw_\x80", 10), Some(1));
        assert_eq!(names.identity(b"raw_\x80", 11), Some(3));
        assert_eq!(names.identity(&[b'x'; SYMBOL_BYTES + 1], 0), None);
        for id in 3..IDENTITY_CAPACITY {
            assert_eq!(names.identity(b"site", id as u64), Some(id + 1));
        }
        assert_eq!(names.identity(b"overflow", 0), None);
        assert_eq!(budget.used(), charged);
        names.clear();
        assert_eq!(names.len(), 0);
    }
    #[test]
    fn generated_keys_accept_the_exact_bound_and_keep_logical_size() {
        let budget = Budget::new(64 * 1024 * 1024);
        let mut names = GeneratedNames::default();
        names.initialize(&budget).unwrap();
        let first = [b'a'; SYMBOL_BYTES];
        let second = [b'b'; SYMBOL_BYTES];
        assert_eq!(names.identity(&first, 17), Some(1));
        assert_eq!(names.len(), 1);
        assert_eq!(names.identity(&first, 17), Some(1));
        assert_eq!(names.len(), 1);
        assert_eq!(names.identity(&second, 17), Some(2));
        assert_eq!(names.len(), 2);
        assert_eq!(names.identity(&[b'x'; SYMBOL_BYTES + 1], 18), None);
        assert_eq!(names.len(), 2);
        assert_eq!(names.identity(&first, 17), Some(1));
        names.clear();
        assert_eq!(names.len(), 0);
    }
    #[test]
    fn stack_symbols_and_paths_preserve_bytes_and_capacity() {
        let mut symbol = SymbolBuffer::new();
        symbol.identifier(b"raw_\x80").unwrap();
        assert_eq!(symbol.as_str(), "{bytes:7261775f80}");
        let mut escaped = SymbolBuffer::new();
        escaped.identifier(b"{bytes:80}").unwrap();
        assert_ne!(symbol.as_str(), escaped.as_str());
        let mut ordinary = SymbolBuffer::new();
        ordinary.identifier(b"namespace\\Class::method").unwrap();
        assert_eq!(ordinary.as_str(), "namespace\\Class::method");
        assert_eq!(SymbolBuffer::oversized().as_str().len(), 1025);
        let full = "x".repeat(2064);
        let mut filled = SymbolBuffer::new();
        filled.write_str(&full).unwrap();
        assert!(filled.write_str("x").is_err());
        assert_eq!(filled.as_str(), full);
        assert!(FixedPath::<3>::copy(b"four").is_none());
        assert!(FixedPath::<3>::copy(b"a\0").is_none());
        let mut path = FixedPath::<3>::copy(b"a").unwrap();
        assert!(!path.append(b"\0"));
        assert!(path.append(b"bc"));
        assert!(!path.append(b"d"));
        assert_eq!(path.bytes(), b"abc");
        assert_eq!(path.path(), std::path::Path::new("abc"));
    }
}
