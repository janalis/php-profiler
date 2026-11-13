//! Fallible requested-capacity ownership for report buffers and text.
use crate::{Budget, BudgetError, Reservation};
use serde::{
    ser::{SerializeMap, SerializeSeq},
    Serialize, Serializer,
};
use std::{
    fmt,
    ops::{Deref, DerefMut, Index},
};

/// Borrow static report vocabulary; charge every copied dynamic string.
pub struct BudgetText {
    value: TextStorage,
}
enum TextStorage {
    Static(&'static str),
    Owned {
        value: String,
        _reservation: Reservation,
    },
}
impl BudgetText {
    pub fn as_str(&self) -> &str {
        self
    }
    pub fn try_copy(text: &str, budget: &Budget) -> Result<Self, BudgetError> {
        let mut bytes = BudgetVec::try_with_capacity(text.len(), budget)?;
        bytes.values.extend_from_slice(text.as_bytes());
        let BudgetVec {
            values,
            reservation,
            ..
        } = bytes;
        Ok(Self {
            value: TextStorage::Owned {
                value: String::from_utf8(values).expect("copied valid UTF-8"),
                _reservation: reservation,
            },
        })
    }
}
impl From<&'static str> for BudgetText {
    fn from(value: &'static str) -> Self {
        Self {
            value: TextStorage::Static(value),
        }
    }
}
impl From<bool> for BudgetText {
    fn from(value: bool) -> Self {
        Self::from(if value { "true" } else { "false" })
    }
}
impl Deref for BudgetText {
    type Target = str;
    fn deref(&self) -> &str {
        match &self.value {
            TextStorage::Static(value) => value,
            TextStorage::Owned { value, .. } => value,
        }
    }
}
impl fmt::Debug for BudgetText {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        fmt::Debug::fmt(&**self, f)
    }
}
impl fmt::Display for BudgetText {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self)
    }
}
impl Serialize for BudgetText {
    fn serialize<S: Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        s.serialize_str(self)
    }
}
impl PartialEq<str> for BudgetText {
    fn eq(&self, other: &str) -> bool {
        &**self == other
    }
}
impl PartialEq<&str> for BudgetText {
    fn eq(&self, other: &&str) -> bool {
        &**self == *other
    }
}
impl PartialEq for BudgetText {
    fn eq(&self, other: &Self) -> bool {
        **self == **other
    }
}
impl Eq for BudgetText {}
impl PartialOrd for BudgetText {
    fn partial_cmp(&self, other: &Self) -> Option<std::cmp::Ordering> {
        Some(self.cmp(other))
    }
}
impl Ord for BudgetText {
    fn cmp(&self, other: &Self) -> std::cmp::Ordering {
        (**self).cmp(&**other)
    }
}

/// Capacity is fixed at admission. Push failure leaves both values and accounting intact.
pub struct BudgetVec<T> {
    values: Vec<T>,
    reservation: Reservation,
    limit: usize,
}
impl<T> BudgetVec<T> {
    pub fn try_with_capacity(capacity: usize, budget: &Budget) -> Result<Self, BudgetError> {
        let bytes = capacity
            .checked_mul(std::mem::size_of::<T>())
            .ok_or(BudgetError::Overflow)?;
        let reservation = budget.try_reserve(bytes)?;
        let mut values = Vec::new();
        values
            .try_reserve_exact(capacity)
            .map_err(|_| BudgetError::Allocation)?;
        // With std's Global allocator, an initial try_reserve_exact requests
        // precisely this layout. Allocator bookkeeping/slack is not owned Vec
        // capacity; see the pinned standard-library proof in the budget docs.
        Ok(Self {
            values,
            reservation,
            limit: capacity,
        })
    }
    pub(crate) fn into_parts(self) -> (Vec<T>, Reservation) {
        (self.values, self.reservation)
    }
    pub fn push(&mut self, value: T) -> Result<(), BudgetError> {
        if self.values.len() == self.limit {
            return Err(BudgetError::Limit);
        }
        self.values.push(value);
        Ok(())
    }
    pub fn pop(&mut self) -> Option<T> {
        self.values.pop()
    }
    pub fn swap_remove(&mut self, index: usize) -> T {
        self.values.swap_remove(index)
    }
    pub fn retain(&mut self, keep: impl FnMut(&T) -> bool) {
        self.values.retain(keep);
    }
    pub fn clear(&mut self) {
        self.values.clear();
    }
    pub fn allocated_bytes(&self) -> usize {
        self.reservation.bytes()
    }
    pub fn capacity(&self) -> usize {
        self.limit
    }
}
impl BudgetVec<u8> {
    pub fn extend_bytes(&mut self, bytes: &[u8]) -> Result<(), BudgetError> {
        if bytes.len() > self.limit.saturating_sub(self.values.len()) {
            return Err(BudgetError::Limit);
        }
        self.values.extend_from_slice(bytes);
        Ok(())
    }
}
impl<T> Deref for BudgetVec<T> {
    type Target = [T];
    fn deref(&self) -> &[T] {
        &self.values
    }
}
impl<T> DerefMut for BudgetVec<T> {
    fn deref_mut(&mut self) -> &mut [T] {
        &mut self.values
    }
}
impl<T: fmt::Debug> fmt::Debug for BudgetVec<T> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        self.values.fmt(f)
    }
}
impl<T: Serialize> Serialize for BudgetVec<T> {
    fn serialize<S: Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        let mut seq = s.serialize_seq(Some(self.len()))?;
        for value in &self.values {
            seq.serialize_element(value)?;
        }
        seq.end()
    }
}
impl<'a, T> IntoIterator for &'a BudgetVec<T> {
    type Item = &'a T;
    type IntoIter = std::slice::Iter<'a, T>;
    fn into_iter(self) -> Self::IntoIter {
        self.values.iter()
    }
}
impl<'a, T> IntoIterator for &'a mut BudgetVec<T> {
    type Item = &'a mut T;
    type IntoIter = std::slice::IterMut<'a, T>;
    fn into_iter(self) -> Self::IntoIter {
        self.values.iter_mut()
    }
}

/// Sorted vector map: stable fallible allocation with the same JSON object schema.
pub struct BudgetMap<V> {
    entries: BudgetVec<(BudgetText, V)>,
}
impl<V> BudgetMap<V> {
    pub fn try_with_capacity(capacity: usize, budget: &Budget) -> Result<Self, BudgetError> {
        Ok(Self {
            entries: BudgetVec::try_with_capacity(capacity, budget)?,
        })
    }
    pub fn insert(&mut self, key: BudgetText, value: V) -> Result<Option<V>, BudgetError> {
        match self
            .entries
            .values
            .binary_search_by(|(name, _)| (**name).cmp(&key))
        {
            Ok(index) => Ok(Some(std::mem::replace(
                &mut self.entries.values[index].1,
                value,
            ))),
            Err(index) => {
                if self.entries.len() == self.entries.capacity() {
                    return Err(BudgetError::Limit);
                }
                self.entries.values.insert(index, (key, value));
                Ok(None)
            }
        }
    }
    pub fn get(&self, key: &str) -> Option<&V> {
        self.entries
            .values
            .binary_search_by(|(name, _)| (**name).cmp(key))
            .ok()
            .map(|index| &self.entries[index].1)
    }
    pub fn len(&self) -> usize {
        self.entries.len()
    }
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }
    pub fn iter(&self) -> impl Iterator<Item = (&BudgetText, &V)> {
        self.entries.iter().map(|(key, value)| (key, value))
    }
    pub fn values(&self) -> impl Iterator<Item = &V> {
        self.entries.iter().map(|(_, value)| value)
    }
    pub fn keys(&self) -> impl Iterator<Item = &BudgetText> {
        self.entries.iter().map(|(key, _)| key)
    }
}
impl<V> Index<&str> for BudgetMap<V> {
    type Output = V;
    fn index(&self, key: &str) -> &V {
        self.get(key).expect("missing report key")
    }
}
impl<V: fmt::Debug> fmt::Debug for BudgetMap<V> {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_map().entries(self.iter()).finish()
    }
}
impl<V: Serialize> Serialize for BudgetMap<V> {
    fn serialize<S: Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        let mut map = s.serialize_map(Some(self.len()))?;
        for (key, value) in self.iter() {
            map.serialize_entry(key, value)?;
        }
        map.end()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn boolean_metadata_uses_static_canonical_strings() {
        let budget = Budget::new(4096);
        let before = budget.used();
        assert_eq!(BudgetText::from(true), "true");
        assert_eq!(BudgetText::from(false), "false");
        assert_eq!(budget.used(), before);
    }
    #[test]
    fn zero_sized_slots_and_byte_extension_respect_logical_limits() {
        let budget = Budget::new(4096);
        let mut empty = BudgetVec::<()>::try_with_capacity(2, &budget).unwrap();
        assert_eq!(empty.capacity(), 2);
        assert_eq!(empty.allocated_bytes(), 0);
        empty.push(()).unwrap();
        empty.push(()).unwrap();
        assert_eq!(empty.push(()), Err(BudgetError::Limit));
        let mut bytes = BudgetVec::try_with_capacity(3, &budget).unwrap();
        bytes.extend_bytes(b"ab").unwrap();
        assert_eq!(bytes.extend_bytes(b"cd"), Err(BudgetError::Limit));
        assert_eq!(&*bytes, b"ab");
        bytes.extend_bytes(b"c").unwrap();
        assert_eq!(&*bytes, b"abc");
        bytes.extend_bytes(b"").unwrap();
    }
    #[test]
    fn bounded_vector_admission_push_and_release_are_exact() {
        let budget = Budget::try_new(Budget::control_bytes() + 16).unwrap();
        let mut values = BudgetVec::<u64>::try_with_capacity(2, &budget).unwrap();
        assert_eq!(values.allocated_bytes(), 16);
        assert_eq!(values.capacity(), 2);
        values.push(10).unwrap();
        values.push(20).unwrap();
        assert_eq!(values.push(30), Err(BudgetError::Limit));
        assert_eq!(&*values, [10, 20]);
        assert_eq!(serde_json::to_string(&values).unwrap(), "[10,20]");
        for value in &mut values {
            *value += 1;
        }
        assert_eq!(format!("{values:?}"), "[11, 21]");
        assert_eq!(values.swap_remove(0), 11);
        values.retain(|value| *value == 21);
        assert_eq!(&*values, [21]);
        values.retain(|_| false);
        assert!(values.is_empty());
        values.clear();
        assert!(values.is_empty());
        assert_eq!(values.pop(), None);
        assert!(matches!(
            BudgetVec::<u64>::try_with_capacity(usize::MAX, &budget),
            Err(BudgetError::Overflow)
        ));
        assert!(matches!(
            BudgetVec::<u8>::try_with_capacity(1, &budget),
            Err(BudgetError::Limit)
        ));
        drop(values);
        assert_eq!(budget.used(), Budget::control_bytes());
    }
    #[test]
    fn copied_text_and_sorted_maps_preserve_schema_without_unaccounted_growth() {
        let budget = Budget::try_new(4096).unwrap();
        let control = Budget::control_bytes();
        let static_text = BudgetText::from("static");
        assert_eq!(budget.used(), control);
        assert_eq!(static_text.as_str(), "static");
        let text = BudgetText::try_copy("é\0x", &budget).unwrap();
        assert_eq!(budget.used(), control + 4);
        assert_eq!(text, "é\0x");
        assert!(<BudgetText as PartialEq<str>>::eq(&text, "é\0x"));
        assert!(!<BudgetText as PartialEq<str>>::eq(&text, "different"));
        assert_eq!(text, "é\0x");
        assert_eq!(format!("{text}"), "é\0x");
        assert_eq!(format!("{static_text:?}"), "\"static\"");
        let mut map = BudgetMap::try_with_capacity(2, &budget).unwrap();
        assert!(map.is_empty());
        assert_eq!(map.get("missing"), None);
        assert_eq!(map.insert("z".into(), 2).unwrap(), None);
        assert_eq!(map.insert("a".into(), 1).unwrap(), None);
        assert!(!map.is_empty());
        assert_eq!(map.insert("a".into(), 3).unwrap(), Some(1));
        assert_eq!(map.insert("extra".into(), 4), Err(BudgetError::Limit));
        assert_eq!(map["z"], 2);
        assert_eq!(
            map.keys().map(BudgetText::as_str).collect::<Vec<_>>(),
            ["a", "z"]
        );
        assert_eq!(map.values().copied().collect::<Vec<_>>(), [3, 2]);
        assert_eq!(serde_json::to_string(&map).unwrap(), "{\"a\":3,\"z\":2}");
        assert_eq!(format!("{map:?}"), "{\"a\": 3, \"z\": 2}");
        let first = BudgetText::from("a");
        let last = BudgetText::from("z");
        assert!(first < last);
        assert_eq!(BudgetText::from("a"), BudgetText::from("a"));
        assert_ne!(BudgetText::from("a"), BudgetText::from("b"));
        drop(map);
        drop(text);
        assert_eq!(budget.used(), control);
    }
}
