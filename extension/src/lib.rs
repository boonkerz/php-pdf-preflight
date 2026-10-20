//! Native PDF analysis for print preflight.
//!
//! The crate has two layers:
//!
//! * a pure Rust core (`Document` and the modules below) that parses and
//!   analyses PDF files without any shop-specific rules, and
//! * PHP bindings (`php` module, feature `php`) exposing the `PdfNative`
//!   class.
//!
//! The core can be tested and benchmarked without PHP:
//! `cargo test --no-default-features`.

pub mod colors;
pub mod content;
pub mod decode;
pub mod document;
pub mod errors;
pub mod fonts;
pub mod guard;
pub mod images;
pub mod limits;
pub mod pages;
pub mod text;
pub mod value;

#[cfg(feature = "php")]
mod php;

pub use document::Document;
pub use errors::{Issue, PreflightError};
pub use limits::{Limits, OpenOptions};
pub use value::Value;
