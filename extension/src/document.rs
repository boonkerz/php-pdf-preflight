//! An opened PDF document and its structural information.
//!
//! The file is parsed exactly once in `Document::open`. Page geometry is cheap
//! and computed on request; the content analysis of each page (fonts) is
//! computed lazily on first request and cached.

use std::cell::{Cell, OnceCell, RefCell};
use std::collections::{BTreeMap, HashMap};
use std::rc::Rc;
use std::time::{Duration, Instant};

use hayro_syntax::object::{Array, Dict, Name, Object, Stream};
use hayro_syntax::reader::{Reader, ReaderExt};
use hayro_syntax::{DecryptionError, LoadPdfError, Pdf, PdfVersion};
use memchr::memmem;

use crate::content::{Env, FontKey, PageContent, PageFrame, analyze_page_content};
use crate::decode::decode_stream;
use crate::errors::{Issue, PreflightError, Result};
use crate::fonts::FontInfo;
use crate::guard;
use crate::images::ImageInfo;
use crate::limits::{Limits, OpenOptions};
use crate::pages::{PageGeometry, page_geometry};
use crate::text::{decode_text_string, pdf_date_to_iso};
use crate::value::Value;

const INFO_KEYS: [&str; 8] =
    ["Title", "Author", "Subject", "Keywords", "Creator", "Producer", "CreationDate", "ModDate"];

#[derive(Debug, Clone, Default)]
pub struct EncryptionInfo {
    pub filter: Option<String>,
    pub v: Option<i64>,
    pub r: Option<i64>,
    pub length: Option<i64>,
}

#[derive(Debug, Clone, Default)]
pub struct OutputIntent {
    pub subtype: Option<String>,
    pub output_condition_identifier: Option<String>,
    pub output_condition: Option<String>,
    pub registry_name: Option<String>,
    pub info: Option<String>,
    pub has_dest_output_profile: bool,
}

/// Structural facts gathered once at open time.
#[derive(Debug, Clone, Default)]
pub struct StructureInfo {
    pub file_size: u64,
    pub header_version: Option<String>,
    pub version: String,
    pub object_count: usize,
    pub encryption: Option<EncryptionInfo>,
    pub info: BTreeMap<String, String>,
    pub has_xmp_metadata: bool,
    pub pdfx_info: Option<String>,
    pub pdfx_xmp: Option<String>,
    pub output_intents: Vec<OutputIntent>,
    pub issues: Vec<Issue>,
    pub guard: guard::GuardReport,
}

pub struct Document {
    pdf: Pdf,
    limits: Limits,
    structure: StructureInfo,
    page_content: Vec<OnceCell<Rc<PageContent>>>,
    font_cache: RefCell<HashMap<FontKey, Rc<FontInfo>>>,
    image_cache: RefCell<HashMap<hayro_syntax::object::ObjectIdentifier, Rc<ImageInfo>>>,
    decoded_total: Cell<u64>,
    analysis_time: Cell<Duration>,
}

impl Document {
    pub fn open(path: &str, options: &OpenOptions) -> Result<Self> {
        let meta = std::fs::metadata(path).map_err(|e| PreflightError::Io(format!("{path}: {e}")))?;
        if !meta.is_file() {
            return Err(PreflightError::Io(format!("{path}: not a regular file")));
        }
        check_size(meta.len(), &options.limits)?;
        let data = std::fs::read(path).map_err(|e| PreflightError::Io(format!("{path}: {e}")))?;
        Self::from_bytes(data, options)
    }

    pub fn from_bytes(data: Vec<u8>, options: &OpenOptions) -> Result<Self> {
        let limits = options.limits.clone();
        check_size(data.len() as u64, &limits)?;
        if data.is_empty() {
            return Err(PreflightError::InvalidPdf("file is empty".into()));
        }

        let guard_report = guard::scan(&data, limits.max_stream_size)?;
        let file_size = data.len() as u64;
        let mut issues = scan_file_structure(&data);
        if guard_report.unverified > 0 {
            issues.push(Issue::new(
                "object_streams_unverified",
                format!(
                    "{} object/cross-reference stream(s) could not be checked for decompression bombs before parsing",
                    guard_report.unverified
                ),
            ));
        }

        let pdf = Pdf::new_with_password(data, &options.password).map_err(|e| match e {
            LoadPdfError::Decryption(DecryptionError::PasswordProtected) => PreflightError::PasswordRequired,
            LoadPdfError::Decryption(DecryptionError::UnsupportedAlgorithm) => {
                PreflightError::UnsupportedEncryption("unsupported encryption algorithm".into())
            }
            LoadPdfError::Decryption(other) => PreflightError::UnsupportedEncryption(format!("{other:?}")),
            LoadPdfError::Invalid => PreflightError::InvalidPdf("no readable document structure found".into()),
        })?;

        if pdf.len() > limits.max_objects {
            return Err(PreflightError::LimitExceeded {
                limit: "max_objects",
                detail: format!("document has {} objects, limit is {}", pdf.len(), limits.max_objects),
            });
        }
        let page_count = pdf.pages().len();
        if page_count > limits.max_pages {
            return Err(PreflightError::LimitExceeded {
                limit: "max_pages",
                detail: format!("document has {page_count} pages, limit is {}", limits.max_pages),
            });
        }

        let mut doc = Document {
            structure: StructureInfo { file_size, issues, guard: guard_report, ..Default::default() },
            page_content: (0..page_count).map(|_| OnceCell::new()).collect(),
            pdf,
            limits,
            font_cache: RefCell::new(HashMap::new()),
            image_cache: RefCell::new(HashMap::new()),
            decoded_total: Cell::new(0),
            analysis_time: Cell::new(Duration::ZERO),
        };
        doc.read_structure();
        Ok(doc)
    }

    pub fn page_count(&self) -> usize {
        self.page_content.len()
    }

    pub fn limits(&self) -> &Limits {
        &self.limits
    }

    pub fn structure(&self) -> &StructureInfo {
        &self.structure
    }

    fn check_page(&self, page: i64) -> Result<usize> {
        if page < 1 || page as u64 > self.page_count() as u64 {
            return Err(PreflightError::PageOutOfRange { page, page_count: self.page_count() });
        }
        Ok(page as usize - 1)
    }

    /// Geometry of a page (1-based numbering).
    pub fn page_geometry(&self, page: i64) -> Result<PageGeometry> {
        let idx = self.check_page(page)?;
        Ok(page_geometry(&self.pdf.pages()[idx], idx + 1))
    }

    /// Content analysis of a page (1-based numbering), computed once.
    pub fn page_content(&self, page: i64) -> Result<Rc<PageContent>> {
        let idx = self.check_page(page)?;
        Ok(self.page_content[idx]
            .get_or_init(|| {
                let started = Instant::now();
                let budget = Duration::from_millis(self.limits.max_analysis_time_ms);
                let deadline = (self.limits.max_analysis_time_ms > 0)
                    .then(|| started + budget.saturating_sub(self.analysis_time.get()));
                let env = Env {
                    limits: &self.limits,
                    font_cache: &self.font_cache,
                    image_cache: &self.image_cache,
                    decoded_total: &self.decoded_total,
                    deadline,
                };
                let geometry = page_geometry(&self.pdf.pages()[idx], idx + 1);
                let crop = geometry.boxes[1].effective;
                let frame = PageFrame { crop_box: [crop.x0, crop.y0, crop.x1, crop.y1], user_unit: geometry.user_unit };
                let content = analyze_page_content(&self.pdf.pages()[idx], idx + 1, &frame, &env);
                self.analysis_time.set(self.analysis_time.get() + started.elapsed());
                Rc::new(content)
            })
            .clone())
    }

    /// Fonts of a page as a list of maps.
    pub fn fonts_value(&self, page: i64) -> Result<Value> {
        let content = self.page_content(page)?;
        Ok(Value::List(content.fonts.iter().map(|f| f.to_value()).collect()))
    }

    /// Colour spaces (declared and used), spot colours and the print
    /// classification of a page.
    pub fn colors_value(&self, page: i64) -> Result<Value> {
        let content = self.page_content(page)?;
        Ok(content.colors.to_value(content.complete))
    }

    /// Image placements of a page with their effective resolution.
    pub fn images_value(&self, page: i64) -> Result<Value> {
        Ok(self.page_content(page)?.images_value())
    }

    pub fn page_info_value(&self, page: i64) -> Result<Value> {
        Ok(self.page_geometry(page)?.to_value())
    }

    pub fn document_info_value(&self) -> Value {
        let s = &self.structure;
        let mut info = Value::map();
        for key in INFO_KEYS {
            let v = s.info.get(key).cloned();
            info = info.with(key, v);
        }
        for date_key in ["CreationDate", "ModDate"] {
            let iso = s.info.get(date_key).and_then(|d| pdf_date_to_iso(d));
            info = info.with(&format!("{date_key}Iso"), iso);
        }

        let encryption = s.encryption.as_ref().map_or(Value::Null, |e| {
            Value::map().with("filter", e.filter.clone()).with("v", e.v).with("r", e.r).with("length", e.length)
        });

        let intents: Vec<Value> = s
            .output_intents
            .iter()
            .map(|oi| {
                Value::map()
                    .with("subtype", oi.subtype.clone())
                    .with("output_condition_identifier", oi.output_condition_identifier.clone())
                    .with("output_condition", oi.output_condition.clone())
                    .with("registry_name", oi.registry_name.clone())
                    .with("info", oi.info.clone())
                    .with("has_dest_output_profile", oi.has_dest_output_profile)
            })
            .collect();

        let declared = s.pdfx_xmp.clone().or_else(|| s.pdfx_info.clone());
        let pdfx = Value::map()
            .with("declared", declared.is_some())
            .with("version", declared)
            .with("version_info_dict", s.pdfx_info.clone())
            .with("version_xmp", s.pdfx_xmp.clone())
            .with("has_pdfx_output_intent", s.output_intents.iter().any(|oi| oi.subtype.as_deref() == Some("GTS_PDFX")))
            // The extension only reports the identification. Conformance is
            // never validated here.
            .with("validated", false);

        Value::map()
            .with("version", s.version.as_str())
            .with("header_version", s.header_version.clone())
            .with("page_count", self.page_count())
            .with("file_size", s.file_size)
            .with("object_count", s.object_count)
            .with("encrypted", s.encryption.is_some())
            .with("encryption", encryption)
            .with("info", info)
            .with("has_xmp_metadata", s.has_xmp_metadata)
            .with("pdfx", pdfx)
            .with("output_intents", Value::List(intents))
            .with(
                "guard",
                Value::map()
                    .with("object_streams_verified", s.guard.verified)
                    .with("object_streams_unverified", s.guard.unverified),
            )
            .with("issues", Value::List(s.issues.iter().map(Issue::to_value).collect()))
    }

    /// Full analysis of all pages. Content analysis results are cached, so
    /// calling this after per-page queries does not repeat work.
    pub fn analyze_value(&self) -> Value {
        let mut pages = Vec::with_capacity(self.page_count());
        // Aggregated per font: info, pages with usage.
        let mut doc_fonts: Vec<(FontKey, Rc<FontInfo>, Vec<usize>, bool)> = Vec::new();
        let mut doc_font_index: HashMap<FontKey, usize> = HashMap::new();
        let mut complete = true;
        let (mut color_pages, mut gray_pages, mut unknown_pages) = (Vec::new(), Vec::new(), Vec::new());
        let mut spot_colors = std::collections::BTreeSet::new();

        for page in 1..=self.page_count() {
            let p = page as i64;
            let geometry = self.page_geometry(p).expect("page in range");
            let content = self.page_content(p).expect("page in range");
            complete &= content.complete;

            for usage in &content.fonts {
                let idx = *doc_font_index.entry(usage.key).or_insert_with(|| {
                    doc_fonts.push((usage.key, usage.info.clone(), Vec::new(), false));
                    doc_fonts.len() - 1
                });
                if usage.used {
                    doc_fonts[idx].2.push(page);
                    doc_fonts[idx].3 = true;
                }
            }

            match content.colors.classification(content.complete) {
                "color" => color_pages.push(page),
                "grayscale" => gray_pages.push(page),
                _ => unknown_pages.push(page),
            }
            spot_colors.extend(content.colors.spot_colors.iter().cloned());

            let mut page_value = geometry.to_value();
            if let Value::Map(entries) = &mut page_value {
                entries.push(("fonts".into(), Value::List(content.fonts.iter().map(|f| f.to_value()).collect())));
                entries.push(("colors".into(), content.colors.to_value(content.complete)));
                entries.push(("images".into(), content.images_value()));
                entries
                    .push(("content_issues".into(), Value::List(content.issues.iter().map(Issue::to_value).collect())));
                entries.push(("analysis_complete".into(), content.complete.into()));
            }
            pages.push(page_value);
        }

        let fonts: Vec<Value> = doc_fonts
            .iter()
            .map(|(_, info, used_pages, used)| {
                let mut v = info.to_value();
                if let Value::Map(entries) = &mut v {
                    entries.push(("used".into(), (*used).into()));
                    entries.push(("pages".into(), used_pages.clone().into()));
                }
                v
            })
            .collect();

        Value::map()
            .with("document", self.document_info_value())
            .with("pages", Value::List(pages))
            .with("fonts", Value::List(fonts))
            .with(
                "colors",
                Value::map()
                    .with("color_pages", color_pages)
                    .with("grayscale_pages", gray_pages)
                    .with("unknown_pages", unknown_pages)
                    .with("spot_colors", spot_colors.into_iter().collect::<Vec<_>>()),
            )
            .with("analysis_complete", complete)
            .with("limits", self.limits.to_value())
    }

    fn read_structure(&mut self) {
        let data = self.pdf.data().as_ref();
        let xref = self.pdf.xref();
        let s = &mut self.structure;
        s.object_count = self.pdf.len();
        s.header_version = header_version(data);
        s.version = version_str(self.pdf.version()).to_string();

        if let Some(trailer) = find_trailer(data) {
            let mut r = Reader::new_with(data, trailer);
            if let Some(dict) = r.read_without_context::<Dict<'_>>() {
                if let Some(enc_ref) = dict.get_ref(b"Encrypt") {
                    let enc = xref.get::<Dict<'_>>(enc_ref.into());
                    s.encryption = Some(EncryptionInfo {
                        filter: enc.as_ref().and_then(|e| e.get::<Name<'_>>(b"Filter")).map(|n| n.as_str().to_string()),
                        v: enc.as_ref().and_then(|e| e.get::<i64>(b"V")),
                        r: enc.as_ref().and_then(|e| e.get::<i64>(b"R")),
                        length: enc.as_ref().and_then(|e| e.get::<i64>(b"Length")),
                    });
                } else if dict.contains_key(b"Encrypt") {
                    s.encryption = Some(EncryptionInfo::default());
                }
                if let Some(info) = dict.get_ref(b"Info").and_then(|r| xref.get::<Dict<'_>>(r.into())) {
                    for key in INFO_KEYS {
                        if let Some(v) = info.get::<hayro_syntax::object::String<'_>>(key.as_bytes()) {
                            s.info.insert(key.to_string(), decode_text_string(v.as_bytes()));
                        }
                    }
                    s.pdfx_info = info
                        .get::<hayro_syntax::object::String<'_>>(b"GTS_PDFXVersion")
                        .map(|v| decode_text_string(v.as_bytes()));
                }
            }
        } else {
            s.issues.push(Issue::new(
                "trailer_not_found",
                "no trailer dictionary found; document information is unavailable",
            ));
        }

        let Some(catalog) = xref.get::<Dict<'_>>(xref.root_id()) else {
            s.issues.push(Issue::new("catalog_missing", "the document catalog could not be read"));
            return;
        };

        if let Some(intents) = catalog.get::<Array<'_>>(b"OutputIntents") {
            for oi in intents.iter::<Object<'_>>().filter_map(Object::into_dict) {
                let text =
                    |k: &[u8]| oi.get::<hayro_syntax::object::String<'_>>(k).map(|v| decode_text_string(v.as_bytes()));
                s.output_intents.push(OutputIntent {
                    subtype: oi.get::<Name<'_>>(b"S").map(|n| n.as_str().to_string()),
                    output_condition_identifier: text(b"OutputConditionIdentifier"),
                    output_condition: text(b"OutputCondition"),
                    registry_name: text(b"RegistryName"),
                    info: text(b"Info"),
                    has_dest_output_profile: oi.get::<Stream<'_>>(b"DestOutputProfile").is_some(),
                });
            }
        }

        if let Some(xmp) = catalog.get::<Stream<'_>>(b"Metadata") {
            s.has_xmp_metadata = true;
            match decode_stream(&xmp, self.limits.max_stream_size.min(16 * 1024 * 1024)) {
                Ok(bytes) => s.pdfx_xmp = xmp_pdfx_version(&bytes),
                Err(e) => s.issues.push(Issue::new("xmp_unreadable", format!("XMP metadata cannot be decoded: {e}"))),
            }
        }

        let declared_count = catalog.get::<Dict<'_>>(b"Pages").and_then(|p| p.get::<i64>(b"Count"));
        if let Some(count) = declared_count
            && count != self.page_content.len() as i64
        {
            s.issues.push(Issue::new(
                "page_count_mismatch",
                format!("page tree declares {count} pages but {} were found", self.page_content.len()),
            ));
        }
    }
}

fn check_size(size: u64, limits: &Limits) -> Result<()> {
    if size > limits.max_file_size {
        return Err(PreflightError::FileTooLarge { size, limit: limits.max_file_size });
    }
    Ok(())
}

fn version_str(v: PdfVersion) -> &'static str {
    match v {
        PdfVersion::Pdf10 => "1.0",
        PdfVersion::Pdf11 => "1.1",
        PdfVersion::Pdf12 => "1.2",
        PdfVersion::Pdf13 => "1.3",
        PdfVersion::Pdf14 => "1.4",
        PdfVersion::Pdf15 => "1.5",
        PdfVersion::Pdf16 => "1.6",
        PdfVersion::Pdf17 => "1.7",
        PdfVersion::Pdf20 => "2.0",
    }
}

fn header_version(data: &[u8]) -> Option<String> {
    let head = &data[..data.len().min(1024)];
    let pos = memmem::find(head, b"%PDF-")? + 5;
    let v: String =
        head[pos..].iter().take_while(|b| b.is_ascii_digit() || **b == b'.').map(|&b| char::from(b)).collect();
    (!v.is_empty()).then_some(v)
}

/// Basic file-level checks that the (lenient) parser does not report.
fn scan_file_structure(data: &[u8]) -> Vec<Issue> {
    let mut issues = Vec::new();
    let header = memmem::find(&data[..data.len().min(1024)], b"%PDF-");
    match header {
        None => issues.push(Issue::new("header_missing", "no %PDF- header in the first 1024 bytes")),
        Some(0) => {}
        Some(offset) => {
            issues.push(Issue::new("header_offset", format!("%PDF- header starts at byte {offset} instead of 0")))
        }
    }
    let tail = &data[data.len().saturating_sub(1024)..];
    if memmem::rfind(tail, b"%%EOF").is_none() {
        issues.push(Issue::new("eof_marker_missing", "no %%EOF marker at the end of the file (truncated?)"));
    }
    match startxref_offset(data) {
        None => issues.push(Issue::new("startxref_missing", "no startxref entry found; cross-reference data is reconstructed")),
        // Readers resolve offsets relative to the header if there is junk
        // before it; that case is already reported as `header_offset`.
        Some(off) if !points_to_xref(data, off) && !points_to_xref(data, off + header.unwrap_or(0)) => issues.push(Issue::new(
            "startxref_invalid",
            format!("startxref offset {off} does not point to a cross-reference section; cross-reference data is reconstructed"),
        )),
        Some(_) => {}
    }
    issues
}

fn startxref_offset(data: &[u8]) -> Option<usize> {
    let tail_start = data.len().saturating_sub(4096);
    let pos = memmem::rfind(&data[tail_start..], b"startxref")? + tail_start + 9;
    let digits: String = data[pos..]
        .iter()
        .skip_while(|b| b.is_ascii_whitespace())
        .take_while(|b| b.is_ascii_digit())
        .map(|&b| char::from(b))
        .collect();
    digits.parse().ok()
}

fn points_to_xref(data: &[u8], offset: usize) -> bool {
    let Some(rest) = data.get(offset..) else { return false };
    let rest = &rest[..rest.len().min(64)];
    let trimmed: &[u8] = {
        let start = rest.iter().position(|b| !b.is_ascii_whitespace()).unwrap_or(rest.len());
        &rest[start..]
    };
    if trimmed.starts_with(b"xref") {
        return true;
    }
    // "<num> <gen> obj" of a cross-reference stream
    let text = String::from_utf8_lossy(trimmed);
    let mut parts = text.split_ascii_whitespace();
    matches!(
        (parts.next(), parts.next(), parts.next()),
        (Some(a), Some(b), Some(c)) if a.parse::<u64>().is_ok() && b.parse::<u64>().is_ok() && c.starts_with("obj")
    )
}

/// Byte offset of the dictionary of the most recent trailer: either a classic
/// `trailer << … >>` or the dictionary of the cross-reference stream that
/// `startxref` points to.
fn find_trailer(data: &[u8]) -> Option<usize> {
    let header = memmem::find(&data[..data.len().min(1024)], b"%PDF-").unwrap_or(0);
    let off = startxref_offset(data).map(|off| if points_to_xref(data, off) { off } else { off + header });
    if let Some(off) = off
        && let Some(rest) = data.get(off..)
    {
        let window = &rest[..rest.len().min(512)];
        if let Some(obj) = memmem::find(window, b"obj")
            && !window.starts_with(b"xref")
            && let Some(dict) = memmem::find(&window[obj..], b"<<")
        {
            return Some(off + obj + dict);
        }
    }
    let pos = memmem::rfind(data, b"trailer")?;
    let dict = memmem::find(&data[pos..data.len().min(pos + 1024)], b"<<")?;
    Some(pos + dict)
}

/// Extracts `GTS_PDFXVersion` from XMP (attribute or element form, any
/// namespace prefix such as `pdfxid:` or `pdfx:`).
fn xmp_pdfx_version(xmp: &[u8]) -> Option<String> {
    let text = String::from_utf8_lossy(xmp);
    let key = ":GTS_PDFXVersion";
    let mut search = 0;
    while let Some(found) = text[search..].find(key) {
        let after = &text[search + found + key.len()..];
        let value = if let Some(rest) = after.trim_start().strip_prefix('=') {
            let rest = rest.trim_start();
            let quote = rest.chars().next()?;
            rest[1..].split(quote).next().map(str::to_string)
        } else if let Some(rest) = after.strip_prefix('>') {
            rest.split('<').next().map(|v| v.trim().to_string())
        } else {
            None
        };
        if let Some(v) = value.filter(|v| !v.is_empty()) {
            return Some(v);
        }
        search += found + key.len();
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn xmp_versions() {
        assert_eq!(
            xmp_pdfx_version(b"<rdf:Description pdfxid:GTS_PDFXVersion=\"PDF/X-4\"/>").as_deref(),
            Some("PDF/X-4")
        );
        assert_eq!(
            xmp_pdfx_version(b"<pdfx:GTS_PDFXVersion>PDF/X-1:2001</pdfx:GTS_PDFXVersion>").as_deref(),
            Some("PDF/X-1:2001")
        );
        assert_eq!(xmp_pdfx_version(b"<x:xmpmeta/>"), None);
    }

    #[test]
    fn xref_pointer() {
        assert!(points_to_xref(b"xref\n0 1\n", 0));
        assert!(points_to_xref(b"\n12 0 obj\n<<", 0));
        assert!(!points_to_xref(b"garbage", 0));
        assert!(!points_to_xref(b"", 10));
    }
}
