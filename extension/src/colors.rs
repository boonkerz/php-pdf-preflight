//! Colour spaces and the print classification of colour values.
//!
//! Classification rules (documented in docs/colors.md):
//!
//! * Gray families (DeviceGray, CalGray, ICCBased with N=1) are gray.
//! * RGB-like values are gray if R = G = B, Lab values if a = b = 0.
//! * CMYK-like values are gray only if C = M = Y = 0 (K-only). Rich black
//!   (C = M = Y > 0) needs the colour plates and therefore counts as colour.
//! * Separation/DeviceN colorants Black and All are gray, None paints
//!   nothing, every other colorant (process or spot) with a tint > 0 is colour.
//!
//! Anything that cannot be decided is `Unknown` – it is never guessed from
//! the mere presence of a colour space.

use std::rc::Rc;

use hayro_syntax::object::{Array, Dict, Name, Object, Stream};

use crate::decode::decode_stream;
use crate::value::Value;

/// Tolerance for "equal" colour components (1/255).
pub const NEUTRAL_EPS: f64 = 1.0 / 255.0 + 1e-9;
const LAB_EPS: f64 = 0.5;
const MAX_CS_DEPTH: usize = 8;
const MAX_PALETTE: usize = 256 * 32;

/// Print classification of something painted.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub enum Class {
    /// Paints no ink (e.g. Separation /None, tint 0 of a spot colour).
    NoInk,
    Gray,
    Unknown,
    Color,
}

impl Class {
    pub fn as_str(&self) -> &'static str {
        match self {
            Self::NoInk => "no_ink",
            Self::Gray => "grayscale",
            Self::Unknown => "unknown",
            Self::Color => "color",
        }
    }

    /// Combination of two painted elements: colour dominates, then unknown.
    pub fn combine(self, other: Class) -> Class {
        self.max(other)
    }
}

#[derive(Debug, Clone, PartialEq)]
pub enum Family {
    DeviceGray,
    DeviceRgb,
    DeviceCmyk,
    CalGray,
    CalRgb,
    Lab,
    IccBased,
    Indexed,
    Pattern,
    Separation,
    DeviceN,
    Unknown(String),
}

impl Family {
    pub fn name(&self) -> &str {
        match self {
            Self::DeviceGray => "DeviceGray",
            Self::DeviceRgb => "DeviceRGB",
            Self::DeviceCmyk => "DeviceCMYK",
            Self::CalGray => "CalGray",
            Self::CalRgb => "CalRGB",
            Self::Lab => "Lab",
            Self::IccBased => "ICCBased",
            Self::Indexed => "Indexed",
            Self::Pattern => "Pattern",
            Self::Separation => "Separation",
            Self::DeviceN => "DeviceN",
            Self::Unknown(name) => name,
        }
    }
}

const PROCESS_COLORANTS: [&str; 4] = ["Cyan", "Magenta", "Yellow", "Black"];

#[derive(Debug, Clone)]
pub struct ColorSpace {
    pub family: Family,
    /// Number of colour components of a colour value.
    pub n: usize,
    /// Alternate space (ICCBased, Separation, DeviceN).
    pub alternate: Option<Rc<ColorSpace>>,
    /// Base space (Indexed, uncoloured Pattern).
    pub base: Option<Rc<ColorSpace>>,
    /// Colorant names (Separation, DeviceN).
    pub colorants: Vec<String>,
    /// Indexed lookup table and highest index.
    pub palette: Option<Rc<Vec<u8>>>,
    pub hival: usize,
    /// Object reference of the colour space array / ICC stream.
    pub object: Option<String>,
}

impl ColorSpace {
    pub fn simple(family: Family, n: usize) -> Self {
        Self { family, n, alternate: None, base: None, colorants: Vec::new(), palette: None, hival: 0, object: None }
    }

    pub fn device_gray() -> Rc<Self> {
        Rc::new(Self::simple(Family::DeviceGray, 1))
    }

    /// Process category: gray, rgb, cmyk, lab, spot, pattern or unknown.
    pub fn category(&self) -> &'static str {
        match &self.family {
            Family::DeviceGray | Family::CalGray => "gray",
            Family::DeviceRgb | Family::CalRgb => "rgb",
            Family::DeviceCmyk => "cmyk",
            Family::Lab => "lab",
            Family::IccBased => match self.n {
                1 => "gray",
                3 => "rgb",
                4 => "cmyk",
                _ => "unknown",
            },
            Family::Indexed => self.base.as_ref().map_or("unknown", |b| b.category()),
            Family::Pattern => "pattern",
            Family::Separation | Family::DeviceN => {
                let names: Vec<&str> =
                    self.colorants.iter().map(String::as_str).filter(|c| !matches!(*c, "None" | "All")).collect();
                if names.iter().any(|c| !PROCESS_COLORANTS.contains(c)) {
                    "spot"
                } else if names.iter().any(|c| *c != "Black") {
                    "cmyk"
                } else {
                    "gray"
                }
            }
            Family::Unknown(_) => "unknown",
        }
    }

    /// Spot colorant names (excluding process colorants, All and None).
    pub fn spot_colorants(&self) -> Vec<String> {
        match &self.family {
            Family::Separation | Family::DeviceN => self
                .colorants
                .iter()
                .filter(|c| !PROCESS_COLORANTS.contains(&c.as_str()) && !matches!(c.as_str(), "None" | "All"))
                .cloned()
                .collect(),
            Family::Indexed | Family::Pattern => self.base.as_ref().map(|b| b.spot_colorants()).unwrap_or_default(),
            _ => Vec::new(),
        }
    }

    /// Initial colour after selecting the space with `cs`/`CS`.
    pub fn initial_values(&self) -> Vec<f64> {
        match &self.family {
            Family::DeviceCmyk => vec![0.0, 0.0, 0.0, 1.0],
            Family::IccBased if self.n == 4 => vec![0.0, 0.0, 0.0, 1.0],
            Family::Separation | Family::DeviceN => vec![1.0; self.n],
            Family::Lab => vec![0.0, 0.0, 0.0],
            _ => vec![0.0; self.n],
        }
    }

    /// Classifies one colour value in this space.
    pub fn classify(&self, v: &[f64]) -> Class {
        match &self.family {
            Family::DeviceGray | Family::CalGray => Class::Gray,
            Family::DeviceRgb | Family::CalRgb => classify_rgb(v),
            Family::DeviceCmyk => classify_cmyk(v),
            Family::Lab => match v {
                [_, a, b, ..] if a.abs() <= LAB_EPS && b.abs() <= LAB_EPS => Class::Gray,
                [_, _, _, ..] => Class::Color,
                _ => Class::Unknown,
            },
            Family::IccBased => match self.n {
                1 => Class::Gray,
                3 => classify_rgb(v),
                4 => classify_cmyk(v),
                _ => Class::Unknown,
            },
            Family::Separation | Family::DeviceN => {
                let mut class = Class::NoInk;
                for (i, colorant) in self.colorants.iter().enumerate() {
                    let tint = v.get(i).copied().unwrap_or(1.0);
                    if tint <= NEUTRAL_EPS || colorant == "None" {
                        continue;
                    }
                    let c = if colorant == "Black" || colorant == "All" { Class::Gray } else { Class::Color };
                    class = class.combine(c);
                }
                class
            }
            Family::Indexed => {
                let (Some(base), Some(palette)) = (&self.base, &self.palette) else { return Class::Unknown };
                let index = v.first().map_or(0, |i| i.round().clamp(0.0, self.hival as f64) as usize);
                self.classify_palette_entry(base, palette, index)
            }
            Family::Pattern | Family::Unknown(_) => Class::Unknown,
        }
    }

    fn classify_palette_entry(&self, base: &ColorSpace, palette: &[u8], index: usize) -> Class {
        if matches!(base.family, Family::Lab) {
            return Class::Unknown;
        }
        let n = base.n;
        let Some(entry) = palette.get(index * n..index * n + n) else { return Class::Unknown };
        let mut values = [0.0f64; 32];
        for (i, b) in entry.iter().enumerate().take(32) {
            values[i] = f64::from(*b) / 255.0;
        }
        base.classify(&values[..n.min(32)])
    }

    /// Classifies every palette entry; `Some(Gray)` if all are gray.
    pub fn palette_class(&self) -> Option<Class> {
        let (Some(base), Some(palette)) = (&self.base, &self.palette) else { return None };
        let mut class = Class::NoInk;
        for i in 0..=self.hival {
            class = class.combine(self.classify_palette_entry(base, palette, i));
        }
        Some(class)
    }

    pub fn to_value(&self) -> Value {
        Value::map()
            .with("family", self.family.name())
            .with("category", self.category())
            .with("components", self.n)
            .with("colorants", self.colorants.clone())
            .with("alternate", self.alternate.as_ref().map(|a| a.family.name().to_string()))
            .with("base", self.base.as_ref().map(|b| b.family.name().to_string()))
            .with("object", self.object.clone())
    }
}

fn classify_rgb(v: &[f64]) -> Class {
    match v {
        [r, g, b, ..] => {
            let (min, max) = (r.min(*g).min(*b), r.max(*g).max(*b));
            if max - min <= NEUTRAL_EPS { Class::Gray } else { Class::Color }
        }
        _ => Class::Unknown,
    }
}

fn classify_cmyk(v: &[f64]) -> Class {
    match v {
        [c, m, y, ..] => {
            if *c <= NEUTRAL_EPS && *m <= NEUTRAL_EPS && *y <= NEUTRAL_EPS {
                Class::Gray
            } else {
                Class::Color
            }
        }
        _ => Class::Unknown,
    }
}

/// Resolves colour space objects. Names that are not device colour spaces
/// must be looked up in the resources by the caller first.
pub struct Resolver {
    pub max_palette_decode: usize,
}

impl Resolver {
    pub fn device(name: &[u8]) -> Option<Rc<ColorSpace>> {
        Some(Rc::new(match name {
            b"DeviceGray" | b"G" => ColorSpace::simple(Family::DeviceGray, 1),
            b"DeviceRGB" | b"RGB" => ColorSpace::simple(Family::DeviceRgb, 3),
            b"DeviceCMYK" | b"CMYK" => ColorSpace::simple(Family::DeviceCmyk, 4),
            b"Pattern" => ColorSpace::simple(Family::Pattern, 0),
            _ => return None,
        }))
    }

    /// Resolves a colour space object (name or array).
    pub fn resolve(&self, obj: Object<'_>, object: Option<String>, depth: usize) -> Rc<ColorSpace> {
        if depth > MAX_CS_DEPTH {
            return Rc::new(ColorSpace::simple(Family::Unknown("too deeply nested".into()), 0));
        }
        let mut cs = match obj {
            Object::Name(name) => match Self::device(name.as_ref()) {
                Some(cs) => return cs,
                None => ColorSpace::simple(Family::Unknown(name.as_str().to_string()), 0),
            },
            Object::Array(arr) => self.resolve_array(&arr, depth),
            _ => ColorSpace::simple(Family::Unknown("invalid".into()), 0),
        };
        cs.object = object;
        Rc::new(cs)
    }

    fn resolve_array(&self, arr: &Array<'_>, depth: usize) -> ColorSpace {
        let items: Vec<Object<'_>> = arr.iter::<Object<'_>>().take(8).collect();
        let Some(Object::Name(kind)) = items.first() else {
            return ColorSpace::simple(Family::Unknown("invalid".into()), 0);
        };
        let sub = |i: usize| items.get(i).cloned().map(|o| self.resolve(o, None, depth + 1));
        match kind.as_ref() {
            b"DeviceGray" | b"G" | b"DeviceRGB" | b"RGB" | b"DeviceCMYK" | b"CMYK" => {
                Rc::unwrap_or_clone(Self::device(kind.as_ref()).expect("device name"))
            }
            b"CalGray" => ColorSpace::simple(Family::CalGray, 1),
            b"CalRGB" => ColorSpace::simple(Family::CalRgb, 3),
            b"Lab" => ColorSpace::simple(Family::Lab, 3),
            b"ICCBased" => {
                let stream = items.get(1).cloned().and_then(Object::into_stream);
                let dict = stream.as_ref().map(|s| s.dict().clone());
                let n = dict.as_ref().and_then(|d| d.get::<usize>(b"N")).unwrap_or(0);
                let alternate = dict
                    .as_ref()
                    .and_then(|d| d.get::<Object<'_>>(b"Alternate"))
                    .map(|o| self.resolve(o, None, depth + 1));
                let mut cs = ColorSpace::simple(Family::IccBased, n);
                cs.alternate = alternate;
                cs
            }
            b"Indexed" | b"I" => {
                let base = sub(1);
                let hival =
                    items.get(2).and_then(|o| o.clone().into_number()).map_or(0, |n| n.as_i64().clamp(0, 255) as usize);
                let palette = match items.get(3) {
                    Some(Object::String(s)) => Some(s.as_bytes().to_vec()),
                    Some(Object::Stream(s)) => decode_stream(s, self.max_palette_decode.min(MAX_PALETTE)).ok(),
                    _ => None,
                };
                let mut cs = ColorSpace::simple(Family::Indexed, 1);
                cs.base = base;
                cs.palette = palette.map(Rc::new);
                cs.hival = hival;
                cs
            }
            b"Pattern" => {
                let mut cs = ColorSpace::simple(Family::Pattern, 0);
                cs.base = sub(1);
                if let Some(base) = &cs.base {
                    cs.n = base.n;
                }
                cs
            }
            b"Separation" => {
                let name = match items.get(1) {
                    Some(Object::Name(n)) => n.as_str().to_string(),
                    _ => String::from("?"),
                };
                let mut cs = ColorSpace::simple(Family::Separation, 1);
                cs.colorants = vec![name];
                cs.alternate = sub(2);
                cs
            }
            b"DeviceN" => {
                let names: Vec<String> = match items.get(1) {
                    Some(Object::Array(a)) => {
                        a.iter::<Object<'_>>().filter_map(Object::into_name).map(|n| n.as_str().to_string()).collect()
                    }
                    _ => Vec::new(),
                };
                let mut cs = ColorSpace::simple(Family::DeviceN, names.len());
                cs.colorants = names;
                cs.alternate = sub(2);
                cs
            }
            other => ColorSpace::simple(Family::Unknown(String::from_utf8_lossy(other).into_owned()), 0),
        }
    }
}

/// Classifies the colours a shading can produce. Exponential (type 2)
/// functions and stitching (type 3) functions of them are evaluated at their
/// end points; because interpolation preserves neutrality, a shading between
/// two gray colours is gray. Other functions are `Unknown` unless the colour
/// space alone decides.
pub fn classify_shading(shading: &Dict<'_>, cs: &ColorSpace) -> Class {
    let by_space = cs.classify(&cs.initial_values());
    match &cs.family {
        Family::DeviceGray | Family::CalGray => return Class::Gray,
        Family::IccBased if cs.n == 1 => return Class::Gray,
        Family::Separation | Family::DeviceN => {
            // Any tint > 0 of a colorant: the colorant classes decide.
            return if by_space == Class::NoInk { Class::NoInk } else { by_space };
        }
        _ => {}
    }
    let function = shading
        .get::<Dict<'_>>(b"Function")
        .or_else(|| shading.get::<Stream<'_>>(b"Function").map(|s| s.dict().clone()));
    function.map_or(Class::Unknown, |f| classify_function(&f, cs, 0))
}

fn classify_function(f: &Dict<'_>, cs: &ColorSpace, depth: usize) -> Class {
    if depth > 4 {
        return Class::Unknown;
    }
    match f.get::<i64>(b"FunctionType") {
        Some(2) => {
            let read = |key: &[u8], default: f64| -> Vec<f64> {
                f.get::<Array<'_>>(key).map_or_else(
                    || vec![default],
                    |a| a.iter::<Object<'_>>().filter_map(|o| o.into_number().map(|n| n.as_f64())).collect(),
                )
            };
            let (c0, c1) = (read(b"C0", 0.0), read(b"C1", 1.0));
            if c0.len() < cs.n || c1.len() < cs.n {
                return Class::Unknown;
            }
            cs.classify(&c0).combine(cs.classify(&c1))
        }
        Some(3) => {
            let Some(functions) = f.get::<Array<'_>>(b"Functions") else { return Class::Unknown };
            let mut class = Class::NoInk;
            let mut any = false;
            for sub in functions.iter::<Object<'_>>() {
                let dict = match sub {
                    Object::Dict(d) => d,
                    Object::Stream(s) => s.dict().clone(),
                    _ => return Class::Unknown,
                };
                class = class.combine(classify_function(&dict, cs, depth + 1));
                any = true;
            }
            if any { class } else { Class::Unknown }
        }
        _ => Class::Unknown,
    }
}

/// Name of a `Name` object for messages.
pub fn name_str(n: &Name<'_>) -> String {
    n.as_str().to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn value_classification() {
        let rgb = ColorSpace::simple(Family::DeviceRgb, 3);
        assert_eq!(rgb.classify(&[0.5, 0.5, 0.5]), Class::Gray);
        assert_eq!(rgb.classify(&[0.5, 0.5, 0.503]), Class::Gray);
        assert_eq!(rgb.classify(&[1.0, 0.0, 0.0]), Class::Color);
        let cmyk = ColorSpace::simple(Family::DeviceCmyk, 4);
        assert_eq!(cmyk.classify(&[0.0, 0.0, 0.0, 1.0]), Class::Gray);
        assert_eq!(cmyk.classify(&[0.5, 0.5, 0.5, 1.0]), Class::Color, "rich black uses colour plates");
        let mut sep = ColorSpace::simple(Family::Separation, 1);
        sep.colorants = vec!["HKS 13 K".into()];
        assert_eq!(sep.classify(&[1.0]), Class::Color);
        assert_eq!(sep.classify(&[0.0]), Class::NoInk);
        assert_eq!(sep.category(), "spot");
        sep.colorants = vec!["All".into()];
        assert_eq!(sep.classify(&[1.0]), Class::Gray);
        let mut devn = ColorSpace::simple(Family::DeviceN, 2);
        devn.colorants = vec!["Black".into(), "Cyan".into()];
        assert_eq!(devn.classify(&[1.0, 0.0]), Class::Gray);
        assert_eq!(devn.classify(&[1.0, 0.2]), Class::Color);
        assert_eq!(devn.category(), "cmyk");
    }

    #[test]
    fn indexed_palette() {
        let mut idx = ColorSpace::simple(Family::Indexed, 1);
        idx.base = Some(Rc::new(ColorSpace::simple(Family::DeviceRgb, 3)));
        idx.palette = Some(Rc::new(vec![0, 0, 0, 255, 0, 0, 128, 128, 128]));
        idx.hival = 2;
        assert_eq!(idx.classify(&[0.0]), Class::Gray);
        assert_eq!(idx.classify(&[1.0]), Class::Color);
        assert_eq!(idx.classify(&[2.0]), Class::Gray);
        assert_eq!(idx.palette_class(), Some(Class::Color));
        assert_eq!(idx.category(), "rgb");
    }
}
