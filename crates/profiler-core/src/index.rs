//! Append-only, fallibly admitted lookup indexes for stable capture records.
use crate::{Budget, BudgetError, BudgetVec};
use std::{
    collections::hash_map::DefaultHasher,
    hash::{Hash, Hasher},
};

const EMPTY: usize = usize::MAX;
struct Slot {
    hash: u64,
    record: usize,
}
pub(crate) struct Index {
    slots: BudgetVec<Slot>,
}
/// DefaultHasher owns only stack state; unlike RandomState it does not obtain a
/// fresh random seed. Hashes are internal lookup hints, never profile identity.
pub(crate) fn fingerprint(value: &(impl Hash + ?Sized)) -> u64 {
    let mut hasher = DefaultHasher::new();
    value.hash(&mut hasher);
    hasher.finish()
}
impl Index {
    pub(crate) fn try_new(records: usize, budget: &Budget) -> Result<Self, BudgetError> {
        let capacity = if records == 0 {
            0
        } else {
            records
                .checked_mul(2)
                .and_then(usize::checked_next_power_of_two)
                .ok_or(BudgetError::Overflow)?
        };
        let mut slots = BudgetVec::try_with_capacity(capacity, budget)?;
        for _ in 0..capacity {
            slots
                .push(Slot {
                    hash: 0,
                    record: EMPTY,
                })
                .expect("index slots reserved before initialization");
        }
        Ok(Self { slots })
    }
    pub(crate) fn allocated_bytes(&self) -> usize {
        self.slots.allocated_bytes()
    }
    pub(crate) fn find(&self, hash: u64, equals: impl Fn(usize) -> bool) -> Option<usize> {
        if self.slots.is_empty() {
            return None;
        }
        let mask = self.slots.len() - 1;
        let mut bucket = hash as usize & mask;
        for _ in 0..self.slots.len() {
            let slot = &self.slots[bucket];
            if slot.record == EMPTY {
                return None;
            }
            if slot.hash == hash && equals(slot.record) {
                return Some(slot.record);
            }
            bucket = (bucket + 1) & mask;
        }
        None
    }
    /// The engine inserts each record once and admits at most half the slots.
    /// A bounded false result also makes malformed/full helper inputs terminate.
    pub(crate) fn insert(&mut self, hash: u64, record: usize) -> bool {
        if self.slots.is_empty() {
            return false;
        }
        let mask = self.slots.len() - 1;
        let mut bucket = hash as usize & mask;
        for _ in 0..self.slots.len() {
            if self.slots[bucket].record == EMPTY {
                self.slots[bucket] = Slot { hash, record };
                return true;
            }
            bucket = (bucket + 1) & mask;
        }
        false
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn one_client_predicate_handles_empty_partial_colliding_and_full_tables() {
        let budget = Budget::new(4096);
        // Reuse one callback type across all states, also making the complete
        // generic probe contract visible in LLVM's per-instantiation summary.
        let equals: fn(usize) -> bool = |record| record == 1;
        let empty = Index::try_new(0, &budget).unwrap();
        assert_eq!(empty.find(1, equals), None);
        let mut partial = Index::try_new(2, &budget).unwrap();
        assert!(partial.insert(1, 0));
        assert!(partial.insert(1, 1));
        assert_eq!(partial.find(1, equals), Some(1));
        assert_eq!(partial.find(2, equals), None);
        let mut full = Index::try_new(1, &budget).unwrap();
        assert!(full.insert(1, 0));
        assert!(full.insert(3, 1));
        assert_eq!(full.find(3, equals), Some(1));
        assert_eq!(full.find(1, equals), None);
    }
    #[test]
    fn admitted_tables_keep_power_of_two_half_load_and_exact_charges() {
        let budget = Budget::new(4 * 1024 * 1024);
        let control = Budget::control_bytes();
        assert_ne!(fingerprint("alpha"), fingerprint("beta"));
        for records in [1, 3, 4, 7, 8, 512, 8192] {
            let index = Index::try_new(records, &budget).unwrap();
            assert!(index.slots.len().is_power_of_two());
            assert!(index.slots.len() >= records * 2);
            assert_eq!(
                index.allocated_bytes(),
                index.slots.len() * std::mem::size_of::<Slot>()
            );
            assert_eq!(budget.used(), control + index.allocated_bytes());
            drop(index);
            assert_eq!(budget.used(), control);
        }
        assert!(matches!(
            Index::try_new(usize::MAX / 2, &budget),
            Err(BudgetError::Overflow)
        ));
    }
    #[test]
    fn real_bucket_collisions_and_equal_fingerprints_verify_full_keys() {
        let budget = Budget::new(4096);
        let mut index = Index::try_new(4, &budget).unwrap();
        let mut names = Vec::new();
        for number in 0..1000 {
            let name = format!("function_{number}");
            if fingerprint(&name) & 7 == 7 {
                names.push(name);
                if names.len() == 3 {
                    break;
                }
            }
        }
        assert_eq!(names.len(), 3);
        for (record, name) in names.iter().enumerate() {
            assert!(index.insert(fingerprint(name), record));
        }
        for (record, name) in names.iter().enumerate() {
            assert_eq!(
                index.find(fingerprint(name), |candidate| names[candidate] == *name),
                Some(record)
            );
        }
        assert_eq!(index.find(fingerprint("missing"), |_| false), None);
        // Force a full-fingerprint collision separately from the real bucket
        // collisions above. Equality must reject the first distinct record.
        assert!(index.insert(fingerprint(&names[0]), 3));
        assert_eq!(
            index.find(fingerprint(&names[0]), |record| record == 3),
            Some(3)
        );
        assert_eq!(index.find(fingerprint(&names[0]), |_| false), None);
    }
    #[test]
    fn empty_full_and_failed_index_admission_are_bounded_and_release_charges() {
        let budget = Budget::new(4096);
        let control = Budget::control_bytes();
        let mut empty = Index::try_new(0, &budget).unwrap();
        assert_eq!(empty.allocated_bytes(), 0);
        assert_eq!(empty.find(1, |_| true), None);
        assert!(!empty.insert(1, 0));
        assert!(matches!(
            Index::try_new(usize::MAX, &budget),
            Err(BudgetError::Overflow)
        ));
        let blocked = budget.try_reserve(budget.available()).unwrap();
        assert!(matches!(
            Index::try_new(1, &budget),
            Err(BudgetError::Limit)
        ));
        drop(blocked);
        let mut full = Index::try_new(1, &budget).unwrap();
        // Deliberately exceed the engine's half-load invariant to verify the
        // helper's bounded full-table behavior, including wraparound probes.
        assert!(full.insert(1, 0));
        assert!(full.insert(3, 1));
        assert!(!full.insert(5, 2));
        assert_eq!(full.find(3, |record| record == 1), Some(1));
        assert_eq!(full.find(9, |_| true), None);
        drop(full);
        drop(empty);
        assert_eq!(budget.used(), control);
    }
}
