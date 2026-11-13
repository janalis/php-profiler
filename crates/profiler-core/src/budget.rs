//! Shared accounting for live native allocation capacities, not process RSS.
use std::{
    alloc::Layout,
    fmt,
    sync::atomic::{AtomicUsize, Ordering},
};
use triomphe::Arc;

pub const DEFAULT_BUDGET_BYTES: usize = 64 * 1024 * 1024;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum BudgetError {
    Limit,
    Overflow,
    Allocation,
}
impl fmt::Display for BudgetError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(match self {
            Self::Limit => "native profiler memory budget exhausted",
            Self::Overflow => "native profiler allocation size overflow",
            Self::Allocation => "native profiler allocation failed",
        })
    }
}
impl std::error::Error for BudgetError {}

fn allocation_error(_: triomphe::AllocError) -> BudgetError {
    BudgetError::Allocation
}

#[repr(C)]
struct Inner {
    limit: usize,
    used: AtomicUsize,
    peak: AtomicUsize,
}

#[derive(Clone)]
pub struct Budget {
    inner: Arc<Inner>,
}
impl Budget {
    /// Includes the pinned triomphe control allocation itself. triomphe 0.1.17
    /// allocates repr(C) { AtomicUsize, Inner }, padded to its alignment.
    pub fn control_bytes() -> usize {
        Layout::new::<AtomicUsize>()
            .extend(Layout::new::<Inner>())
            .expect("fixed budget control layout")
            .0
            .pad_to_align()
            .size()
    }
    pub fn try_new(limit: usize) -> Result<Self, BudgetError> {
        let control = Self::control_bytes();
        if limit < control {
            return Err(BudgetError::Limit);
        }
        let inner = Arc::try_new(Inner {
            limit,
            used: AtomicUsize::new(control),
            peak: AtomicUsize::new(control),
        })
        .map_err(allocation_error)?;
        Ok(Self { inner })
    }
    pub fn new(limit: usize) -> Self {
        Self::try_new(limit).expect("budget allocation failed; native callers must use try_new")
    }
    pub fn limit(&self) -> usize {
        self.inner.limit
    }
    pub fn used(&self) -> usize {
        self.inner.used.load(Ordering::Acquire)
    }
    pub fn peak(&self) -> usize {
        self.inner.peak.load(Ordering::Acquire)
    }
    pub fn available(&self) -> usize {
        self.limit() - self.used()
    }
    pub fn try_reserve(&self, bytes: usize) -> Result<Reservation, BudgetError> {
        // The standard atomic primitive retries competing updates. A failed
        // predicate leaves the counter unchanged; the returned value identifies
        // the exact rejected state, even if another thread subsequently releases.
        let previous = self
            .inner
            .used
            .try_update(Ordering::AcqRel, Ordering::Acquire, |current| {
                current
                    .checked_add(bytes)
                    .filter(|next| *next <= self.limit())
            })
            .map_err(|current| {
                if current.checked_add(bytes).is_none() {
                    BudgetError::Overflow
                } else {
                    BudgetError::Limit
                }
            })?;
        // Acceptance above proves this addition is representable.
        self.inner
            .peak
            .fetch_max(previous + bytes, Ordering::AcqRel);
        Ok(Reservation {
            budget: self.clone(),
            bytes,
        })
    }
}

/// Ownership follows the allocation; dropping the token returns its capacity.
pub struct Reservation {
    budget: Budget,
    bytes: usize,
}
impl Reservation {
    pub fn bytes(&self) -> usize {
        self.bytes
    }
}
impl Drop for Reservation {
    fn drop(&mut self) {
        self.budget
            .inner
            .used
            .fetch_sub(self.bytes, Ordering::AcqRel);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn exact_limit_release_peak_and_control_layout() {
        assert_eq!(DEFAULT_BUDGET_BYTES, 64 * 1024 * 1024);
        let control = Budget::control_bytes();
        let b = Budget::try_new(control + 10).unwrap();
        assert_eq!(
            b.inner.as_ptr().addr() - b.inner.heap_ptr().addr() + size_of::<Inner>(),
            control
        );
        assert_eq!(b.available(), 10);
        let r = b.try_reserve(10).unwrap();
        assert_eq!(r.bytes(), 10);
        assert_eq!(b.used(), b.limit());
        assert!(matches!(b.try_reserve(1), Err(BudgetError::Limit)));
        drop(r);
        assert_eq!(b.available(), 10);
        assert_eq!(b.peak(), control + 10);
        assert!(matches!(
            Budget::try_new(control - 1),
            Err(BudgetError::Limit)
        ));
        assert!(matches!(
            b.try_reserve(usize::MAX),
            Err(BudgetError::Overflow)
        ));
        assert_eq!(Budget::new(control).used(), control);
        let impossible = Arc::<[std::mem::MaybeUninit<u8>]>::try_new_uninit_slice(usize::MAX)
            .expect_err("an overflowing allocation layout must be refused");
        assert_eq!(allocation_error(impossible), BudgetError::Allocation);
        for (e, text) in [
            (
                BudgetError::Limit,
                "native profiler memory budget exhausted",
            ),
            (
                BudgetError::Overflow,
                "native profiler allocation size overflow",
            ),
            (BudgetError::Allocation, "native profiler allocation failed"),
        ] {
            assert_eq!(e.to_string(), text);
        }
    }
    #[test]
    fn concurrent_reservations_cannot_exceed_the_shared_limit() {
        let b = Budget::new(Budget::control_bytes() + 4);
        let barrier = std::sync::Arc::new(std::sync::Barrier::new(8));
        let threads: Vec<_> = (0..8)
            .map(|_| {
                let b = b.clone();
                let barrier = barrier.clone();
                std::thread::spawn(move || {
                    let reservation = b.try_reserve(1).ok();
                    barrier.wait();
                    assert!(b.used() <= b.limit());
                    reservation.is_some()
                })
            })
            .collect();
        assert_eq!(
            threads
                .into_iter()
                .map(|t| t.join().unwrap())
                .filter(|success| *success)
                .count(),
            4
        );
        assert_eq!(b.available(), 4);
    }
}
