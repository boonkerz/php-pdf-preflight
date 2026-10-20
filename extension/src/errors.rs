//! Error and warning types shared by the analysis core and the PHP bindings.

use std::fmt;

use crate::value::Value;

/// Fatal errors. They abort the current operation and are mapped to a
/// `PdfNativeException` in PHP. The numeric codes are part of the public API.
#[derive(Debug, Clone, PartialEq)]
pub enum PreflightError {
    /// The file could not be read.
    Io(String),
    /// The input exceeds `max_file_size`.
    FileTooLarge { size: u64, limit: u64 },
    /// The data is not a PDF file or is damaged beyond recovery.
    InvalidPdf(String),
    /// The document is encrypted and the given password (or the empty
    /// default password) does not open it.
    PasswordRequired,
    /// The document uses an encryption scheme the parser cannot handle.
    UnsupportedEncryption(String),
    /// A configured resource limit was exceeded while opening the document.
    LimitExceeded { limit: &'static str, detail: String },
    /// A page number outside `1..=page_count` was requested.
    PageOutOfRange { page: i64, page_count: usize },
    /// The document has already been closed.
    Closed,
    /// An invalid option was passed.
    InvalidArgument(String),
    /// A bug in the extension (for example a caught panic).
    Internal(String),
}

impl PreflightError {
    pub const CODE_IO: i32 = 1;
    pub const CODE_FILE_TOO_LARGE: i32 = 2;
    pub const CODE_INVALID_PDF: i32 = 3;
    pub const CODE_PASSWORD_REQUIRED: i32 = 4;
    pub const CODE_UNSUPPORTED_ENCRYPTION: i32 = 5;
    pub const CODE_LIMIT_EXCEEDED: i32 = 6;
    pub const CODE_PAGE_OUT_OF_RANGE: i32 = 7;
    pub const CODE_CLOSED: i32 = 8;
    pub const CODE_INVALID_ARGUMENT: i32 = 9;
    pub const CODE_INTERNAL: i32 = 99;

    pub fn code(&self) -> i32 {
        match self {
            Self::Io(_) => Self::CODE_IO,
            Self::FileTooLarge { .. } => Self::CODE_FILE_TOO_LARGE,
            Self::InvalidPdf(_) => Self::CODE_INVALID_PDF,
            Self::PasswordRequired => Self::CODE_PASSWORD_REQUIRED,
            Self::UnsupportedEncryption(_) => Self::CODE_UNSUPPORTED_ENCRYPTION,
            Self::LimitExceeded { .. } => Self::CODE_LIMIT_EXCEEDED,
            Self::PageOutOfRange { .. } => Self::CODE_PAGE_OUT_OF_RANGE,
            Self::Closed => Self::CODE_CLOSED,
            Self::InvalidArgument(_) => Self::CODE_INVALID_ARGUMENT,
            Self::Internal(_) => Self::CODE_INTERNAL,
        }
    }
}

impl fmt::Display for PreflightError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Io(msg) => write!(f, "I/O error: {msg}"),
            Self::FileTooLarge { size, limit } => {
                write!(f, "file size {size} bytes exceeds the limit of {limit} bytes")
            }
            Self::InvalidPdf(msg) => write!(f, "invalid PDF: {msg}"),
            Self::PasswordRequired => write!(f, "the document is encrypted and requires a password"),
            Self::UnsupportedEncryption(msg) => write!(f, "unsupported encryption: {msg}"),
            Self::LimitExceeded { limit, detail } => write!(f, "limit '{limit}' exceeded: {detail}"),
            Self::PageOutOfRange { page, page_count } => {
                write!(f, "page {page} is out of range (document has {page_count} pages, numbering starts at 1)")
            }
            Self::Closed => write!(f, "the document has been closed"),
            Self::InvalidArgument(msg) => write!(f, "invalid argument: {msg}"),
            Self::Internal(msg) => write!(f, "internal error: {msg}"),
        }
    }
}

impl std::error::Error for PreflightError {}

pub type Result<T> = std::result::Result<T, PreflightError>;

/// Non-fatal findings about the file structure or about parts of a page that
/// could not be analysed. `code` is a stable machine-readable identifier.
#[derive(Debug, Clone, PartialEq)]
pub struct Issue {
    pub code: &'static str,
    pub message: String,
    pub page: Option<usize>,
}

impl Issue {
    pub fn new(code: &'static str, message: impl Into<String>) -> Self {
        Self { code, message: message.into(), page: None }
    }

    pub fn on_page(code: &'static str, page: usize, message: impl Into<String>) -> Self {
        Self { code, message: message.into(), page: Some(page) }
    }

    pub fn to_value(&self) -> Value {
        Value::map().with("code", self.code).with("message", self.message.as_str()).with("page", self.page)
    }
}
