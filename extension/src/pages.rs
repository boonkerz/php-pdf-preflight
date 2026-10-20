//! Page geometry: page boxes, rotation and user unit.
//!
//! Inheritance and default rules follow ISO 32000-2, 7.7.3.3 and 14.11.2:
//! `MediaBox`, `CropBox` and `Rotate` are inheritable from the page tree;
//! `BleedBox`, `TrimBox` and `ArtBox` are not and default to the `CropBox`,
//! which itself defaults to the `MediaBox`. All boxes are effectively
//! reduced to their intersection with the `MediaBox`.

use std::collections::HashSet;

use hayro_syntax::object::{Array, Dict, Number, Object};
use hayro_syntax::page::Page;

use crate::errors::Issue;
use crate::value::Value;

pub const PT_TO_MM: f64 = 25.4 / 72.0;
const MAX_PAGE_TREE_DEPTH: usize = 256;

pub const BOX_NAMES: [&str; 5] = ["MediaBox", "CropBox", "BleedBox", "TrimBox", "ArtBox"];

#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Rect {
    pub x0: f64,
    pub y0: f64,
    pub x1: f64,
    pub y1: f64,
}

impl Rect {
    /// Creates a normalised rectangle (lower-left / upper-right), since PDF
    /// allows any two opposite corners.
    pub fn new(a: f64, b: f64, c: f64, d: f64) -> Self {
        Self { x0: a.min(c), y0: b.min(d), x1: a.max(c), y1: b.max(d) }
    }

    pub fn width(&self) -> f64 {
        self.x1 - self.x0
    }

    pub fn height(&self) -> f64 {
        self.y1 - self.y0
    }

    pub fn intersect(&self, other: &Rect) -> Rect {
        let x0 = self.x0.max(other.x0);
        let y0 = self.y0.max(other.y0);
        Rect { x0, y0, x1: self.x1.min(other.x1).max(x0), y1: self.y1.min(other.y1).max(y0) }
    }

    /// True if `self` extends beyond `outer` by more than `eps` points.
    pub fn exceeds(&self, outer: &Rect, eps: f64) -> bool {
        self.x0 < outer.x0 - eps || self.y0 < outer.y0 - eps || self.x1 > outer.x1 + eps || self.y1 > outer.y1 + eps
    }
}

/// Where the value of a page box comes from.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum BoxSource {
    /// Explicitly defined in the page dictionary.
    Page,
    /// Inherited from an ancestor `Pages` node.
    Inherited,
    /// Not defined; derived from another box by the PDF default rules.
    Default(&'static str),
    /// Required box missing or invalid; a fallback value is used.
    Fallback,
}

impl BoxSource {
    fn as_str(&self) -> &'static str {
        match self {
            Self::Page => "page",
            Self::Inherited => "inherited",
            Self::Default(_) => "default",
            Self::Fallback => "fallback",
        }
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct PageBox {
    pub name: &'static str,
    /// The box as defined (or derived), normalised, in default user space units.
    pub rect: Rect,
    /// `rect` intersected with the MediaBox.
    pub effective: Rect,
    pub source: BoxSource,
}

impl PageBox {
    pub fn is_defined(&self) -> bool {
        matches!(self.source, BoxSource::Page | BoxSource::Inherited)
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct PageGeometry {
    pub page: usize,
    /// MediaBox, CropBox, BleedBox, TrimBox, ArtBox (in this order).
    pub boxes: Vec<PageBox>,
    /// Normalised rotation: 0, 90, 180 or 270.
    pub rotation: i32,
    pub user_unit: f64,
    pub issues: Vec<Issue>,
}

impl PageGeometry {
    pub fn get(&self, name: &str) -> Option<&PageBox> {
        self.boxes.iter().find(|b| b.name == name)
    }

    fn scale(&self) -> f64 {
        self.user_unit * PT_TO_MM
    }

    /// Width and height in mm as displayed, i.e. after applying `/Rotate`.
    pub fn display_size_mm(&self, b: &PageBox) -> (f64, f64) {
        let (w, h) = (b.effective.width() * self.scale(), b.effective.height() * self.scale());
        if self.rotation % 180 == 0 { (w, h) } else { (h, w) }
    }

    pub fn box_to_value(&self, b: &PageBox) -> Value {
        let (dw, dh) = self.display_size_mm(b);
        let media = &self.boxes[0].rect;
        Value::map()
            .with("defined", b.is_defined())
            .with("source", b.source.as_str())
            .with(
                "derived_from",
                match b.source {
                    BoxSource::Default(from) => Value::from(from),
                    _ => Value::Null,
                },
            )
            .with("rect", rect_value(&b.rect))
            .with("effective_rect", rect_value(&b.effective))
            .with("exceeds_media_box", b.rect.exceeds(media, 0.01))
            .with("width_pt", b.effective.width())
            .with("height_pt", b.effective.height())
            .with("width_mm", round_mm(b.effective.width() * self.scale()))
            .with("height_mm", round_mm(b.effective.height() * self.scale()))
            .with("display_width_mm", round_mm(dw))
            .with("display_height_mm", round_mm(dh))
    }

    pub fn boxes_to_value(&self) -> Value {
        let mut map = Value::map();
        for b in &self.boxes {
            map = map.with(b.name, self.box_to_value(b));
        }
        map
    }

    pub fn to_value(&self) -> Value {
        let crop = &self.boxes[1];
        let (w, h) = self.display_size_mm(crop);
        let orientation = if (w - h).abs() < 0.01 {
            "square"
        } else if w > h {
            "landscape"
        } else {
            "portrait"
        };
        Value::map()
            .with("page", self.page)
            .with("width_mm", round_mm(w))
            .with("height_mm", round_mm(h))
            .with("orientation", orientation)
            .with("rotation", self.rotation)
            .with("user_unit", self.user_unit)
            .with("size_box", "CropBox")
            .with("boxes", self.boxes_to_value())
            .with("issues", Value::List(self.issues.iter().map(Issue::to_value).collect()))
    }
}

fn rect_value(r: &Rect) -> Value {
    Value::List(vec![r.x0.into(), r.y0.into(), r.x1.into(), r.y1.into()])
}

pub fn round_mm(v: f64) -> f64 {
    (v * 10_000.0).round() / 10_000.0
}

enum Lookup<T> {
    Missing,
    Invalid,
    Found(T),
}

fn read_rect(dict: &Dict<'_>, key: &[u8]) -> Lookup<Rect> {
    if !dict.contains_key(key) {
        return Lookup::Missing;
    }
    let Some(arr) = dict.get::<Array<'_>>(key) else { return Lookup::Invalid };
    let nums: Vec<f64> = arr
        .iter::<Object<'_>>()
        .map(|o| o.into_number().map(|n: Number| n.as_f64()))
        .collect::<Option<Vec<_>>>()
        .unwrap_or_default();
    match nums.as_slice() {
        [a, b, c, d] if nums.iter().all(|v| v.is_finite()) => {
            let r = Rect::new(*a, *b, *c, *d);
            if r.width() > 0.0 && r.height() > 0.0 { Lookup::Found(r) } else { Lookup::Invalid }
        }
        _ => Lookup::Invalid,
    }
}

/// Computes the geometry of a page (1-based `page_no` is only used for
/// messages).
pub fn page_geometry(page: &Page<'_>, page_no: usize) -> PageGeometry {
    let mut issues = Vec::new();
    let page_dict = page.raw().clone();

    // Page dictionary followed by its ancestors.
    let mut chain = vec![page_dict.clone()];
    let mut visited = HashSet::new();
    if let Some(id) = page_dict.obj_id() {
        visited.insert(id);
    }
    let mut current = page_dict.clone();
    while let Some(parent) = current.get::<Dict<'_>>(b"Parent") {
        if chain.len() > MAX_PAGE_TREE_DEPTH {
            issues.push(Issue::on_page("page_tree_too_deep", page_no, "page tree is nested too deeply"));
            break;
        }
        if let Some(id) = parent.obj_id()
            && !visited.insert(id)
        {
            issues.push(Issue::on_page("page_tree_cycle", page_no, "cycle in the /Parent chain of the page tree"));
            break;
        }
        chain.push(parent.clone());
        current = parent;
    }

    let inheritable = |key: &'static str, issues: &mut Vec<Issue>| -> Option<(Rect, BoxSource)> {
        for (level, dict) in chain.iter().enumerate() {
            match read_rect(dict, key.as_bytes()) {
                Lookup::Found(r) => {
                    return Some((r, if level == 0 { BoxSource::Page } else { BoxSource::Inherited }));
                }
                Lookup::Invalid => {
                    issues.push(Issue::on_page("invalid_page_box", page_no, format!("{key} is not a valid rectangle")));
                    return None;
                }
                Lookup::Missing => {}
            }
        }
        None
    };

    let (media, media_source) = match inheritable("MediaBox", &mut issues) {
        Some(found) => found,
        None => {
            // hayro-syntax substitutes A4 for a missing MediaBox.
            let r = page.media_box();
            let rect = Rect::new(r.x0, r.y0, r.x1, r.y1);
            issues.push(Issue::on_page(
                "missing_media_box",
                page_no,
                "the required MediaBox is missing or invalid; using a fallback size",
            ));
            (rect, BoxSource::Fallback)
        }
    };
    let (crop, crop_source) = inheritable("CropBox", &mut issues).unwrap_or((media, BoxSource::Default("MediaBox")));

    let mut boxes = vec![
        PageBox { name: "MediaBox", rect: media, effective: media, source: media_source },
        PageBox { name: "CropBox", rect: crop, effective: crop.intersect(&media), source: crop_source },
    ];

    for name in ["BleedBox", "TrimBox", "ArtBox"] {
        let (rect, source) = match read_rect(&page_dict, name.as_bytes()) {
            Lookup::Found(r) => (r, BoxSource::Page),
            Lookup::Invalid => {
                issues.push(Issue::on_page("invalid_page_box", page_no, format!("{name} is not a valid rectangle")));
                (crop, BoxSource::Default("CropBox"))
            }
            Lookup::Missing => {
                if chain[1..].iter().any(|d| d.contains_key(name.as_bytes())) {
                    issues.push(Issue::on_page(
                        "non_inheritable_box_on_pages_node",
                        page_no,
                        format!("{name} is defined on a Pages node, where it is not inheritable and therefore ignored"),
                    ));
                }
                (crop, BoxSource::Default("CropBox"))
            }
        };
        boxes.push(PageBox { name, rect, effective: rect.intersect(&media), source });
    }

    for b in &boxes[1..] {
        if b.is_defined() && b.rect.exceeds(&media, 0.01) {
            issues.push(Issue::on_page(
                "box_exceeds_media_box",
                page_no,
                format!("{} extends beyond the MediaBox and is clipped to it", b.name),
            ));
        }
    }

    let rotate = chain.iter().find_map(|d| d.contains_key(b"Rotate").then(|| d.get::<Number>(b"Rotate")));
    let rotation = match rotate {
        None => 0,
        Some(Some(n)) if n.as_f64().fract() == 0.0 && (n.as_i64() % 90) == 0 => (n.as_i64().rem_euclid(360)) as i32,
        Some(_) => {
            issues.push(Issue::on_page("invalid_rotation", page_no, "/Rotate is not a multiple of 90; treated as 0"));
            0
        }
    };

    let user_unit = match page_dict.get::<Number>(b"UserUnit").map(|n| n.as_f64()) {
        None => 1.0,
        Some(u) if u.is_finite() && u > 0.0 => u,
        Some(_) => {
            issues.push(Issue::on_page("invalid_user_unit", page_no, "/UserUnit must be a positive number; using 1"));
            1.0
        }
    };

    PageGeometry { page: page_no, boxes, rotation, user_unit, issues }
}
