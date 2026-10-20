//! Decoding of PDF text strings and dates.

/// PDFDocEncoding code points 0x80–0x9F (ISO 32000-2, Annex D). Other bytes
/// map to the same Unicode code point (Latin-1), except for the undefined
/// 0x7F and 0xAD, which are replaced.
const PDF_DOC_80_9F: [char; 32] = [
    '\u{2022}', '\u{2020}', '\u{2021}', '\u{2026}', '\u{2014}', '\u{2013}', '\u{0192}', '\u{2044}', '\u{2039}',
    '\u{203A}', '\u{2212}', '\u{2030}', '\u{201E}', '\u{201C}', '\u{201D}', '\u{2018}', '\u{2019}', '\u{201A}',
    '\u{2122}', '\u{FB01}', '\u{FB02}', '\u{0141}', '\u{0152}', '\u{0160}', '\u{0178}', '\u{017D}', '\u{0131}',
    '\u{0142}', '\u{0153}', '\u{0161}', '\u{017E}', '\u{FFFD}',
];

const PDF_DOC_18_1F: [char; 8] =
    ['\u{02D8}', '\u{02C7}', '\u{02C6}', '\u{02D9}', '\u{02DD}', '\u{02DB}', '\u{02DA}', '\u{02DC}'];

/// Decodes a PDF text string (UTF-16BE with BOM, UTF-8 with BOM, or
/// PDFDocEncoding) into a Rust string. Invalid sequences become U+FFFD.
pub fn decode_text_string(bytes: &[u8]) -> String {
    if let Some(rest) = bytes.strip_prefix(&[0xFE, 0xFF]) {
        let units: Vec<u16> = rest.as_chunks::<2>().0.iter().map(|c| u16::from_be_bytes(*c)).collect();
        return String::from_utf16_lossy(&units);
    }
    if let Some(rest) = bytes.strip_prefix(&[0xEF, 0xBB, 0xBF]) {
        return String::from_utf8_lossy(rest).into_owned();
    }
    bytes
        .iter()
        .map(|&b| match b {
            0x18..=0x1F => PDF_DOC_18_1F[usize::from(b - 0x18)],
            0x80..=0x9F => PDF_DOC_80_9F[usize::from(b - 0x80)],
            0x7F | 0xAD => '\u{FFFD}',
            _ => char::from(b),
        })
        .collect()
}

/// Converts a PDF date (`D:YYYYMMDDHHmmSSOHH'mm`) into ISO 8601. Missing
/// trailing components default as specified (month/day 01, time 00).
/// Returns `None` for strings that are not PDF dates.
pub fn pdf_date_to_iso(raw: &str) -> Option<String> {
    let s = raw.trim().strip_prefix("D:").unwrap_or(raw.trim());
    let digits: String = s.chars().take_while(char::is_ascii_digit).collect();
    if digits.len() < 4 {
        return None;
    }
    let field = |start: usize, len: usize, default: u32| -> u32 {
        digits.get(start..start + len).and_then(|v| v.parse().ok()).unwrap_or(default)
    };
    let (year, month, day) = (field(0, 4, 0), field(4, 2, 1), field(6, 2, 1));
    let (hour, minute, second) = (field(8, 2, 0), field(10, 2, 0), field(12, 2, 0));
    if !(1..=12).contains(&month) || !(1..=31).contains(&day) || hour > 23 || minute > 59 || second > 60 {
        return None;
    }

    let rest = &s[digits.len()..];
    let tz = match rest.chars().next() {
        Some('Z') | None => {
            if rest.is_empty() {
                String::new()
            } else {
                "Z".to_string()
            }
        }
        Some(sign @ ('+' | '-')) => {
            let tz_digits: String = rest[1..].chars().filter(char::is_ascii_digit).collect();
            let h = tz_digits.get(0..2).and_then(|v| v.parse::<u32>().ok()).unwrap_or(0);
            let m = tz_digits.get(2..4).and_then(|v| v.parse::<u32>().ok()).unwrap_or(0);
            format!("{sign}{h:02}:{m:02}")
        }
        _ => String::new(),
    };

    Some(format!("{year:04}-{month:02}-{day:02}T{hour:02}:{minute:02}:{second:02}{tz}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn text_strings() {
        assert_eq!(decode_text_string(b"Hello"), "Hello");
        assert_eq!(decode_text_string(&[0xFE, 0xFF, 0x00, 0x48, 0x00, 0xE4]), "Hä");
        assert_eq!(decode_text_string(&[0x84, 0xE4]), "—ä");
        assert_eq!(decode_text_string(&[0xEF, 0xBB, 0xBF, 0xC3, 0xA4]), "ä");
    }

    #[test]
    fn dates() {
        assert_eq!(pdf_date_to_iso("D:20240131120000+01'00'").as_deref(), Some("2024-01-31T12:00:00+01:00"));
        assert_eq!(pdf_date_to_iso("D:2024").as_deref(), Some("2024-01-01T00:00:00"));
        assert_eq!(pdf_date_to_iso("D:20240131235959Z").as_deref(), Some("2024-01-31T23:59:59Z"));
        assert_eq!(pdf_date_to_iso("garbage"), None);
    }
}
