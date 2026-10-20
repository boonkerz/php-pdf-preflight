//! Font dictionaries: type, subset and embedding status.
//!
//! Embedding is not inferred from the mere presence of a `FontDescriptor`:
//! the referenced font program stream must resolve, decode and carry a
//! signature that matches a known font format.

use hayro_syntax::object::{Array, Dict, Name, Object, ObjectIdentifier, Stream};

use crate::decode::decode_prefix;
use crate::value::Value;

const STANDARD_14: [&str; 14] = [
    "Times-Roman",
    "Times-Bold",
    "Times-Italic",
    "Times-BoldItalic",
    "Helvetica",
    "Helvetica-Bold",
    "Helvetica-Oblique",
    "Helvetica-BoldOblique",
    "Courier",
    "Courier-Bold",
    "Courier-Oblique",
    "Courier-BoldOblique",
    "Symbol",
    "ZapfDingbats",
];

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Embedding {
    Embedded,
    NotEmbedded,
    /// A font program is referenced but missing, empty or undecodable.
    Invalid,
}

impl Embedding {
    pub fn as_str(&self) -> &'static str {
        match self {
            Self::Embedded => "embedded",
            Self::NotEmbedded => "not_embedded",
            Self::Invalid => "invalid",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct FontInfo {
    /// Object reference ("12 0 R") or `None` for direct font dictionaries.
    pub object: Option<String>,
    /// `/BaseFont` as written (including a subset prefix).
    pub base_font: String,
    /// `base_font` without the subset prefix.
    pub name: String,
    /// `/Subtype` of the font dictionary (Type0, Type1, MMType1, TrueType, Type3).
    pub font_type: String,
    /// `/Subtype` of the descendant font for Type0 fonts (CIDFontType0/2).
    pub descendant_type: Option<String>,
    pub subset: bool,
    pub standard14: bool,
    pub embedding: Embedding,
    /// Key of the font program in the descriptor: FontFile, FontFile2, FontFile3.
    pub font_file: Option<&'static str>,
    /// `/Subtype` of a FontFile3 stream (Type1C, CIDFontType0C, OpenType).
    pub font_file_subtype: Option<String>,
    /// Format detected from the font program signature.
    pub program_format: Option<&'static str>,
    /// Human readable problems found while inspecting the font.
    pub problems: Vec<String>,
}

impl FontInfo {
    pub fn is_embedded(&self) -> bool {
        self.embedding == Embedding::Embedded
    }

    pub fn to_value(&self) -> Value {
        Value::map()
            .with("object", self.object.clone())
            .with("name", self.name.as_str())
            .with("base_font", self.base_font.as_str())
            .with("type", self.font_type.as_str())
            .with("descendant_type", self.descendant_type.clone())
            .with("embedded", self.is_embedded())
            .with("embedding", self.embedding.as_str())
            .with("subset", self.subset)
            .with("standard14", self.standard14)
            .with("font_file", self.font_file)
            .with("font_file_subtype", self.font_file_subtype.clone())
            .with("program_format", self.program_format)
            .with("problems", self.problems.clone())
    }
}

fn name_of(dict: &Dict<'_>, key: &[u8]) -> Option<String> {
    dict.get::<Name<'_>>(key).map(|n| n.as_str().to_string())
}

/// Subset fonts are named `ABCDEF+FontName` (six upper-case letters).
fn split_subset_prefix(base_font: &str) -> (bool, &str) {
    let b = base_font.as_bytes();
    if b.len() > 7 && b[6] == b'+' && b[..6].iter().all(u8::is_ascii_uppercase) {
        (true, &base_font[7..])
    } else {
        (false, base_font)
    }
}

/// `id` is the object identifier of the font dictionary if it is an indirect
/// object (note that `Dict::obj_id()` reports the *containing* object for
/// direct dictionaries, so the caller must determine this).
pub fn analyze_font(font: &Dict<'_>, id: Option<ObjectIdentifier>) -> FontInfo {
    // Type3 fonts have no /BaseFont; /Name is optional there.
    let base_font = name_of(font, b"BaseFont").or_else(|| name_of(font, b"Name")).unwrap_or_default();
    let (subset, name) = split_subset_prefix(&base_font);
    let name = name.to_string();
    let font_type = name_of(font, b"Subtype").unwrap_or_else(|| "Unknown".to_string());
    let mut info = FontInfo {
        object: id.map(|id| format!("{} {} R", id.obj_number, id.gen_number)),
        standard14: STANDARD_14.contains(&name.as_str()),
        base_font,
        name,
        font_type,
        descendant_type: None,
        subset,
        embedding: Embedding::NotEmbedded,
        font_file: None,
        font_file_subtype: None,
        program_format: None,
        problems: Vec::new(),
    };

    if info.font_type == "Type3" {
        // Glyphs are content streams inside the PDF.
        info.embedding = Embedding::Embedded;
        info.program_format = Some("type3");
        return info;
    }

    let descriptor_owner = if info.font_type == "Type0" {
        let descendant = font
            .get::<Array<'_>>(b"DescendantFonts")
            .and_then(|a| a.iter::<Object<'_>>().next())
            .and_then(Object::into_dict);
        match descendant {
            Some(d) => {
                info.descendant_type = name_of(&d, b"Subtype");
                d
            }
            None => {
                info.embedding = Embedding::Invalid;
                info.problems.push("Type0 font without a valid DescendantFonts entry".into());
                return info;
            }
        }
    } else {
        font.clone()
    };

    let Some(descriptor) = descriptor_owner.get::<Dict<'_>>(b"FontDescriptor") else {
        if descriptor_owner.contains_key(b"FontDescriptor") {
            info.embedding = Embedding::Invalid;
            info.problems.push("FontDescriptor cannot be resolved".into());
        }
        return info;
    };

    for key in ["FontFile", "FontFile2", "FontFile3"] {
        if !descriptor.contains_key(key.as_bytes()) {
            continue;
        }
        info.font_file = Some(key);
        let Some(stream) = descriptor.get::<Stream<'_>>(key.as_bytes()) else {
            info.embedding = Embedding::Invalid;
            info.problems.push(format!("{key} does not resolve to a stream"));
            return info;
        };
        info.font_file_subtype = name_of(stream.dict(), b"Subtype");
        inspect_program(&stream, key, &mut info);
        return info;
    }

    info
}

fn inspect_program(stream: &Stream<'_>, key: &str, info: &mut FontInfo) {
    let header = match decode_prefix(stream, 64) {
        Ok(h) => h,
        Err(e) => {
            info.embedding = Embedding::Invalid;
            info.problems.push(format!("{key} cannot be decoded: {e}"));
            return;
        }
    };
    if header.is_empty() {
        info.embedding = Embedding::Invalid;
        info.problems.push(format!("{key} is empty"));
        return;
    }

    info.embedding = Embedding::Embedded;
    let format = detect_format(&header);
    info.program_format = Some(format);

    let expected: &[&str] = match (key, info.font_file_subtype.as_deref()) {
        ("FontFile", _) => &["type1"],
        ("FontFile2", _) => &["truetype"],
        ("FontFile3", Some("OpenType")) => &["opentype", "truetype"],
        ("FontFile3", _) => &["cff"],
        _ => &[],
    };
    if format == "unknown" {
        info.problems.push(format!("{key} does not start with a known font program signature"));
    } else if !expected.contains(&format) {
        info.problems.push(format!("{key} contains a {format} font program"));
    }
}

fn detect_format(h: &[u8]) -> &'static str {
    if h.starts_with(b"%!PS-AdobeFont") || h.starts_with(b"%!FontType1") || h.starts_with(&[0x80, 0x01]) {
        "type1"
    } else if h.starts_with(&[0x00, 0x01, 0x00, 0x00]) || h.starts_with(b"true") || h.starts_with(b"ttcf") {
        "truetype"
    } else if h.starts_with(b"OTTO") {
        "opentype"
    } else if h.len() >= 4 && h[0] == 1 && h[2] >= 4 && (1..=4).contains(&h[3]) {
        // CFF header: major version 1, header size >= 4, offSize 1..4
        "cff"
    } else {
        "unknown"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn subset_prefix() {
        assert_eq!(split_subset_prefix("ABCDEF+Arial"), (true, "Arial"));
        assert_eq!(split_subset_prefix("AbCDEF+Arial"), (false, "AbCDEF+Arial"));
        assert_eq!(split_subset_prefix("Helvetica"), (false, "Helvetica"));
    }

    #[test]
    fn signatures() {
        assert_eq!(detect_format(b"%!PS-AdobeFont-1.0"), "type1");
        assert_eq!(detect_format(&[0, 1, 0, 0, 0, 12]), "truetype");
        assert_eq!(detect_format(b"OTTO\0\x0a"), "opentype");
        assert_eq!(detect_format(&[1, 0, 4, 2]), "cff");
        assert_eq!(detect_format(b"garbage"), "unknown");
    }
}
