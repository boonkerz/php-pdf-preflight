//! Pre-scan that bounds the streams `hayro-syntax` decodes internally.
//!
//! Object streams (`/Type /ObjStm`) and cross-reference streams
//! (`/Type /XRef`) are decompressed by the parser itself while objects are
//! resolved, without an output limit. Before handing the data to the parser
//! we locate these streams in the raw file and measure their inflated size
//! with a constant-memory streaming inflater.
//!
//! Limitation: object streams of encrypted documents cannot be inflated
//! before decryption and are reported as "unverified".

use std::collections::HashSet;

use hayro_syntax::object::{Dict, Name};
use hayro_syntax::reader::{Reader, ReaderExt};
use memchr::memmem;

use crate::decode::{filter_names, inflated_size_exceeds};
use crate::errors::{PreflightError, Result};

#[derive(Debug, Default, Clone, PartialEq)]
pub struct GuardReport {
    /// Object/xref streams whose decoded size was verified.
    pub verified: usize,
    /// Object/xref streams that could not be verified (encrypted, unusual
    /// filters, unparseable dictionaries).
    pub unverified: usize,
}

pub fn scan(data: &[u8], limit: usize) -> Result<GuardReport> {
    let mut report = GuardReport::default();
    let mut seen = HashSet::new();

    for needle in [&b"/ObjStm"[..], &b"/XRef"[..]] {
        for hit in memmem::find_iter(data, needle) {
            // `/XRefStm` is a trailer key, not a stream type.
            let after = data.get(hit + needle.len()).copied().unwrap_or(b' ');
            if after.is_ascii_alphanumeric() {
                continue;
            }
            let Some(dict_start) = find_dict_start(data, hit) else { continue };
            if !seen.insert(dict_start) {
                continue;
            }
            match check_stream(data, dict_start, limit) {
                Check::Ok => report.verified += 1,
                Check::Unverified => report.unverified += 1,
                Check::NotAStream => {}
                Check::TooLarge => {
                    return Err(PreflightError::LimitExceeded {
                        limit: "max_stream_size",
                        detail: format!(
                            "an object or cross-reference stream at byte offset {dict_start} decompresses to more than {limit} bytes"
                        ),
                    });
                }
            }
        }
    }

    Ok(report)
}

enum Check {
    Ok,
    Unverified,
    NotAStream,
    TooLarge,
}

/// Finds the `<<` that opens the dictionary of the indirect object containing
/// position `hit`, by searching backwards for the `obj` keyword.
fn find_dict_start(data: &[u8], hit: usize) -> Option<usize> {
    let window_start = hit.saturating_sub(4096);
    let obj_pos = memmem::rfind(&data[window_start..hit], b"obj")? + window_start;
    let mut pos = obj_pos + 3;
    while pos < hit && data[pos].is_ascii_whitespace() {
        pos += 1;
    }
    data[pos..].starts_with(b"<<").then_some(pos)
}

fn check_stream(data: &[u8], dict_start: usize, limit: usize) -> Check {
    let mut r = Reader::new_with(data, dict_start);
    let Some(dict) = r.read_without_context::<Dict<'_>>() else { return Check::Unverified };
    let is_target = matches!(dict.get::<Name<'_>>(b"Type").as_deref(), Some(b"ObjStm" | b"XRef"));
    if !is_target {
        return Check::NotAStream;
    }

    let mut pos = r.offset;
    while pos < data.len() && data[pos].is_ascii_whitespace() {
        pos += 1;
    }
    if !data[pos..].starts_with(b"stream") {
        return Check::NotAStream;
    }
    pos += 6;
    if data[pos..].starts_with(b"\r\n") {
        pos += 2;
    } else if pos < data.len() && (data[pos] == b'\n' || data[pos] == b'\r') {
        pos += 1;
    }

    let end = dict
        .get::<usize>(b"Length")
        .and_then(|len| pos.checked_add(len))
        .filter(|&end| end <= data.len())
        .or_else(|| memmem::find(&data[pos..], b"endstream").map(|e| pos + e));
    let Some(end) = end else { return Check::Unverified };

    let filters = filter_names(&dict);
    match filters.as_slice() {
        [] => {
            if end - pos > limit {
                Check::TooLarge
            } else {
                Check::Ok
            }
        }
        [f] if f == "FlateDecode" => match inflated_size_exceeds(&data[pos..end], limit) {
            Some(true) => Check::TooLarge,
            Some(false) => Check::Ok,
            None => Check::Unverified,
        },
        _ => Check::Unverified,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn objstm(payload_len: usize) -> Vec<u8> {
        let payload = vec![b' '; payload_len];
        let compressed = miniz_oxide::deflate::compress_to_vec_zlib(&payload, 9);
        let mut out = format!(
            "%PDF-1.5\n5 0 obj\n<< /Type /ObjStm /N 0 /First 0 /Filter /FlateDecode /Length {} >>\nstream\n",
            compressed.len()
        )
        .into_bytes();
        out.extend_from_slice(&compressed);
        out.extend_from_slice(b"\nendstream\nendobj\n");
        out
    }

    #[test]
    fn detects_object_stream_bomb() {
        let data = objstm(5_000_000);
        assert!(matches!(scan(&data, 1_000_000), Err(PreflightError::LimitExceeded { .. })));
        assert_eq!(scan(&data, 10_000_000).unwrap(), GuardReport { verified: 1, unverified: 0 });
    }

    #[test]
    fn ignores_xrefstm_key() {
        let data = b"trailer << /Size 3 /XRefStm 123 >>";
        assert_eq!(scan(data, 10).unwrap(), GuardReport::default());
    }
}
