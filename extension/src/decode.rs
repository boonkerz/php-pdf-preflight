//! Size-bounded decoding of PDF stream filters.
//!
//! `hayro-syntax` can decode streams itself, but without an output limit. All
//! streams the analysis needs (content streams, form XObjects, XMP metadata,
//! font program headers) are therefore decoded here with a hard cap on the
//! output size, which makes decompression bombs fail early and cheaply.

use hayro_syntax::object::{Dict, Name, Object, Stream};
#[cfg(test)]
use hayro_syntax::reader::ReaderExt;
use miniz_oxide::inflate::stream::{InflateState, inflate};
use miniz_oxide::{DataFormat, MZError, MZFlush, MZStatus};

#[derive(Debug, Clone, PartialEq)]
pub enum DecodeError {
    /// The decoded data would exceed `limit` bytes.
    LimitExceeded { limit: usize },
    /// The stream data is damaged.
    Corrupt(String),
    /// The stream uses a filter that is not needed for analysis (e.g. image
    /// codecs) or not supported.
    Unsupported(String),
}

impl std::fmt::Display for DecodeError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::LimitExceeded { limit } => write!(f, "decoded stream exceeds {limit} bytes"),
            Self::Corrupt(m) => write!(f, "corrupt stream data: {m}"),
            Self::Unsupported(m) => write!(f, "unsupported stream filter: {m}"),
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Filter {
    Flate,
    Lzw,
    AsciiHex,
    Ascii85,
    RunLength,
    /// `/Crypt` with the identity filter; decryption itself is done by the parser.
    Crypt,
}

struct FilterStep<'a> {
    filter: Filter,
    params: Option<Dict<'a>>,
}

/// Returns the names of all filters applied to a stream (normalised to their
/// long form, e.g. `FlateDecode`).
pub fn filter_names(dict: &Dict<'_>) -> Vec<String> {
    raw_filters(dict).into_iter().map(|(name, _)| canonical_filter_name(&name).to_string()).collect()
}

fn canonical_filter_name(name: &str) -> &str {
    match name {
        "Fl" => "FlateDecode",
        "LZW" => "LZWDecode",
        "AHx" => "ASCIIHexDecode",
        "A85" => "ASCII85Decode",
        "RL" => "RunLengthDecode",
        "DCT" => "DCTDecode",
        "CCF" => "CCITTFaxDecode",
        "JPX" => "JPXDecode",
        other => other,
    }
}

fn raw_filters<'a>(dict: &Dict<'a>) -> Vec<(String, Option<Dict<'a>>)> {
    // Inline images use the abbreviations /F and /DP.
    let (filter_key, params_key): (&[u8], &[u8]) = if dict.contains_key(b"Filter") || !dict.contains_key(b"F") {
        (b"Filter", b"DecodeParms")
    } else {
        (b"F", b"DP")
    };
    let mut names = Vec::new();
    if let Some(name) = dict.get::<Name<'_>>(filter_key) {
        names.push(name.as_str().to_string());
    } else if let Some(arr) = dict.get::<hayro_syntax::object::Array<'_>>(filter_key) {
        for obj in arr.iter::<Object<'_>>() {
            match obj.into_name() {
                Some(n) => names.push(n.as_str().to_string()),
                None => names.push(String::from("<invalid>")),
            }
        }
    }

    let mut params: Vec<Option<Dict<'a>>> = Vec::new();
    if let Some(p) = dict.get::<Dict<'a>>(params_key) {
        params.push(Some(p));
    } else if let Some(arr) = dict.get::<hayro_syntax::object::Array<'a>>(params_key) {
        for obj in arr.iter::<Object<'a>>() {
            params.push(obj.into_dict());
        }
    }

    names.into_iter().enumerate().map(|(i, n)| (n, params.get(i).cloned().flatten())).collect()
}

fn parse_filters<'a>(dict: &Dict<'a>) -> Result<Vec<FilterStep<'a>>, DecodeError> {
    raw_filters(dict)
        .into_iter()
        .map(|(name, params)| {
            let filter = match canonical_filter_name(&name) {
                "FlateDecode" => Filter::Flate,
                "LZWDecode" => Filter::Lzw,
                "ASCIIHexDecode" => Filter::AsciiHex,
                "ASCII85Decode" => Filter::Ascii85,
                "RunLengthDecode" => Filter::RunLength,
                "Crypt" => Filter::Crypt,
                other => return Err(DecodeError::Unsupported(other.to_string())),
            };
            Ok(FilterStep { filter, params })
        })
        .collect()
}

/// Decodes a stream completely, failing if the output exceeds `limit` bytes.
pub fn decode_stream(stream: &Stream<'_>, limit: usize) -> Result<Vec<u8>, DecodeError> {
    let steps = parse_filters(stream.dict())?;
    let raw = stream.raw_data();
    if steps.is_empty() {
        if raw.len() > limit {
            return Err(DecodeError::LimitExceeded { limit });
        }
        return Ok(raw.into_owned());
    }
    let mut data = raw.into_owned();
    for step in &steps {
        data = apply(step, &data, limit, false)?;
    }
    Ok(data)
}

/// Decodes at most the first `n` bytes of a stream. Used to inspect headers
/// (e.g. font program signatures) without inflating the whole stream.
pub fn decode_prefix(stream: &Stream<'_>, n: usize) -> Result<Vec<u8>, DecodeError> {
    let steps = parse_filters(stream.dict())?;
    let raw = stream.raw_data();
    match steps.as_slice() {
        [] => Ok(raw[..raw.len().min(n)].to_vec()),
        [single] if single.filter == Filter::Flate && !has_predictor(&single.params) => apply(single, &raw, n, true),
        _ => {
            // Chained filters are rare for the streams we inspect this way;
            // decode with a moderate bound and cut afterwards.
            let mut data = raw.into_owned();
            for step in &steps {
                data = apply(step, &data, 16 * 1024 * 1024, false)?;
            }
            data.truncate(n);
            Ok(data)
        }
    }
}

fn has_predictor(params: &Option<Dict<'_>>) -> bool {
    params.as_ref().and_then(|p| p.get::<i64>(b"Predictor")).unwrap_or(1) > 1
}

fn apply(step: &FilterStep<'_>, input: &[u8], limit: usize, truncate: bool) -> Result<Vec<u8>, DecodeError> {
    let decoded = match step.filter {
        Filter::Flate => inflate_bounded(input, limit, truncate)?,
        Filter::Lzw => {
            let early = step.params.as_ref().and_then(|p| p.get::<i64>(b"EarlyChange")).unwrap_or(1);
            lzw_decode(input, early != 0, limit)?
        }
        Filter::AsciiHex => ascii_hex_decode(input, limit)?,
        Filter::Ascii85 => ascii85_decode(input, limit)?,
        Filter::RunLength => run_length_decode(input, limit)?,
        Filter::Crypt => input.to_vec(),
    };
    if has_predictor(&step.params) && matches!(step.filter, Filter::Flate | Filter::Lzw) {
        return apply_predictor(decoded, step.params.as_ref().expect("checked by has_predictor"));
    }
    Ok(decoded)
}

/// Streaming inflate with an output cap. Accepts zlib streams as well as raw
/// deflate data (both occur in the wild). Damaged streams yield the data
/// decoded so far, like most PDF viewers do, unless nothing could be decoded.
fn inflate_bounded(input: &[u8], limit: usize, truncate: bool) -> Result<Vec<u8>, DecodeError> {
    let zlib = input.len() >= 2 && (input[0] & 0x0f) == 8 && (u16::from(input[0]) << 8 | u16::from(input[1])) % 31 == 0;
    let format = if zlib { DataFormat::Zlib } else { DataFormat::Raw };
    let mut state = InflateState::new_boxed(format);
    let mut out = Vec::new();
    let mut buf = vec![0u8; 64 * 1024];
    let mut pos = 0;

    loop {
        let res = inflate(&mut state, &input[pos..], &mut buf, MZFlush::None);
        pos += res.bytes_consumed;
        if out.len() + res.bytes_written > limit {
            if truncate {
                out.extend_from_slice(&buf[..limit - out.len()]);
                return Ok(out);
            }
            return Err(DecodeError::LimitExceeded { limit });
        }
        out.extend_from_slice(&buf[..res.bytes_written]);

        match res.status {
            Ok(MZStatus::StreamEnd) => return Ok(out),
            Ok(_) if res.bytes_consumed == 0 && res.bytes_written == 0 => return Ok(out),
            Ok(_) => {}
            // Truncated input: return what we have.
            Err(MZError::Buf) => return Ok(out),
            Err(e) => {
                if out.is_empty() {
                    return Err(DecodeError::Corrupt(format!("inflate failed: {e:?}")));
                }
                return Ok(out);
            }
        }
    }
}

fn push_bounded(out: &mut Vec<u8>, bytes: &[u8], limit: usize) -> Result<(), DecodeError> {
    if out.len() + bytes.len() > limit {
        return Err(DecodeError::LimitExceeded { limit });
    }
    out.extend_from_slice(bytes);
    Ok(())
}

fn ascii_hex_decode(input: &[u8], limit: usize) -> Result<Vec<u8>, DecodeError> {
    let mut out = Vec::with_capacity((input.len() / 2).min(limit));
    let mut high: Option<u8> = None;
    for &b in input {
        let v = match b {
            b'0'..=b'9' => b - b'0',
            b'a'..=b'f' => b - b'a' + 10,
            b'A'..=b'F' => b - b'A' + 10,
            b'>' => break,
            b if b.is_ascii_whitespace() || b == 0 => continue,
            _ => return Err(DecodeError::Corrupt("invalid character in ASCIIHex data".into())),
        };
        match high.take() {
            Some(h) => push_bounded(&mut out, &[h << 4 | v], limit)?,
            None => high = Some(v),
        }
    }
    if let Some(h) = high {
        push_bounded(&mut out, &[h << 4], limit)?;
    }
    Ok(out)
}

fn ascii85_decode(input: &[u8], limit: usize) -> Result<Vec<u8>, DecodeError> {
    let mut out = Vec::new();
    let mut group = [0u8; 5];
    let mut n = 0;
    let data = input.strip_prefix(b"<~").unwrap_or(input);
    for &b in data {
        match b {
            b'~' => break,
            b'z' if n == 0 => push_bounded(&mut out, &[0, 0, 0, 0], limit)?,
            b'!'..=b'u' => {
                group[n] = b - b'!';
                n += 1;
                if n == 5 {
                    let v = group.iter().fold(0u64, |acc, &d| acc * 85 + u64::from(d));
                    if v > u64::from(u32::MAX) {
                        return Err(DecodeError::Corrupt("ASCII85 group out of range".into()));
                    }
                    push_bounded(&mut out, &(v as u32).to_be_bytes(), limit)?;
                    n = 0;
                }
            }
            b if b.is_ascii_whitespace() || b == 0 => {}
            _ => return Err(DecodeError::Corrupt("invalid character in ASCII85 data".into())),
        }
    }
    if n > 1 {
        for slot in group.iter_mut().skip(n) {
            *slot = 84;
        }
        let v = group.iter().fold(0u64, |acc, &d| acc * 85 + u64::from(d));
        let bytes = (v.min(u64::from(u32::MAX)) as u32).to_be_bytes();
        push_bounded(&mut out, &bytes[..n - 1], limit)?;
    }
    Ok(out)
}

fn run_length_decode(input: &[u8], limit: usize) -> Result<Vec<u8>, DecodeError> {
    let mut out = Vec::new();
    let mut i = 0;
    while i < input.len() {
        let len = input[i];
        i += 1;
        match len {
            128 => break,
            0..=127 => {
                let n = usize::from(len) + 1;
                let end = (i + n).min(input.len());
                push_bounded(&mut out, &input[i..end], limit)?;
                i = end;
            }
            _ => {
                let n = 257 - usize::from(len);
                let Some(&b) = input.get(i) else { break };
                if out.len() + n > limit {
                    return Err(DecodeError::LimitExceeded { limit });
                }
                out.resize(out.len() + n, b);
                i += 1;
            }
        }
    }
    Ok(out)
}

fn lzw_decode(input: &[u8], early_change: bool, limit: usize) -> Result<Vec<u8>, DecodeError> {
    const CLEAR: usize = 256;
    const EOD: usize = 257;
    let mut out = Vec::new();
    // Each table entry is (prefix index, last byte, length).
    let mut table: Vec<(usize, u8, usize)> = (0..256).map(|b| (usize::MAX, b as u8, 1)).collect();
    table.push((usize::MAX, 0, 0));
    table.push((usize::MAX, 0, 0));
    let mut code_len = 9;
    let mut prev: Option<usize> = None;
    let (mut bit_buf, mut bit_count) = (0u32, 0u32);
    let mut scratch = Vec::new();

    let expand = |table: &Vec<(usize, u8, usize)>, mut code: usize, scratch: &mut Vec<u8>| {
        scratch.clear();
        while code != usize::MAX {
            let (prefix, byte, _) = table[code];
            scratch.push(byte);
            code = prefix;
        }
        scratch.reverse();
    };

    for &byte in input {
        bit_buf = (bit_buf << 8) | u32::from(byte);
        bit_count += 8;
        while bit_count >= code_len {
            let code = ((bit_buf >> (bit_count - code_len)) & ((1 << code_len) - 1)) as usize;
            bit_count -= code_len;
            if code == CLEAR {
                table.truncate(258);
                code_len = 9;
                prev = None;
                continue;
            }
            if code == EOD {
                return Ok(out);
            }
            let first_byte;
            if code < table.len() {
                expand(&table, code, &mut scratch);
                first_byte = scratch[0];
            } else if code == table.len() && prev.is_some() {
                let p = prev.expect("checked");
                expand(&table, p, &mut scratch);
                first_byte = scratch[0];
                scratch.push(first_byte);
            } else {
                return Err(DecodeError::Corrupt("invalid LZW code".into()));
            }
            push_bounded(&mut out, &scratch, limit)?;
            if let Some(p) = prev
                && table.len() < 4096
            {
                let len = table[p].2 + 1;
                table.push((p, first_byte, len));
            }
            prev = Some(code);
            let threshold = table.len() + usize::from(early_change);
            code_len = match threshold {
                t if t >= 2048 => 12,
                t if t >= 1024 => 11,
                t if t >= 512 => 10,
                _ => 9,
            };
        }
    }
    Ok(out)
}

/// PNG (10–15) and TIFF (2, 8 bit only) predictors.
fn apply_predictor(data: Vec<u8>, params: &Dict<'_>) -> Result<Vec<u8>, DecodeError> {
    let predictor = params.get::<i64>(b"Predictor").unwrap_or(1);
    let colors = params.get::<i64>(b"Colors").unwrap_or(1).clamp(1, 32) as usize;
    let bpc = params.get::<i64>(b"BitsPerComponent").unwrap_or(8).clamp(1, 16) as usize;
    let columns = params.get::<i64>(b"Columns").unwrap_or(1).clamp(1, 1 << 20) as usize;
    let bpp = (colors * bpc).div_ceil(8).max(1);
    let row_len = (colors * bpc * columns).div_ceil(8);

    match predictor {
        2 if bpc == 8 => {
            let mut out = data;
            for row in out.chunks_mut(row_len) {
                for i in bpp..row.len() {
                    row[i] = row[i].wrapping_add(row[i - bpp]);
                }
            }
            Ok(out)
        }
        10..=15 => {
            let mut out = Vec::with_capacity(data.len());
            let mut prev_row = vec![0u8; row_len];
            for chunk in data.chunks(row_len + 1) {
                let (kind, src) = (chunk[0], &chunk[1..]);
                let mut row = src.to_vec();
                row.resize(row_len, 0);
                for i in 0..row_len {
                    let left = if i >= bpp { row[i - bpp] } else { 0 };
                    let up = prev_row[i];
                    let up_left = if i >= bpp { prev_row[i - bpp] } else { 0 };
                    row[i] = match kind {
                        0 => row[i],
                        1 => row[i].wrapping_add(left),
                        2 => row[i].wrapping_add(up),
                        3 => row[i].wrapping_add(((u16::from(left) + u16::from(up)) / 2) as u8),
                        4 => row[i].wrapping_add(paeth(left, up, up_left)),
                        _ => return Err(DecodeError::Corrupt("invalid PNG predictor row type".into())),
                    };
                }
                out.extend_from_slice(&row[..src.len().min(row_len)]);
                prev_row = row;
            }
            Ok(out)
        }
        1 => Ok(data),
        other => Err(DecodeError::Unsupported(format!("predictor {other}"))),
    }
}

fn paeth(a: u8, b: u8, c: u8) -> u8 {
    let p = i16::from(a) + i16::from(b) - i16::from(c);
    let (pa, pb, pc) = ((p - i16::from(a)).abs(), (p - i16::from(b)).abs(), (p - i16::from(c)).abs());
    if pa <= pb && pa <= pc {
        a
    } else if pb <= pc {
        b
    } else {
        c
    }
}

/// Streams the decoded data of a stream to `sink` in chunks if it uses a
/// single FlateDecode filter without predictor (the common case for image
/// data); `sink` returns false to stop early. Returns `None` if the stream
/// needs the general `decode_stream` path, otherwise the number of decoded
/// bytes or an error.
pub fn stream_flate(
    stream: &Stream<'_>,
    limit: usize,
    mut sink: impl FnMut(&[u8]) -> bool,
) -> Option<Result<usize, DecodeError>> {
    let steps = parse_filters(stream.dict()).ok()?;
    let [step] = steps.as_slice() else { return None };
    if step.filter != Filter::Flate {
        return None;
    }
    let mut png = match &step.params {
        Some(params) if has_predictor(&step.params) => Some(PngRows::new(params)?),
        _ => None,
    };
    let mut sink = move |chunk: &[u8]| match &mut png {
        Some(rows) => rows.feed(chunk, &mut sink),
        None => sink(chunk),
    };
    let input = stream.raw_data();
    let zlib = input.len() >= 2 && (input[0] & 0x0f) == 8 && (u16::from(input[0]) << 8 | u16::from(input[1])) % 31 == 0;
    let mut state = InflateState::new_boxed(if zlib { DataFormat::Zlib } else { DataFormat::Raw });
    let mut buf = vec![0u8; 64 * 1024];
    let (mut pos, mut total) = (0usize, 0usize);
    loop {
        let res = inflate(&mut state, &input[pos..], &mut buf, MZFlush::None);
        pos += res.bytes_consumed;
        total += res.bytes_written;
        if total > limit {
            return Some(Err(DecodeError::LimitExceeded { limit }));
        }
        if res.bytes_written > 0 && !sink(&buf[..res.bytes_written]) {
            return Some(Ok(total));
        }
        match res.status {
            Ok(MZStatus::StreamEnd) | Err(MZError::Buf) => return Some(Ok(total)),
            Ok(_) if res.bytes_consumed == 0 && res.bytes_written == 0 => return Some(Ok(total)),
            Ok(_) => {}
            Err(e) if total == 0 => return Some(Err(DecodeError::Corrupt(format!("inflate failed: {e:?}")))),
            Err(_) => return Some(Ok(total)),
        }
    }
}

/// Streaming PNG predictor (10–15): undoes the per-row filter of complete
/// rows and passes the unfiltered rows on.
struct PngRows {
    bpp: usize,
    row_len: usize,
    prev: Vec<u8>,
    pending: Vec<u8>,
}

impl PngRows {
    fn new(params: &Dict<'_>) -> Option<Self> {
        let predictor = params.get::<i64>(b"Predictor").unwrap_or(1);
        if !(10..=15).contains(&predictor) {
            return None;
        }
        let colors = params.get::<i64>(b"Colors").unwrap_or(1).clamp(1, 32) as usize;
        let bpc = params.get::<i64>(b"BitsPerComponent").unwrap_or(8).clamp(1, 16) as usize;
        let columns = params.get::<i64>(b"Columns").unwrap_or(1).clamp(1, 1 << 20) as usize;
        let row_len = (colors * bpc * columns).div_ceil(8);
        Some(Self { bpp: (colors * bpc).div_ceil(8).max(1), row_len, prev: vec![0; row_len], pending: Vec::new() })
    }

    fn feed(&mut self, mut data: &[u8], sink: &mut impl FnMut(&[u8]) -> bool) -> bool {
        let full = self.row_len + 1;
        while !data.is_empty() {
            let take = (full - self.pending.len()).min(data.len());
            self.pending.extend_from_slice(&data[..take]);
            data = &data[take..];
            if self.pending.len() < full {
                break;
            }
            let kind = self.pending[0];
            let mut row: Vec<u8> = self.pending[1..].to_vec();
            self.pending.clear();
            for i in 0..row.len() {
                let left = if i >= self.bpp { row[i - self.bpp] } else { 0 };
                let up = self.prev[i];
                let up_left = if i >= self.bpp { self.prev[i - self.bpp] } else { 0 };
                row[i] = match kind {
                    1 => row[i].wrapping_add(left),
                    2 => row[i].wrapping_add(up),
                    3 => row[i].wrapping_add(((u16::from(left) + u16::from(up)) / 2) as u8),
                    4 => row[i].wrapping_add(paeth(left, up, up_left)),
                    _ => row[i],
                };
            }
            if !sink(&row) {
                return false;
            }
            self.prev = row;
        }
        true
    }
}

/// Upper bound check for streams decoded *inside* hayro-syntax (object and
/// cross-reference streams), used by `guard::scan`.
pub fn inflated_size_exceeds(input: &[u8], limit: usize) -> Option<bool> {
    let zlib = input.len() >= 2 && (input[0] & 0x0f) == 8 && (u16::from(input[0]) << 8 | u16::from(input[1])) % 31 == 0;
    let mut state = InflateState::new_boxed(if zlib { DataFormat::Zlib } else { DataFormat::Raw });
    let mut buf = vec![0u8; 64 * 1024];
    let (mut pos, mut total) = (0usize, 0usize);
    loop {
        let res = inflate(&mut state, &input[pos..], &mut buf, MZFlush::None);
        pos += res.bytes_consumed;
        total += res.bytes_written;
        if total > limit {
            return Some(true);
        }
        match res.status {
            Ok(MZStatus::StreamEnd) => return Some(false),
            Ok(_) if res.bytes_consumed == 0 && res.bytes_written == 0 => return Some(false),
            Ok(_) => {}
            Err(MZError::Buf) => return Some(false),
            // Not valid deflate data (e.g. encrypted): cannot be verified.
            Err(_) => return if total == 0 { None } else { Some(false) },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn inflate_respects_limit() {
        let zeros = vec![0u8; 1_000_000];
        let compressed = miniz_oxide::deflate::compress_to_vec_zlib(&zeros, 9);
        assert_eq!(inflate_bounded(&compressed, 2_000_000, false).unwrap().len(), 1_000_000);
        assert_eq!(inflate_bounded(&compressed, 1000, false), Err(DecodeError::LimitExceeded { limit: 1000 }));
        assert_eq!(inflate_bounded(&compressed, 1000, true).unwrap().len(), 1000);
        assert_eq!(inflated_size_exceeds(&compressed, 999_999), Some(true));
        assert_eq!(inflated_size_exceeds(&compressed, 1_000_000), Some(false));
    }

    #[test]
    fn inflate_raw_deflate() {
        let data = b"BT /F1 12 Tf (Hello) Tj ET".repeat(10);
        let compressed = miniz_oxide::deflate::compress_to_vec(&data, 6);
        assert_eq!(inflate_bounded(&compressed, 1 << 20, false).unwrap(), data);
    }

    #[test]
    fn ascii_filters() {
        assert_eq!(ascii_hex_decode(b"48 65 6C6C6F>", 100).unwrap(), b"Hello");
        assert_eq!(ascii_hex_decode(b"4>", 100).unwrap(), vec![0x40]);
        assert_eq!(ascii85_decode(b"<~87cURD]i,\"Ebo80~>", 100).unwrap(), b"Hello World!");
        // Final partial group (11 bytes).
        assert_eq!(ascii85_decode(b"87cURD]i,\"Ebo7~>", 100).unwrap(), b"Hello World");
        assert_eq!(ascii85_decode(b"z~>", 100).unwrap(), vec![0, 0, 0, 0]);
    }

    #[test]
    fn run_length_and_limit() {
        // 0x02 -> copy 3 literal bytes, 0xFE -> repeat next byte 3 times
        assert_eq!(run_length_decode(&[2, b'a', b'b', b'c', 0xFE, b'x', 128], 100).unwrap(), b"abcxxx");
        assert!(matches!(run_length_decode(&[129, b'x'], 10), Err(DecodeError::LimitExceeded { .. })));
    }

    #[test]
    fn streaming_png_predictor_matches_batch() {
        // Rows of 3 bytes, filter types Sub (1), Up (2), Paeth (4).
        let filtered = [1u8, 10, 5, 5, 2, 1, 1, 1, 4, 0, 0, 3];
        let dict = hayro_syntax::reader::Reader::new(b"<< /Predictor 15 /Colors 1 /Columns 3 >>")
            .read_without_context::<Dict<'_>>()
            .unwrap();
        let batch = apply_predictor(filtered.to_vec(), &dict).unwrap();
        let mut rows = PngRows::new(&dict).unwrap();
        let mut streamed = Vec::new();
        for b in filtered {
            rows.feed(&[b], &mut |row: &[u8]| {
                streamed.extend_from_slice(row);
                true
            });
        }
        assert_eq!(streamed, batch);
        assert_eq!(batch, vec![10, 15, 20, 11, 16, 21, 11, 16, 24]);
    }

    #[test]
    fn lzw_spec_example() {
        // Example from the PDF specification (7.4.4.2): encodes "-----A---B".
        let input = [0x80, 0x0B, 0x60, 0x50, 0x22, 0x0C, 0x0C, 0x85, 0x01];
        assert_eq!(lzw_decode(&input, true, 100).unwrap(), b"-----A---B");
    }
}
