//! Resource limits for processing untrusted PDF files.
//!
//! See `docs/security.md` for which of these limits are enforced reliably
//! inside the PHP process and which require an isolated worker.

use crate::errors::{PreflightError, Result};
use crate::value::Value;

#[derive(Debug, Clone, PartialEq)]
pub struct Limits {
    /// Maximum input size in bytes. Checked before the file is read.
    pub max_file_size: u64,
    /// Maximum number of entries in the cross-reference table.
    pub max_objects: usize,
    /// Maximum number of pages.
    pub max_pages: usize,
    /// Maximum decoded size of a single stream (content stream, form, XMP…).
    pub max_stream_size: usize,
    /// Maximum decoded bytes per document, summed over all streams the
    /// analysis decodes. Protects against many medium-sized bombs.
    pub max_total_decoded: u64,
    /// Maximum nesting depth of form XObjects, patterns and annotation
    /// appearances.
    pub max_nesting_depth: usize,
    /// Maximum number of content stream operators interpreted per page
    /// (including nested forms).
    pub max_operations_per_page: u64,
    /// Wall-clock budget for the content analysis of the whole document in
    /// milliseconds. `0` disables the check.
    pub max_analysis_time_ms: u64,
    /// Largest image (in pixels) whose pixels are checked for colour when
    /// classifying a page. Larger images make the classification `unknown`
    /// unless other content decides it. `0` disables pixel checks.
    pub max_color_check_pixels: u64,
    /// Maximum number of distinct image placements recorded per page.
    pub max_image_placements: usize,
}

impl Default for Limits {
    fn default() -> Self {
        Self {
            max_file_size: 1024 * 1024 * 1024,
            max_objects: 5_000_000,
            max_pages: 100_000,
            max_stream_size: 256 * 1024 * 1024,
            max_total_decoded: 2 * 1024 * 1024 * 1024,
            max_nesting_depth: 32,
            max_operations_per_page: 20_000_000,
            max_analysis_time_ms: 60_000,
            max_color_check_pixels: 25_000_000,
            max_image_placements: 100_000,
        }
    }
}

/// Options accepted by `PdfNative::open()`.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct OpenOptions {
    pub password: String,
    pub limits: Limits,
}

impl OpenOptions {
    /// Builds options from a string-keyed map. Unknown keys are rejected so
    /// that typos do not silently disable a limit.
    pub fn from_value(value: &Value) -> Result<Self> {
        let mut options = OpenOptions::default();
        let Value::Map(entries) = value else {
            return Err(PreflightError::InvalidArgument("options must be an array".into()));
        };

        for (key, v) in entries {
            let l = &mut options.limits;
            match key.as_str() {
                "password" => {
                    options.password = v
                        .as_str()
                        .ok_or_else(|| PreflightError::InvalidArgument("password must be a string".into()))?
                        .to_string();
                }
                "max_file_size" => l.max_file_size = positive(key, v)?,
                "max_objects" => l.max_objects = positive(key, v)? as usize,
                "max_pages" => l.max_pages = positive(key, v)? as usize,
                "max_stream_size" => l.max_stream_size = positive(key, v)? as usize,
                "max_total_decoded" => l.max_total_decoded = positive(key, v)?,
                "max_nesting_depth" => l.max_nesting_depth = positive(key, v)? as usize,
                "max_operations_per_page" => l.max_operations_per_page = positive(key, v)?,
                "max_analysis_time_ms" => l.max_analysis_time_ms = non_negative(key, v)?,
                "max_color_check_pixels" => l.max_color_check_pixels = non_negative(key, v)?,
                "max_image_placements" => l.max_image_placements = positive(key, v)? as usize,
                other => {
                    return Err(PreflightError::InvalidArgument(format!("unknown option '{other}'")));
                }
            }
        }

        Ok(options)
    }
}

impl Limits {
    pub fn to_value(&self) -> Value {
        Value::map()
            .with("max_file_size", self.max_file_size)
            .with("max_objects", self.max_objects)
            .with("max_pages", self.max_pages)
            .with("max_stream_size", self.max_stream_size)
            .with("max_total_decoded", self.max_total_decoded)
            .with("max_nesting_depth", self.max_nesting_depth)
            .with("max_operations_per_page", self.max_operations_per_page)
            .with("max_analysis_time_ms", self.max_analysis_time_ms)
            .with("max_color_check_pixels", self.max_color_check_pixels)
            .with("max_image_placements", self.max_image_placements)
    }
}

fn non_negative(key: &str, v: &Value) -> Result<u64> {
    match v {
        Value::Int(0) => Ok(0),
        _ => positive(key, v),
    }
}

fn positive(key: &str, v: &Value) -> Result<u64> {
    match v {
        Value::Int(i) if *i > 0 => Ok(*i as u64),
        _ => Err(PreflightError::InvalidArgument(format!("option '{key}' must be a positive integer"))),
    }
}
