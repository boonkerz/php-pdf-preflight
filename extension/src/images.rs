//! Image XObjects and inline images: metadata, effective resolution and
//! colour classification.
//!
//! The effective resolution of a placement is derived from the current
//! transformation matrix: the image's unit square is mapped to a
//! parallelogram whose side lengths (in points, times /UserUnit) give the
//! placed size, so scaling, rotation and skew inside nested forms are all
//! taken into account.

use std::cell::OnceCell;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::rc::Rc;

use hayro_syntax::object::{Array, Dict, Object, Stream};

use crate::colors::{Class, ColorSpace, Family};
use crate::decode::{decode_stream, filter_names, stream_flate};
use crate::value::Value;

/// Pixel tolerance for neutral JPEG pixels after YCbCr -> RGB conversion.
const JPEG_NEUTRAL_TOLERANCE: i16 = 3;

#[derive(Debug)]
pub struct ImageInfo {
    pub object: Option<String>,
    pub inline: bool,
    pub width: u64,
    pub height: u64,
    pub bits_per_component: Option<u32>,
    /// `None` for image masks and JPX images without explicit colour space.
    pub color_space: Option<Rc<ColorSpace>>,
    pub image_mask: bool,
    pub filters: Vec<String>,
    pub compression: &'static str,
    pub has_soft_mask: bool,
    pub has_mask: bool,
    decode: Option<Vec<f64>>,
    /// Colour classification, computed lazily (pixel checks are expensive).
    pub class: OnceCell<(Class, Option<String>)>,
}

/// Looks up `long` or its inline-image abbreviation `short`.
macro_rules! get_alias {
    ($dict:expr, $t:ty, $long:expr, $short:expr) => {
        $dict.get::<$t>($long).or_else(|| $dict.get::<$t>($short))
    };
}

pub fn compression_of(filters: &[String]) -> &'static str {
    match filters.last().map(String::as_str) {
        None => "none",
        Some("DCTDecode") => "jpeg",
        Some("JPXDecode") => "jpeg2000",
        Some("JBIG2Decode") => "jbig2",
        Some("CCITTFaxDecode") => "ccitt",
        Some("FlateDecode") => "flate",
        Some("LZWDecode") => "lzw",
        Some("RunLengthDecode") => "runlength",
        Some(_) => "other",
    }
}

impl ImageInfo {
    /// `color_space` resolves the `/ColorSpace` (or `/CS`) entry; the caller
    /// handles resource lookups for names.
    pub fn new<'a>(
        dict: &Dict<'a>,
        object: Option<String>,
        inline: bool,
        color_space: impl FnOnce(Object<'a>) -> Rc<ColorSpace>,
    ) -> Self {
        let image_mask = get_alias!(dict, bool, b"ImageMask", b"IM").unwrap_or(false);
        let filters = filter_names(dict);
        let cs_obj = get_alias!(dict, Object<'a>, b"ColorSpace", b"CS");
        let color_space = if image_mask { None } else { cs_obj.map(color_space) };
        let decode = get_alias!(dict, Array<'a>, b"Decode", b"D")
            .map(|a| a.iter::<Object<'_>>().filter_map(|o| o.into_number().map(|n| n.as_f64())).collect());
        Self {
            object,
            inline,
            width: get_alias!(dict, i64, b"Width", b"W").unwrap_or(0).max(0) as u64,
            height: get_alias!(dict, i64, b"Height", b"H").unwrap_or(0).max(0) as u64,
            bits_per_component: get_alias!(dict, u32, b"BitsPerComponent", b"BPC").or(image_mask.then_some(1)),
            color_space,
            image_mask,
            compression: compression_of(&filters),
            filters,
            has_soft_mask: dict.contains_key(b"SMask"),
            has_mask: dict.contains_key(b"Mask"),
            decode,
            class: OnceCell::new(),
        }
    }

    /// Classification from metadata only; `None` if the pixels must be checked.
    pub fn class_from_metadata(&self) -> Option<(Class, Option<String>)> {
        let Some(cs) = &self.color_space else {
            return Some((Class::Unknown, Some(format!("{} image without explicit colour space", self.describe()))));
        };
        match cs.category() {
            "gray" => return Some((Class::Gray, None)),
            "pattern" | "unknown" => {
                return Some((
                    Class::Unknown,
                    Some(format!("{} image in colour space {}", self.describe(), cs.family.name())),
                ));
            }
            _ => {}
        }
        match &cs.family {
            Family::Separation => Some((cs.classify(&[1.0]), None)),
            Family::Indexed => match cs.palette_class() {
                Some(Class::Gray) => Some((Class::Gray, None)),
                Some(Class::NoInk) => Some((Class::NoInk, None)),
                _ => None,
            },
            _ => None,
        }
    }

    pub fn describe(&self) -> String {
        match &self.object {
            Some(obj) => format!("{} {obj}", self.compression),
            None => format!("inline {}", self.compression),
        }
    }

    /// Checks the pixels for colour. `max_pixels` and `max_bytes` bound the
    /// work; returns the class, an optional reason for `Unknown` and the
    /// number of bytes decoded.
    pub fn classify_pixels(
        &self,
        stream: &Stream<'_>,
        max_pixels: u64,
        max_bytes: usize,
    ) -> (Class, Option<String>, usize) {
        let Some(cs) = self.color_space.clone() else { return (Class::Unknown, None, 0) };
        let pixels = self.width.saturating_mul(self.height);
        let unknown = |why: &str| (Class::Unknown, Some(format!("{}: {why}", self.describe())), 0);
        if pixels == 0 {
            return unknown("empty image");
        }
        if pixels > max_pixels {
            return unknown(&format!("{pixels} pixels exceed the colour check limit 'max_color_check_pixels'"));
        }
        match self.compression {
            "jpeg" if self.filters.len() == 1 => {
                if cs.category() != "rgb" {
                    return unknown(&format!("colour check of {} JPEG images is not supported", cs.category()));
                }
                let data = stream.raw_data();
                let (class, reason) = jpeg_class(&data, max_pixels);
                return (class, reason.map(|r| format!("{}: {r}", self.describe())), (pixels * 3) as usize);
            }
            "jpeg" | "jpeg2000" | "jbig2" | "ccitt" | "other" => {
                return unknown("colour check is not supported for this compression");
            }
            _ => {}
        }
        let Some(bpc) = self.bits_per_component.filter(|b| matches!(b, 1 | 2 | 4 | 8 | 16)) else {
            return unknown("unsupported bits per component");
        };
        let n = cs.n.max(1);
        if n > 32 {
            return unknown("too many colour components");
        }
        let row_bytes = (self.width as usize * n * bpc as usize).div_ceil(8);
        let expected = row_bytes.saturating_mul(self.height as usize);
        if expected > max_bytes {
            return unknown("decoded image exceeds the stream size limit");
        }
        let limit = expected.saturating_add(1024).min(max_bytes);
        let mut classifier =
            SampleClassifier::new(&cs, self.width as usize, self.height as usize, bpc, self.decode.as_deref());
        // Stream the pixels where possible, so that coloured images stop at
        // the first coloured pixel instead of being decoded completely.
        let decoded = match stream_flate(stream, limit, |chunk| classifier.feed(chunk)) {
            Some(result) => result,
            None => decode_stream(stream, limit).map(|data| {
                classifier.feed(&data);
                data.len()
            }),
        };
        match decoded {
            Ok(bytes) => (classifier.finish(), None, bytes),
            Err(e) => unknown(&format!("image data cannot be decoded ({e})")),
        }
    }

    pub fn to_value(&self) -> Value {
        let class = self.class.get();
        Value::map()
            .with("object", self.object.clone())
            .with("inline", self.inline)
            .with("width_px", self.width)
            .with("height_px", self.height)
            .with("bits_per_component", self.bits_per_component.map(i64::from))
            .with("color_space", self.color_space.as_ref().map(|c| c.family.name().to_string()))
            .with("color_category", self.color_space.as_ref().map(|c| c.category().to_string()))
            .with("components", self.color_space.as_ref().map(|c| c.n))
            .with("image_mask", self.image_mask)
            .with("filters", self.filters.clone())
            .with("compression", self.compression)
            .with("has_soft_mask", self.has_soft_mask)
            .with("has_mask", self.has_mask)
            .with("color_class", class.map(|(c, _)| c.as_str().to_string()))
    }
}

/// Classifies pixels row by row from data fed in arbitrary chunks; stops at
/// the first coloured pixel.
struct SampleClassifier<'c> {
    cs: &'c ColorSpace,
    width: usize,
    rows_left: usize,
    bpc: u32,
    n: usize,
    max_val: f64,
    decode: Vec<f64>,
    row_bytes: usize,
    pending: Vec<u8>,
    class: Class,
}

impl<'c> SampleClassifier<'c> {
    fn new(cs: &'c ColorSpace, width: usize, height: usize, bpc: u32, decode: Option<&[f64]>) -> Self {
        let n = cs.n.max(1);
        let max_val = ((1u32 << bpc) - 1) as f64;
        // Default Decode arrays: [0 maxval] for Indexed, Lab ranges, [0 1] otherwise.
        let default: Vec<f64> = if matches!(cs.family, Family::Indexed) {
            vec![0.0, max_val]
        } else if matches!(cs.family, Family::Lab) {
            vec![0.0, 100.0, -100.0, 100.0, -100.0, 100.0]
        } else {
            [0.0, 1.0].repeat(n)
        };
        let decode = decode.filter(|d| d.len() >= 2 * n).map_or(default, <[f64]>::to_vec);
        Self {
            cs,
            width,
            rows_left: height,
            bpc,
            n,
            max_val,
            decode,
            row_bytes: (width * n * bpc as usize).div_ceil(8).max(1),
            pending: Vec::new(),
            class: Class::NoInk,
        }
    }

    /// Returns false once the result is decided (colour found or all rows seen).
    fn feed(&mut self, mut data: &[u8]) -> bool {
        if !self.pending.is_empty() {
            let need = self.row_bytes - self.pending.len();
            let take = need.min(data.len());
            self.pending.extend_from_slice(&data[..take]);
            data = &data[take..];
            if self.pending.len() < self.row_bytes {
                return true;
            }
            let row = std::mem::take(&mut self.pending);
            if !self.row(&row) {
                return false;
            }
        }
        while data.len() >= self.row_bytes {
            let (row, rest) = data.split_at(self.row_bytes);
            if !self.row(row) {
                return false;
            }
            data = rest;
        }
        self.pending.extend_from_slice(data);
        true
    }

    fn row(&mut self, row: &[u8]) -> bool {
        if self.rows_left == 0 {
            return false;
        }
        self.rows_left -= 1;
        let mut values = [0.0f64; 32];
        let mut bit = 0usize;
        for _ in 0..self.width {
            for (c, value) in values.iter_mut().enumerate().take(self.n) {
                let Some(raw) = read_sample(row, bit, self.bpc) else {
                    self.class = self.class.combine(Class::Unknown);
                    return false;
                };
                bit += self.bpc as usize;
                let (dmin, dmax) = (self.decode[2 * c], self.decode[2 * c + 1]);
                *value = dmin + f64::from(raw) * (dmax - dmin) / self.max_val;
            }
            self.class = self.class.combine(self.cs.classify(&values[..self.n]));
            if self.class == Class::Color {
                return false;
            }
        }
        self.rows_left > 0
    }

    fn finish(self) -> Class {
        // Truncated data: rows that never arrived cannot be classified.
        if self.rows_left > 0 && self.class != Class::Color { self.class.combine(Class::Unknown) } else { self.class }
    }
}

/// Classifies a complete sample buffer (used by tests).
#[cfg(test)]
fn classify_samples(
    data: &[u8],
    cs: &ColorSpace,
    width: usize,
    height: usize,
    bpc: u32,
    decode: Option<&[f64]>,
) -> Class {
    let mut c = SampleClassifier::new(cs, width, height, bpc, decode);
    c.feed(data);
    c.finish()
}

fn read_sample(row: &[u8], bit: usize, bpc: u32) -> Option<u32> {
    match bpc {
        8 => row.get(bit / 8).map(|b| u32::from(*b)),
        16 => {
            let i = bit / 8;
            Some(u32::from(u16::from_be_bytes([*row.get(i)?, *row.get(i + 1)?])))
        }
        _ => {
            let byte = *row.get(bit / 8)?;
            let shift = 8 - bpc - (bit % 8) as u32;
            Some(u32::from(byte >> shift) & ((1 << bpc) - 1))
        }
    }
}

/// Decodes a YCbCr/RGB JPEG and checks whether all pixels are neutral.
fn jpeg_class(data: &[u8], max_pixels: u64) -> (Class, Option<String>) {
    use zune_jpeg::JpegDecoder;
    use zune_jpeg::zune_core::bytestream::ZCursor;
    use zune_jpeg::zune_core::colorspace::ColorSpace as ZColorSpace;
    use zune_jpeg::zune_core::options::DecoderOptions;

    let result = catch_unwind(AssertUnwindSafe(|| -> Result<(Class, Option<String>), String> {
        let options = DecoderOptions::default().jpeg_set_out_colorspace(ZColorSpace::RGB);
        let mut decoder = JpegDecoder::new_with_options(ZCursor::new(data), options);
        decoder.decode_headers().map_err(|e| format!("JPEG header: {e:?}"))?;
        let info = decoder.info().ok_or("JPEG header missing")?;
        if u64::from(info.width) * u64::from(info.height) > max_pixels {
            return Ok((Class::Unknown, Some("JPEG exceeds the colour check pixel limit".into())));
        }
        match decoder.input_colorspace() {
            Some(ZColorSpace::Luma) => return Ok((Class::Gray, None)),
            Some(ZColorSpace::YCbCr | ZColorSpace::RGB) => {}
            other => return Ok((Class::Unknown, Some(format!("JPEG colour space {other:?} is not supported")))),
        }
        let pixels = decoder.decode().map_err(|e| format!("JPEG data: {e:?}"))?;
        if decoder.options().jpeg_get_out_colorspace() != ZColorSpace::RGB {
            return Ok((Class::Unknown, Some("JPEG could not be converted to RGB".into())));
        }
        for px in pixels.as_chunks::<3>().0 {
            let (r, g, b) = (i16::from(px[0]), i16::from(px[1]), i16::from(px[2]));
            if (r - g).abs() > JPEG_NEUTRAL_TOLERANCE || (g - b).abs() > JPEG_NEUTRAL_TOLERANCE {
                return Ok((Class::Color, None));
            }
        }
        Ok((Class::Gray, None))
    }));
    match result {
        Ok(Ok(r)) => r,
        Ok(Err(e)) => (Class::Unknown, Some(e)),
        Err(_) => (Class::Unknown, Some("JPEG decoder failed".into())),
    }
}

/// Placement of an image on a page, aggregated over identical placements.
#[derive(Debug, Clone)]
pub struct Placement {
    pub image: Rc<ImageInfo>,
    pub dpi_x: f64,
    pub dpi_y: f64,
    pub width_mm: f64,
    pub height_mm: f64,
    /// Bounding box on the page in default user space units.
    pub bbox: [f64; 4],
    /// Whether the placement intersects the page's CropBox.
    pub on_page: bool,
    pub count: u64,
    pub contexts: std::collections::BTreeSet<&'static str>,
}

impl Placement {
    pub fn to_value(&self) -> Value {
        let round = |v: f64| (v * 100.0).round() / 100.0;
        let mut v = self.image.to_value();
        if let Value::Map(entries) = &mut v {
            entries.push(("effective_dpi_x".into(), round(self.dpi_x).into()));
            entries.push(("effective_dpi_y".into(), round(self.dpi_y).into()));
            entries.push(("effective_dpi".into(), round(self.dpi_x.min(self.dpi_y)).into()));
            entries.push(("placed_width_mm".into(), round(self.width_mm).into()));
            entries.push(("placed_height_mm".into(), round(self.height_mm).into()));
            entries.push(("bbox".into(), Value::List(self.bbox.iter().map(|v| Value::Float(round(*v))).collect())));
            entries.push(("on_page".into(), self.on_page.into()));
            entries.push(("placements".into(), self.count.into()));
            entries.push(("contexts".into(), self.contexts.iter().copied().collect::<Vec<_>>().into()));
        }
        v
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sample_unpacking() {
        let rgb = ColorSpace::simple(Family::DeviceRgb, 3);
        assert_eq!(classify_samples(&[10, 10, 10, 200, 200, 200], &rgb, 2, 1, 8, None), Class::Gray);
        assert_eq!(classify_samples(&[10, 10, 10, 200, 0, 0], &rgb, 2, 1, 8, None), Class::Color);
        let cmyk = ColorSpace::simple(Family::DeviceCmyk, 4);
        assert_eq!(classify_samples(&[0, 0, 0, 255], &cmyk, 1, 1, 8, None), Class::Gray);
        // Inverted Decode turns 255 into 0 ink.
        let inverted = [1.0, 0.0, 1.0, 0.0, 1.0, 0.0, 1.0, 0.0];
        assert_eq!(classify_samples(&[255, 255, 255, 0], &cmyk, 1, 1, 8, Some(&inverted)), Class::Gray);
        // 4-bit RGB, rows padded to whole bytes
        assert_eq!(classify_samples(&[0x55, 0x50, 0x33, 0x30], &rgb, 1, 2, 4, None), Class::Gray);
        assert_eq!(classify_samples(&[0x12, 0x30], &rgb, 1, 1, 4, None), Class::Color);
    }

    #[test]
    fn chunked_feeding() {
        let rgb = ColorSpace::simple(Family::DeviceRgb, 3);
        let gray_rows = [7u8, 7, 7, 9, 9, 9, 1, 1, 1, 2, 2, 2];
        let mut c = SampleClassifier::new(&rgb, 2, 2, 8, None);
        for (i, b) in gray_rows.iter().enumerate() {
            // The last byte completes the last row: the result is decided.
            assert_eq!(c.feed(&[*b]), i + 1 < gray_rows.len());
        }
        assert_eq!(c.finish(), Class::Gray);
        // Stops at the first coloured pixel.
        let mut c = SampleClassifier::new(&rgb, 2, 2, 8, None);
        assert!(!c.feed(&[7, 7, 7, 9, 0, 0, 1]));
        assert_eq!(c.finish(), Class::Color);
        // Truncated data cannot be classified as gray.
        let mut c = SampleClassifier::new(&rgb, 2, 2, 8, None);
        c.feed(&[7, 7, 7, 9, 9, 9]);
        assert_eq!(c.finish(), Class::Unknown);
    }

    #[test]
    fn sample_reader() {
        assert_eq!(read_sample(&[0b1011_0000], 0, 1), Some(1));
        assert_eq!(read_sample(&[0b1011_0000], 1, 1), Some(0));
        assert_eq!(read_sample(&[0b1011_0000], 0, 2), Some(2));
        assert_eq!(read_sample(&[0x12, 0x34], 0, 16), Some(0x1234));
        assert_eq!(read_sample(&[0x12], 8, 8), None);
    }
}
