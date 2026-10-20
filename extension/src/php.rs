//! PHP bindings (ext-php-rs).
//!
//! Every method body runs inside `guard()`, which converts both errors and
//! panics into a `PdfNativeException`, so no Rust panic reaches the engine.

use std::panic::{AssertUnwindSafe, catch_unwind};

use ext_php_rs::binary_slice::BinarySlice;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::class::RegisteredClass;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::ce;

use crate::document::Document;
use crate::errors::PreflightError;
use crate::limits::{Limits, OpenOptions};
use crate::value::Value;

/// Thrown for all errors of the extension. `getCode()` returns one of the
/// `PdfNativeException::*` constants.
#[php_class]
#[php(name = "PdfNativeException")]
#[php(extends(ce = ce::exception, stub = "\\Exception"))]
#[derive(Default)]
pub struct PdfNativeException;

#[php_impl]
impl PdfNativeException {
    pub const IO: i32 = PreflightError::CODE_IO;
    pub const FILE_TOO_LARGE: i32 = PreflightError::CODE_FILE_TOO_LARGE;
    pub const INVALID_PDF: i32 = PreflightError::CODE_INVALID_PDF;
    pub const PASSWORD_REQUIRED: i32 = PreflightError::CODE_PASSWORD_REQUIRED;
    pub const UNSUPPORTED_ENCRYPTION: i32 = PreflightError::CODE_UNSUPPORTED_ENCRYPTION;
    pub const LIMIT_EXCEEDED: i32 = PreflightError::CODE_LIMIT_EXCEEDED;
    pub const PAGE_OUT_OF_RANGE: i32 = PreflightError::CODE_PAGE_OUT_OF_RANGE;
    pub const CLOSED: i32 = PreflightError::CODE_CLOSED;
    pub const INVALID_ARGUMENT: i32 = PreflightError::CODE_INVALID_ARGUMENT;
    pub const INTERNAL: i32 = PreflightError::CODE_INTERNAL;
}

fn to_exception(err: PreflightError) -> PhpException {
    PhpException::new(err.to_string(), err.code(), PdfNativeException::get_metadata().ce())
}

fn guard<T>(f: impl FnOnce() -> Result<T, PreflightError>) -> PhpResult<T> {
    match catch_unwind(AssertUnwindSafe(f)) {
        Ok(result) => result.map_err(to_exception),
        Err(payload) => {
            let msg = payload
                .downcast_ref::<&str>()
                .map(|s| (*s).to_string())
                .or_else(|| payload.downcast_ref::<String>().cloned())
                .unwrap_or_else(|| "unknown panic".to_string());
            Err(to_exception(PreflightError::Internal(msg)))
        }
    }
}

fn value_to_zval(value: Value) -> Result<Zval, PreflightError> {
    let mut zv = Zval::new();
    match value {
        Value::Null => zv.set_null(),
        Value::Bool(b) => zv.set_bool(b),
        Value::Int(i) => zv.set_long(i),
        Value::Float(f) => zv.set_double(f),
        Value::Str(s) => zv.set_string(&s, false).map_err(|e| PreflightError::Internal(e.to_string()))?,
        Value::List(_) | Value::Map(_) => zv.set_hashtable(value_to_array(value)?),
    }
    Ok(zv)
}

fn value_to_array(value: Value) -> Result<ZBox<ZendHashTable>, PreflightError> {
    let internal = |e: ext_php_rs::error::Error| PreflightError::Internal(e.to_string());
    match value {
        Value::List(items) => {
            let mut ht = ZendHashTable::with_capacity(u32::try_from(items.len()).unwrap_or(u32::MAX));
            for item in items {
                ht.push(value_to_zval(item)?).map_err(internal)?;
            }
            Ok(ht)
        }
        Value::Map(entries) => {
            let mut ht = ZendHashTable::with_capacity(u32::try_from(entries.len()).unwrap_or(u32::MAX));
            for (k, v) in entries {
                ht.insert(k.as_str(), value_to_zval(v)?).map_err(internal)?;
            }
            Ok(ht)
        }
        other => Err(PreflightError::Internal(format!("expected list or map, got {other:?}"))),
    }
}

fn options_from_array(options: Option<&ZendHashTable>) -> Result<OpenOptions, PreflightError> {
    let Some(ht) = options else { return Ok(OpenOptions::default()) };
    let mut entries = Vec::new();
    for (key, zv) in ht.iter() {
        let v = if let Some(i) = zv.long() {
            Value::Int(i)
        } else if let Some(s) = zv.str() {
            Value::Str(s.to_string())
        } else {
            return Err(PreflightError::InvalidArgument(format!("option '{key}' must be an int or string")));
        };
        entries.push((key.to_string(), v));
    }
    OpenOptions::from_value(&Value::Map(entries))
}

/// A parsed PDF document. Page numbers are 1-based.
#[php_class]
#[php(name = "PdfNative")]
pub struct PdfNative {
    doc: Option<Document>,
}

impl PdfNative {
    fn doc(&self) -> Result<&Document, PreflightError> {
        self.doc.as_ref().ok_or(PreflightError::Closed)
    }

    fn array(&self, f: impl FnOnce(&Document) -> Result<Value, PreflightError>) -> PhpResult<ZBox<ZendHashTable>> {
        guard(|| value_to_array(f(self.doc()?)?))
    }
}

#[php_impl]
impl PdfNative {
    pub const VERSION: &'static str = env!("CARGO_PKG_VERSION");

    /// Opens and parses a PDF file. See README for the supported options.
    pub fn open(path: String, options: Option<&ZendHashTable>) -> PhpResult<PdfNative> {
        guard(|| {
            let options = options_from_array(options)?;
            Ok(PdfNative { doc: Some(Document::open(&path, &options)?) })
        })
    }

    /// Parses a PDF from a binary string (e.g. an upload held in memory).
    pub fn from_string(data: BinarySlice<u8>, options: Option<&ZendHashTable>) -> PhpResult<PdfNative> {
        guard(|| {
            let options = options_from_array(options)?;
            Ok(PdfNative { doc: Some(Document::from_bytes(data.to_vec(), &options)?) })
        })
    }

    /// Default resource limits as an array.
    pub fn default_limits() -> PhpResult<ZBox<ZendHashTable>> {
        guard(|| value_to_array(Limits::default().to_value()))
    }

    /// Releases the parsed document and all cached analysis results.
    pub fn close(&mut self) {
        self.doc = None;
    }

    pub fn is_open(&self) -> bool {
        self.doc.is_some()
    }

    pub fn get_page_count(&self) -> PhpResult<i64> {
        guard(|| Ok(self.doc()?.page_count() as i64))
    }

    pub fn get_document_info(&self) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| Ok(d.document_info_value()))
    }

    pub fn get_page_info(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| d.page_info_value(page))
    }

    pub fn get_page_boxes(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| Ok(d.page_geometry(page)?.boxes_to_value()))
    }

    pub fn get_fonts(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| d.fonts_value(page))
    }

    /// Colour spaces (declared and used for painting), spot colours and the
    /// print classification (color, grayscale, unknown) of a page.
    pub fn get_color_spaces(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| d.colors_value(page))
    }

    /// Image placements of a page with pixel size, colour space, compression
    /// and effective resolution.
    pub fn get_images(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| d.images_value(page))
    }

    /// Content analysis issues and completeness of a page.
    pub fn get_page_analysis(&self, page: i64) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| {
            let content = d.page_content(page)?;
            Ok(Value::map()
                .with("page", page)
                .with("complete", content.complete)
                .with("operations", content.operations)
                .with("issues", Value::List(content.issues.iter().map(|i| i.to_value()).collect())))
        })
    }

    /// Analyses the whole document (structure, all pages, fonts).
    pub fn analyze(&self) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| Ok(d.analyze_value()))
    }

    pub fn get_limits(&self) -> PhpResult<ZBox<ZendHashTable>> {
        self.array(|d| Ok(d.limits().to_value()))
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<PdfNativeException>().class::<PdfNative>()
}
