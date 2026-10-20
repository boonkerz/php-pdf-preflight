//! Content stream interpreter.
//!
//! Walks the content of a page including nested form XObjects, tiling
//! patterns and printable annotation appearances. It tracks the parts of the
//! graphics state the analysis needs (CTM, fill/stroke colour, font, text
//! rendering mode) and records
//!
//! * fonts that are actually used to show text,
//! * colour spaces and colours used by painting operators (fills, strokes,
//!   text, images, shadings) and the resulting print classification,
//! * image placements with their effective resolution.
//!
//! Forms are interpreted for every invocation, because their result depends
//! on the inherited graphics state (CTM for resolutions, colours for forms
//! that do not set their own). Pathological nesting is bounded by
//! `max_operations_per_page`, `max_nesting_depth` and the time budget.

use std::cell::{Cell, RefCell};
use std::collections::{BTreeSet, HashMap, HashSet};
use std::rc::Rc;
use std::time::Instant;

use hayro_syntax::content::TypedIter;
use hayro_syntax::content::ops::TypedInstruction;
use hayro_syntax::object::{Array, Dict, MaybeRef, Name, Number, Object, ObjectIdentifier, Stream};
use hayro_syntax::page::{Page, Resources};
use hayro_syntax::xref::XRef;

use crate::colors::{Class, ColorSpace, Family, NEUTRAL_EPS, Resolver, classify_shading};
use crate::decode::{DecodeError, decode_stream};
use crate::errors::Issue;
use crate::fonts::{FontInfo, analyze_font};
use crate::images::{ImageInfo, Placement};
use crate::limits::Limits;
use crate::pages::PT_TO_MM;
use crate::value::Value;

/// Affine matrix `[a b c d e f]` in PDF (row vector) convention.
pub type Matrix = [f64; 6];
pub const IDENTITY: Matrix = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

/// `m × n`: applies `m` first, then `n` (as `cm` does: CTM' = M × CTM).
pub fn mul(m: &Matrix, n: &Matrix) -> Matrix {
    [
        m[0] * n[0] + m[1] * n[2],
        m[0] * n[1] + m[1] * n[3],
        m[2] * n[0] + m[3] * n[2],
        m[2] * n[1] + m[3] * n[3],
        m[4] * n[0] + m[5] * n[2] + n[4],
        m[4] * n[1] + m[5] * n[3] + n[5],
    ]
}

fn apply(m: &Matrix, x: f64, y: f64) -> (f64, f64) {
    (m[0] * x + m[2] * y + m[4], m[1] * x + m[3] * y + m[5])
}

fn numbers(dict: &Dict<'_>, key: &[u8]) -> Vec<f64> {
    dict.get::<Array<'_>>(key)
        .map(|a| a.iter::<Object<'_>>().filter_map(|o| o.into_number().map(|n: Number| n.as_f64())).collect())
        .unwrap_or_default()
}

fn read_matrix(dict: &Dict<'_>) -> Matrix {
    match numbers(dict, b"Matrix").as_slice() {
        [a, b, c, d, e, f] if [a, b, c, d, e, f].iter().all(|v| v.is_finite()) => [*a, *b, *c, *d, *e, *f],
        _ => IDENTITY,
    }
}

/// Bounding box of a rectangle `[x0 y0 x1 y1]` after transformation.
fn transform_rect(m: &Matrix, r: [f64; 4]) -> [f64; 4] {
    let pts = [apply(m, r[0], r[1]), apply(m, r[2], r[1]), apply(m, r[0], r[3]), apply(m, r[2], r[3])];
    let xs = pts.iter().map(|p| p.0);
    let ys = pts.iter().map(|p| p.1);
    [
        xs.clone().fold(f64::INFINITY, f64::min),
        ys.clone().fold(f64::INFINITY, f64::min),
        xs.fold(f64::NEG_INFINITY, f64::max),
        ys.fold(f64::NEG_INFINITY, f64::max),
    ]
}

/// Identity of a font dictionary within a document.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum FontKey {
    Object(ObjectIdentifier),
    /// Direct (inline) dictionary, identified by the address of its bytes.
    Direct(usize),
}

/// Document-wide state shared by all page analyses.
pub struct Env<'d> {
    pub limits: &'d Limits,
    pub font_cache: &'d RefCell<HashMap<FontKey, Rc<FontInfo>>>,
    pub image_cache: &'d RefCell<HashMap<ObjectIdentifier, Rc<ImageInfo>>>,
    pub decoded_total: &'d Cell<u64>,
    pub deadline: Option<Instant>,
}

/// Page geometry needed by the interpreter.
pub struct PageFrame {
    /// Effective CropBox in default user space units.
    pub crop_box: [f64; 4],
    pub user_unit: f64,
}

#[derive(Debug, Clone)]
pub struct FontUsage {
    pub key: FontKey,
    pub info: Rc<FontInfo>,
    /// Resource names under which the font was referenced (e.g. "F1").
    pub resource_names: BTreeSet<String>,
    /// Text was shown with this font.
    pub used: bool,
    /// Text was shown with a rendering mode other than 3 (invisible).
    pub visible: bool,
    /// Where text with this font was shown: page, form, pattern, annotation.
    pub contexts: BTreeSet<&'static str>,
}

impl FontUsage {
    pub fn to_value(&self) -> Value {
        let mut v = self.info.to_value();
        if let Value::Map(entries) = &mut v {
            entries.push(("used".into(), self.used.into()));
            entries.push(("visible".into(), self.visible.into()));
            entries.push(("resource_names".into(), self.resource_names.iter().cloned().collect::<Vec<_>>().into()));
            entries.push(("contexts".into(), self.contexts.iter().copied().collect::<Vec<_>>().into()));
        }
        v
    }
}

/// A colour space used by painting operators, aggregated per page.
#[derive(Debug, Clone)]
pub struct SpaceUsage {
    pub cs: Rc<ColorSpace>,
    /// fill, stroke, text, image, image_mask, shading
    pub targets: BTreeSet<&'static str>,
    pub class: Class,
}

#[derive(Debug, Clone, Default)]
pub struct ColorReport {
    /// Colour spaces defined in the resources of executed content streams.
    pub declared: Vec<(String, Rc<ColorSpace>)>,
    /// Colour spaces actually used for painting.
    pub used: Vec<SpaceUsage>,
    /// Spot colorants actually painted with a tint > 0.
    pub spot_colors: BTreeSet<String>,
    /// Combined class of everything painted (`None`: nothing painted).
    pub class: Option<Class>,
    /// Why the classification is unknown (limited to a few entries).
    pub reasons: Vec<String>,
}

impl ColorReport {
    /// `color`, `grayscale` or `unknown`. A blank page is `grayscale`.
    pub fn classification(&self, complete: bool) -> &'static str {
        match self.class {
            Some(Class::Color) => "color",
            Some(Class::Unknown) => "unknown",
            _ if !complete => "unknown",
            _ => "grayscale",
        }
    }

    pub fn to_value(&self, complete: bool) -> Value {
        let mut reasons = self.reasons.clone();
        if !complete && self.class != Some(Class::Color) {
            reasons.push("the content analysis of the page is incomplete".into());
        }
        let declared_spots: BTreeSet<String> = self.declared.iter().flat_map(|(_, cs)| cs.spot_colorants()).collect();
        Value::map()
            .with("classification", self.classification(complete))
            .with("painted", matches!(self.class, Some(c) if c != Class::NoInk))
            .with(
                "used",
                Value::List(
                    self.used
                        .iter()
                        .map(|u| {
                            let mut v = u.cs.to_value();
                            if let Value::Map(e) = &mut v {
                                e.push(("targets".into(), u.targets.iter().copied().collect::<Vec<_>>().into()));
                                e.push(("class".into(), u.class.as_str().into()));
                                e.push((
                                    "neutral".into(),
                                    match u.class {
                                        Class::Gray | Class::NoInk => Value::Bool(true),
                                        Class::Color => Value::Bool(false),
                                        Class::Unknown => Value::Null,
                                    },
                                ));
                            }
                            v
                        })
                        .collect(),
                ),
            )
            .with(
                "declared",
                Value::List(
                    self.declared
                        .iter()
                        .map(|(name, cs)| {
                            let mut v = cs.to_value();
                            if let Value::Map(e) = &mut v {
                                e.insert(0, ("name".into(), name.as_str().into()));
                            }
                            v
                        })
                        .collect(),
                ),
            )
            .with("spot_colors", self.spot_colors.iter().cloned().collect::<Vec<_>>())
            .with("declared_spot_colors", declared_spots.into_iter().collect::<Vec<_>>())
            .with("reasons", reasons)
    }
}

#[derive(Debug, Clone, Default)]
pub struct PageContent {
    pub fonts: Vec<FontUsage>,
    pub colors: ColorReport,
    pub images: Vec<Placement>,
    pub issues: Vec<Issue>,
    /// False if any part of the page could not be analysed (limits, damaged
    /// or unsupported streams). Results are then a lower bound.
    pub complete: bool,
    pub operations: u64,
}

impl PageContent {
    pub fn images_value(&self) -> Value {
        Value::List(self.images.iter().map(Placement::to_value).collect())
    }
}

#[derive(Debug, Clone, Copy, Default)]
enum CurrentFont {
    #[default]
    None,
    /// `Tf` referenced a name missing from the resources (already reported).
    Missing,
    Font(usize),
}

#[derive(Clone)]
struct Paint<'a> {
    cs: Rc<ColorSpace>,
    values: Vec<f64>,
    pattern: Option<(Object<'a>, Option<ObjectIdentifier>)>,
}

impl Paint<'_> {
    fn new(cs: Rc<ColorSpace>) -> Self {
        Self { values: cs.initial_values(), cs, pattern: None }
    }
}

#[derive(Clone)]
struct GState<'a> {
    ctm: Matrix,
    font: CurrentFont,
    render_mode: i64,
    fill: Paint<'a>,
    stroke: Paint<'a>,
}

impl GState<'_> {
    fn initial(ctm: Matrix) -> Self {
        let gray = ColorSpace::device_gray();
        Self { ctm, font: CurrentFont::None, render_mode: 0, fill: Paint::new(gray.clone()), stroke: Paint::new(gray) }
    }
}

/// Context of the content stream being executed.
struct Frame<'a> {
    resources: Resources<'a>,
    context: &'static str,
    depth: usize,
    /// CTM at the start of the stream (pattern space is relative to it).
    base: Matrix,
}

#[derive(Hash, PartialEq, Eq)]
enum ImageKey {
    Object(ObjectIdentifier),
    Inline(usize),
}

struct Interp<'a, 'e> {
    env: &'e Env<'e>,
    page: &'e PageFrame,
    xref: &'a XRef,
    resolver: Resolver,
    page_no: usize,
    content: PageContent,
    font_index: HashMap<FontKey, usize>,
    usage_index: HashMap<String, usize>,
    placement_index: HashMap<(ImageKey, i64, i64, bool), usize>,
    /// Resource dictionaries whose fonts/colour spaces were registered.
    registered: HashSet<usize>,
    declared_keys: HashSet<String>,
    cs_cache: HashMap<(usize, Vec<u8>), Rc<ColorSpace>>,
    /// Coloured tiling patterns already executed (pattern id, base matrix).
    patterns_done: HashSet<(ObjectIdentifier, [u64; 6])>,
    /// Chain of forms/patterns currently being executed (cycle detection).
    stack: Vec<ObjectIdentifier>,
    reported: HashSet<&'static str>,
    /// > 0 while executing uncoloured patterns.
    suppress_colors: u32,
    aborted: bool,
}

pub fn analyze_page_content(page: &Page<'_>, page_no: usize, frame: &PageFrame, env: &Env<'_>) -> PageContent {
    let mut it = Interp {
        env,
        page: frame,
        xref: page.xref(),
        resolver: Resolver { max_palette_decode: env.limits.max_stream_size },
        page_no,
        content: PageContent { complete: true, ..Default::default() },
        font_index: HashMap::new(),
        usage_index: HashMap::new(),
        placement_index: HashMap::new(),
        registered: HashSet::new(),
        declared_keys: HashSet::new(),
        cs_cache: HashMap::new(),
        patterns_done: HashSet::new(),
        stack: Vec::new(),
        reported: HashSet::new(),
        suppress_colors: 0,
        aborted: false,
    };

    let page_dict = page.raw();
    let root = Frame { resources: page.resources().clone(), context: "page", depth: 0, base: IDENTITY };
    if let Some(data) = it.page_contents(page_dict) {
        it.run(&data, &root, GState::initial(IDENTITY));
    }
    it.run_annotations(page_dict);
    it.content
}

impl<'a> Interp<'a, '_> {
    fn issue(&mut self, code: &'static str, message: impl Into<String>) {
        // One issue per code and page keeps the output readable for
        // pathological files.
        if self.reported.insert(code) {
            self.content.issues.push(Issue::on_page(code, self.page_no, message));
        }
    }

    fn incomplete(&mut self, code: &'static str, message: impl Into<String>) {
        self.content.complete = false;
        self.issue(code, message);
    }

    fn remaining_budget(&self) -> usize {
        let limits = self.env.limits;
        let remaining = limits.max_total_decoded.saturating_sub(self.env.decoded_total.get());
        (limits.max_stream_size as u64).min(remaining) as usize
    }

    fn charge(&self, bytes: usize) {
        self.env.decoded_total.set(self.env.decoded_total.get() + bytes as u64);
    }

    fn decode(&mut self, stream: &Stream<'a>, what: &str) -> Option<Vec<u8>> {
        let limits = self.env.limits;
        let limit = self.remaining_budget();
        match decode_stream(stream, limit) {
            Ok(data) => {
                self.charge(data.len());
                Some(data)
            }
            Err(DecodeError::LimitExceeded { .. }) => {
                let name = if limit < limits.max_stream_size { "max_total_decoded" } else { "max_stream_size" };
                self.incomplete("limit_exceeded", format!("{what} not analysed: limit '{name}' exceeded"));
                None
            }
            Err(e) => {
                self.incomplete("stream_decode_failed", format!("{what} could not be decoded: {e}"));
                None
            }
        }
    }

    fn page_contents(&mut self, page_dict: &Dict<'a>) -> Option<Vec<u8>> {
        if let Some(stream) = page_dict.get::<Stream<'a>>(b"Contents") {
            return self.decode(&stream, "page content stream");
        }
        let arr = page_dict.get::<Array<'a>>(b"Contents")?;
        let mut data = Vec::new();
        for obj in arr.iter::<Object<'a>>() {
            match obj.into_stream() {
                Some(s) => data.extend(self.decode(&s, "page content stream")?),
                None => self.issue("invalid_content_stream", "page /Contents array contains a non-stream entry"),
            }
            // Operators may span stream boundaries; a separator is required
            // between tokens.
            data.push(b'\n');
        }
        Some(data)
    }

    /// Returns false once the page analysis has to stop.
    fn tick(&mut self) -> bool {
        if self.aborted {
            return false;
        }
        self.content.operations += 1;
        if self.content.operations > self.env.limits.max_operations_per_page {
            self.aborted = true;
            self.incomplete("limit_exceeded", "page analysis stopped: limit 'max_operations_per_page' exceeded");
            return false;
        }
        if self.content.operations.is_multiple_of(4096)
            && let Some(deadline) = self.env.deadline
            && Instant::now() > deadline
        {
            self.aborted = true;
            self.incomplete("analysis_timeout", "page analysis stopped: limit 'max_analysis_time_ms' exceeded");
            return false;
        }
        true
    }

    /// Resolves an entry of a resource sub-dictionary, keeping track of
    /// whether it is an indirect object.
    fn lookup(&self, category: &Dict<'a>, name: &[u8]) -> Option<(Object<'a>, Option<ObjectIdentifier>)> {
        match category.get_raw::<Object<'a>>(name)? {
            MaybeRef::Ref(r) => {
                let id: ObjectIdentifier = r.into();
                self.xref.get::<Object<'a>>(id).map(|o| (o, Some(id)))
            }
            MaybeRef::NotRef(o) => Some((o, None)),
        }
    }

    // ------------------------------------------------------------------
    // Fonts
    // ------------------------------------------------------------------

    fn font_usage(&mut self, dict: Dict<'a>, id: Option<ObjectIdentifier>, resource_name: &str) -> usize {
        let key = match id {
            Some(id) => FontKey::Object(id),
            None => FontKey::Direct(dict.data().as_ptr() as usize),
        };
        let idx = match self.font_index.get(&key) {
            Some(&idx) => idx,
            None => {
                let info = self
                    .env
                    .font_cache
                    .borrow_mut()
                    .entry(key)
                    .or_insert_with(|| Rc::new(analyze_font(&dict, id)))
                    .clone();
                self.content.fonts.push(FontUsage {
                    key,
                    info,
                    resource_names: BTreeSet::new(),
                    used: false,
                    visible: false,
                    contexts: BTreeSet::new(),
                });
                self.font_index.insert(key, self.content.fonts.len() - 1);
                self.content.fonts.len() - 1
            }
        };
        if !resource_name.is_empty() {
            self.content.fonts[idx].resource_names.insert(resource_name.to_string());
        }
        idx
    }

    /// Registers fonts and colour spaces declared in a resource dictionary
    /// (once per dictionary).
    fn register_resources(&mut self, resources: &Resources<'a>) {
        let font_key = resources.fonts.data().as_ptr() as usize;
        if !resources.fonts.is_empty() && self.registered.insert(font_key) {
            let mut names: Vec<Vec<u8>> = resources.fonts.keys().map(|k| k.as_ref().to_vec()).collect();
            names.sort();
            for name in names {
                if let Some((obj, id)) = self.lookup(&resources.fonts, &name)
                    && let Some(dict) = obj.into_dict()
                {
                    self.font_usage(dict, id, &String::from_utf8_lossy(&name));
                }
            }
        }
        let cs_key = resources.color_spaces.data().as_ptr() as usize;
        if !resources.color_spaces.is_empty() && self.registered.insert(cs_key) {
            let mut names: Vec<Vec<u8>> = resources.color_spaces.keys().map(|k| k.as_ref().to_vec()).collect();
            names.sort();
            for name in names {
                let cs = self.color_space(resources, &name);
                let key = format!(
                    "{}|{}|{:?}|{}",
                    String::from_utf8_lossy(&name),
                    cs.family.name(),
                    cs.object,
                    cs.colorants.join(",")
                );
                if self.declared_keys.insert(key) {
                    self.content.colors.declared.push((String::from_utf8_lossy(&name).into_owned(), cs));
                }
            }
        }
    }

    fn select_font(&mut self, resources: &Resources<'a>, name: &Name<'_>) -> CurrentFont {
        match self.lookup(&resources.fonts, name.as_ref()).and_then(|(o, id)| o.into_dict().map(|d| (d, id))) {
            Some((dict, id)) => CurrentFont::Font(self.font_usage(dict, id, name.as_str())),
            None => {
                self.issue("font_resource_missing", format!("font /{} is not defined in the resources", name.as_str()));
                CurrentFont::Missing
            }
        }
    }

    /// `gs` operator with a `/Font [font size]` entry.
    fn ext_g_state_font(&mut self, resources: &Resources<'a>, name: &Name<'_>) -> Option<usize> {
        let (obj, _) = self.lookup(&resources.ext_g_states, name.as_ref())?;
        let gs = obj.into_dict()?;
        let arr = gs.get::<Array<'a>>(b"Font")?;
        let mut items = arr.raw_iter();
        let (dict, id) = match items.next()? {
            MaybeRef::Ref(r) => (self.xref.get::<Dict<'a>>(r.into())?, Some(r.into())),
            MaybeRef::NotRef(o) => (o.into_dict()?, None),
        };
        Some(self.font_usage(dict, id, ""))
    }

    fn show_text(&mut self, gs: &GState<'a>, frame: &Frame<'a>) {
        match gs.font {
            CurrentFont::Font(idx) => {
                let usage = &mut self.content.fonts[idx];
                usage.used = true;
                usage.visible |= gs.render_mode != 3;
                usage.contexts.insert(frame.context);
            }
            CurrentFont::Missing => {}
            CurrentFont::None => self.issue("text_without_font", "text is shown without a font being selected"),
        }
        // Text rendering modes: 0 fill, 1 stroke, 2 both, 3 invisible,
        // 4-6 like 0-2 plus clipping, 7 clipping only.
        if matches!(gs.render_mode, 0 | 2 | 4 | 6) {
            self.paint("text", &gs.fill, frame);
        }
        if matches!(gs.render_mode, 1 | 2 | 5 | 6) {
            self.paint("text", &gs.stroke, frame);
        }
    }

    // ------------------------------------------------------------------
    // Colour
    // ------------------------------------------------------------------

    fn color_space(&mut self, resources: &Resources<'a>, name: &[u8]) -> Rc<ColorSpace> {
        if let Some(cs) = Resolver::device(name) {
            return cs;
        }
        let key = (resources.color_spaces.data().as_ptr() as usize, name.to_vec());
        if let Some(cs) = self.cs_cache.get(&key) {
            return cs.clone();
        }
        let cs = match self.lookup(&resources.color_spaces, name) {
            Some((obj, id)) => {
                self.resolver.resolve(obj, id.map(|id| format!("{} {} R", id.obj_number, id.gen_number)), 0)
            }
            None => {
                self.issue(
                    "colorspace_missing",
                    format!("colour space /{} is not defined in the resources", String::from_utf8_lossy(name)),
                );
                Rc::new(ColorSpace::simple(Family::Unknown(String::from_utf8_lossy(name).into_owned()), 0))
            }
        };
        self.cs_cache.insert(key, cs.clone());
        cs
    }

    /// Records painting with colour space `cs`. `values` are given for
    /// vector/text colours and allow exact spot colour tracking.
    fn record(
        &mut self,
        target: &'static str,
        cs: &Rc<ColorSpace>,
        class: Class,
        values: Option<&[f64]>,
        reason: Option<String>,
    ) {
        if self.suppress_colors > 0 {
            return;
        }
        let key = format!("{}|{}|{}", cs.family.name(), cs.n, cs.colorants.join("\u{1f}"));
        let idx = *self.usage_index.entry(key).or_insert_with(|| {
            self.content.colors.used.push(SpaceUsage { cs: cs.clone(), targets: BTreeSet::new(), class });
            self.content.colors.used.len() - 1
        });
        let usage = &mut self.content.colors.used[idx];
        usage.targets.insert(target);
        usage.class = usage.class.combine(class);

        let colors = &mut self.content.colors;
        colors.class = Some(colors.class.map_or(class, |c| c.combine(class)));
        if class == Class::Unknown
            && let Some(reason) = reason
            && colors.reasons.len() < 10
            && !colors.reasons.contains(&reason)
        {
            colors.reasons.push(reason);
        }
        if class != Class::NoInk && matches!(cs.family, Family::Separation | Family::DeviceN) {
            let spots = cs.spot_colorants();
            for (i, colorant) in cs.colorants.iter().enumerate() {
                let tint = values.and_then(|v| v.get(i).copied()).unwrap_or(1.0);
                if tint > NEUTRAL_EPS && spots.contains(colorant) {
                    colors.spot_colors.insert(colorant.clone());
                }
            }
        }
    }

    fn paint(&mut self, target: &'static str, paint: &Paint<'a>, frame: &Frame<'a>) {
        if !matches!(paint.cs.family, Family::Pattern) {
            let class = paint.cs.classify(&paint.values);
            let reason =
                (class == Class::Unknown).then(|| format!("{target} in colour space {}", paint.cs.family.name()));
            self.record(target, &paint.cs, class, Some(&paint.values), reason);
            return;
        }
        let Some((pattern, id)) = paint.pattern.clone() else {
            self.record(target, &paint.cs, Class::Unknown, None, Some(format!("{target} with an undefined pattern")));
            return;
        };
        match pattern {
            Object::Stream(stream) => {
                // Tiling pattern
                if stream.dict().get::<i64>(b"PaintType") == Some(2) {
                    // Uncoloured: colour comes from the underlying space.
                    match &paint.cs.base {
                        Some(base) => {
                            let class = base.classify(&paint.values);
                            self.record(target, base, class, Some(&paint.values), None);
                        }
                        None => self.record(
                            target,
                            &paint.cs,
                            Class::Unknown,
                            None,
                            Some("uncoloured pattern without base colour space".into()),
                        ),
                    }
                    self.run_pattern(&stream, id, frame, true);
                } else {
                    self.run_pattern(&stream, id, frame, false);
                }
            }
            Object::Dict(dict) => {
                // Shading pattern
                let shading = dict
                    .get::<Dict<'a>>(b"Shading")
                    .or_else(|| dict.get::<Stream<'a>>(b"Shading").map(|s| s.dict().clone()));
                match shading {
                    Some(shading) => self.shade(target, &shading, frame),
                    None => self.record(
                        target,
                        &paint.cs,
                        Class::Unknown,
                        None,
                        Some("shading pattern without shading".into()),
                    ),
                }
            }
            _ => self.record(target, &paint.cs, Class::Unknown, None, Some("invalid pattern".into())),
        }
    }

    fn shade(&mut self, target: &'static str, shading: &Dict<'a>, frame: &Frame<'a>) {
        let cs = match shading.get::<Object<'a>>(b"ColorSpace") {
            Some(Object::Name(name)) => self.color_space(&frame.resources, name.as_ref()),
            Some(obj) => self.resolver.resolve(obj, None, 0),
            None => Rc::new(ColorSpace::simple(Family::Unknown("missing".into()), 0)),
        };
        let class = classify_shading(shading, &cs);
        let reason = (class == Class::Unknown)
            .then(|| format!("shading in colour space {} with a function that cannot be evaluated", cs.family.name()));
        self.record(target, &cs, class, None, reason);
    }

    fn set_color(&mut self, paint: &mut Paint<'a>, nums: &[Number], pattern: Option<&Name<'_>>, frame: &Frame<'a>) {
        paint.values = nums.iter().map(|n| n.as_f64()).collect();
        if let Some(name) = pattern {
            paint.pattern = self.lookup(&frame.resources.patterns, name.as_ref());
            if paint.pattern.is_none() {
                self.issue("pattern_missing", format!("pattern /{} is not defined in the resources", name.as_str()));
            }
        }
    }

    // ------------------------------------------------------------------
    // Interpretation
    // ------------------------------------------------------------------

    fn run(&mut self, data: &[u8], frame: &Frame<'a>, initial: GState<'a>) {
        self.register_resources(&frame.resources);
        let mut gs = initial;
        let mut saved: Vec<GState<'a>> = Vec::new();
        let mut ops = TypedIter::new(data);
        let device = |name: &[u8]| Resolver::device(name).expect("device colour space");

        while let Some(op) = ops.next() {
            if !self.tick() {
                return;
            }
            match op {
                TypedInstruction::SaveState(_) => saved.push(gs.clone()),
                TypedInstruction::RestoreState(_) => {
                    if let Some(prev) = saved.pop() {
                        gs = prev;
                    }
                }
                TypedInstruction::Transform(t) => {
                    let m = [t.0.as_f64(), t.1.as_f64(), t.2.as_f64(), t.3.as_f64(), t.4.as_f64(), t.5.as_f64()];
                    if m.iter().all(|v| v.is_finite()) {
                        gs.ctm = mul(&m, &gs.ctm);
                    }
                }
                TypedInstruction::TextFont(tf) => gs.font = self.select_font(&frame.resources, tf.0),
                TypedInstruction::TextRenderingMode(m) => gs.render_mode = m.0.as_i64(),
                TypedInstruction::SetGraphicsState(n) => {
                    if let Some(font) = self.ext_g_state_font(&frame.resources, n.0) {
                        gs.font = CurrentFont::Font(font);
                    }
                }
                TypedInstruction::ShowText(_)
                | TypedInstruction::NextLineAndShowText(_)
                | TypedInstruction::ShowTextWithParameters(_)
                | TypedInstruction::ShowTexts(_) => self.show_text(&gs, frame),

                // Colour selection
                TypedInstruction::ColorSpaceNonStroke(n) => {
                    gs.fill = Paint::new(self.color_space(&frame.resources, n.0.as_ref()))
                }
                TypedInstruction::ColorSpaceStroke(n) => {
                    gs.stroke = Paint::new(self.color_space(&frame.resources, n.0.as_ref()))
                }
                TypedInstruction::NonStrokeColor(c) => {
                    let mut p = gs.fill.clone();
                    self.set_color(&mut p, &c.0, None, frame);
                    gs.fill = p;
                }
                TypedInstruction::StrokeColor(c) => {
                    let mut p = gs.stroke.clone();
                    self.set_color(&mut p, &c.0, None, frame);
                    gs.stroke = p;
                }
                TypedInstruction::NonStrokeColorNamed(c) => {
                    let mut p = gs.fill.clone();
                    self.set_color(&mut p, &c.0, c.1, frame);
                    gs.fill = p;
                }
                TypedInstruction::StrokeColorNamed(c) => {
                    let mut p = gs.stroke.clone();
                    self.set_color(&mut p, &c.0, c.1, frame);
                    gs.stroke = p;
                }
                TypedInstruction::NonStrokeColorDeviceGray(g) => {
                    gs.fill = Paint { cs: device(b"DeviceGray"), values: vec![g.0.as_f64()], pattern: None }
                }
                TypedInstruction::StrokeColorDeviceGray(g) => {
                    gs.stroke = Paint { cs: device(b"DeviceGray"), values: vec![g.0.as_f64()], pattern: None }
                }
                TypedInstruction::NonStrokeColorDeviceRgb(c) => {
                    gs.fill = Paint {
                        cs: device(b"DeviceRGB"),
                        values: vec![c.0.as_f64(), c.1.as_f64(), c.2.as_f64()],
                        pattern: None,
                    }
                }
                TypedInstruction::StrokeColorDeviceRgb(c) => {
                    gs.stroke = Paint {
                        cs: device(b"DeviceRGB"),
                        values: vec![c.0.as_f64(), c.1.as_f64(), c.2.as_f64()],
                        pattern: None,
                    }
                }
                TypedInstruction::NonStrokeColorCmyk(c) => {
                    gs.fill = Paint {
                        cs: device(b"DeviceCMYK"),
                        values: vec![c.0.as_f64(), c.1.as_f64(), c.2.as_f64(), c.3.as_f64()],
                        pattern: None,
                    }
                }
                TypedInstruction::StrokeColorCmyk(c) => {
                    gs.stroke = Paint {
                        cs: device(b"DeviceCMYK"),
                        values: vec![c.0.as_f64(), c.1.as_f64(), c.2.as_f64(), c.3.as_f64()],
                        pattern: None,
                    }
                }

                // Painting
                TypedInstruction::FillPathNonZero(_)
                | TypedInstruction::FillPathNonZeroCompatibility(_)
                | TypedInstruction::FillPathEvenOdd(_) => self.paint("fill", &gs.fill.clone(), frame),
                TypedInstruction::StrokePath(_) | TypedInstruction::CloseAndStrokePath(_) => {
                    self.paint("stroke", &gs.stroke.clone(), frame)
                }
                TypedInstruction::FillAndStrokeNonZero(_)
                | TypedInstruction::FillAndStrokeEvenOdd(_)
                | TypedInstruction::CloseFillAndStrokeNonZero(_)
                | TypedInstruction::CloseFillAndStrokeEvenOdd(_) => {
                    self.paint("fill", &gs.fill.clone(), frame);
                    self.paint("stroke", &gs.stroke.clone(), frame);
                }
                TypedInstruction::Shading(n) => match self.lookup(&frame.resources.shadings, n.0.as_ref()) {
                    Some((Object::Dict(d), _)) => self.shade("shading", &d, frame),
                    Some((Object::Stream(s), _)) => self.shade("shading", &s.dict().clone(), frame),
                    _ => self
                        .issue("shading_missing", format!("shading /{} is not defined in the resources", n.0.as_str())),
                },
                TypedInstruction::XObject(x) => self.run_xobject(x.0, frame, &gs),
                TypedInstruction::InlineImage(img) => {
                    let stream = img.0.clone();
                    self.do_image(stream, None, frame, &gs, true);
                }
                _ => {}
            }
        }
    }

    /// Checks recursion limits; returns false if the stream must be skipped.
    fn enter(&mut self, id: ObjectIdentifier, depth: usize, what: &str) -> bool {
        if self.stack.contains(&id) {
            self.issue("xobject_cycle", format!("{what} {} {} R references itself", id.obj_number, id.gen_number));
            return false;
        }
        if depth + 1 > self.env.limits.max_nesting_depth {
            self.incomplete("limit_exceeded", format!("{what} not analysed: limit 'max_nesting_depth' exceeded"));
            return false;
        }
        true
    }

    /// Executes a form XObject (or annotation appearance) with the given
    /// graphics state; the form matrix is applied to its CTM.
    fn run_form(
        &mut self,
        stream: &Stream<'a>,
        id: ObjectIdentifier,
        parent: Option<&Resources<'a>>,
        context: &'static str,
        depth: usize,
        mut gs: GState<'a>,
    ) {
        if !self.enter(id, depth, "form XObject") {
            return;
        }
        let Some(data) = self.decode(stream, "form XObject") else { return };
        let resources = match (stream.dict().get::<Dict<'a>>(b"Resources"), parent) {
            (Some(own), _) => Resources::new(own),
            // Forms without own resources use those of the invoking stream
            // (deprecated, but common in older files).
            (None, Some(parent)) => parent.clone(),
            (None, None) => Resources::new(Dict::default()),
        };
        gs.ctm = mul(&read_matrix(stream.dict()), &gs.ctm);
        let frame = Frame { resources, context, depth: depth + 1, base: gs.ctm };
        self.stack.push(id);
        self.run(&data, &frame, gs);
        self.stack.pop();
    }

    fn run_xobject(&mut self, name: &Name<'_>, frame: &Frame<'a>, gs: &GState<'a>) {
        let Some((obj, id)) = self.lookup(&frame.resources.x_objects, name.as_ref()) else {
            self.issue("xobject_missing", format!("XObject /{} is not defined in the resources", name.as_str()));
            return;
        };
        let Some(stream) = obj.into_stream() else {
            self.issue("xobject_invalid", format!("XObject /{} is not a stream", name.as_str()));
            return;
        };
        let id = id.unwrap_or_else(|| stream.obj_id());
        match stream.dict().get::<Name<'_>>(b"Subtype").as_deref() {
            Some(b"Form") => {
                let context = if frame.context == "page" { "form" } else { frame.context };
                self.run_form(&stream, id, Some(&frame.resources), context, frame.depth, gs.clone());
            }
            Some(b"Image") => self.do_image(stream, Some(id), frame, gs, false),
            _ => {}
        }
    }

    /// Executes a tiling pattern once per page and pattern space.
    fn run_pattern(&mut self, stream: &Stream<'a>, id: Option<ObjectIdentifier>, frame: &Frame<'a>, uncolored: bool) {
        let id = id.unwrap_or_else(|| stream.obj_id());
        let ctm = mul(&read_matrix(stream.dict()), &frame.base);
        if !self.patterns_done.insert((id, ctm.map(f64::to_bits))) {
            return;
        }
        if !self.enter(id, frame.depth, "pattern") {
            return;
        }
        let Some(data) = self.decode(stream, "pattern") else { return };
        let resources = Resources::new(stream.dict().get::<Dict<'a>>(b"Resources").unwrap_or_default());
        let inner = Frame { resources, context: "pattern", depth: frame.depth + 1, base: ctm };
        self.stack.push(id);
        // Colour operators inside uncoloured patterns are ignored; the content
        // is still scanned for fonts and image placements.
        self.suppress_colors += u32::from(uncolored);
        self.run(&data, &inner, GState::initial(ctm));
        self.suppress_colors -= u32::from(uncolored);
        self.stack.pop();
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    fn do_image<'s>(
        &mut self,
        stream: Stream<'s>,
        id: Option<ObjectIdentifier>,
        frame: &Frame<'a>,
        gs: &GState<'a>,
        inline: bool,
    ) {
        let resources = frame.resources.clone();
        let make = |this: &mut Self| {
            Rc::new(ImageInfo::new(
                stream.dict(),
                id.map(|id| format!("{} {} R", id.obj_number, id.gen_number)),
                inline,
                |obj| match obj {
                    Object::Name(name) => this.color_space(&resources, name.as_ref()),
                    other => this.resolver.resolve(other, None, 0),
                },
            ))
        };
        let info = match id {
            Some(id) if !inline => {
                let cached = self.env.image_cache.borrow().get(&id).cloned();
                match cached {
                    Some(info) => info,
                    None => {
                        let info = make(self);
                        self.env.image_cache.borrow_mut().insert(id, info.clone());
                        info
                    }
                }
            }
            _ => make(self),
        };

        if info.image_mask {
            // Stencil masks paint with the current fill colour.
            self.paint("image_mask", &gs.fill.clone(), frame);
        } else {
            let (class, reason) = self.image_class(&info, &stream);
            let cs = info
                .color_space
                .clone()
                .unwrap_or_else(|| Rc::new(ColorSpace::simple(Family::Unknown("embedded".into()), 0)));
            self.record("image", &cs, class, None, reason);
        }

        let key = match id {
            Some(id) if !inline => ImageKey::Object(id),
            _ => ImageKey::Inline(stream.dict().data().as_ptr() as usize),
        };
        self.place(info, key, &gs.ctm, frame.context);
    }

    fn image_class(&mut self, info: &Rc<ImageInfo>, stream: &Stream<'_>) -> (Class, Option<String>) {
        if let Some(c) = info.class.get() {
            return c.clone();
        }
        if let Some(c) = info.class_from_metadata() {
            let _ = info.class.set(c.clone());
            return c;
        }
        if self.content.colors.class == Some(Class::Color) {
            // The page is already known to be coloured; skip the pixel check.
            return (Class::Unknown, None);
        }
        let max_pixels = self.env.limits.max_color_check_pixels;
        let (class, reason, used) = info.classify_pixels(stream, max_pixels, self.remaining_budget());
        self.charge(used);
        let result = (class, reason);
        let _ = info.class.set(result.clone());
        result
    }

    fn place(&mut self, image: Rc<ImageInfo>, key: ImageKey, ctm: &Matrix, context: &'static str) {
        let uu = self.page.user_unit;
        let sx = ctm[0].hypot(ctm[1]) * uu;
        let sy = ctm[2].hypot(ctm[3]) * uu;
        if sx < 1e-9 || sy < 1e-9 || image.width == 0 || image.height == 0 {
            return;
        }
        let dpi_x = image.width as f64 / (sx / 72.0);
        let dpi_y = image.height as f64 / (sy / 72.0);
        let bbox = transform_rect(ctm, [0.0, 0.0, 1.0, 1.0]);
        let crop = self.page.crop_box;
        let on_page = bbox[0].max(crop[0]) < bbox[2].min(crop[2]) && bbox[1].max(crop[1]) < bbox[3].min(crop[3]);

        let full_key = (key, (dpi_x * 10.0).round() as i64, (dpi_y * 10.0).round() as i64, on_page);
        if let Some(&idx) = self.placement_index.get(&full_key) {
            let p = &mut self.content.images[idx];
            p.count += 1;
            p.contexts.insert(context);
            return;
        }
        if self.content.images.len() >= self.env.limits.max_image_placements {
            self.incomplete("limit_exceeded", "image placements not recorded: limit 'max_image_placements' exceeded");
            return;
        }
        self.placement_index.insert(full_key, self.content.images.len());
        self.content.images.push(Placement {
            image,
            dpi_x,
            dpi_y,
            width_mm: sx * PT_TO_MM,
            height_mm: sy * PT_TO_MM,
            bbox,
            on_page,
            count: 1,
            contexts: BTreeSet::from([context]),
        });
    }

    // ------------------------------------------------------------------
    // Annotations
    // ------------------------------------------------------------------

    /// Appearance streams of annotations that are printed (Print flag set,
    /// Hidden flag not set), placed as described in ISO 32000-2, 12.5.5.
    fn run_annotations(&mut self, page_dict: &Dict<'a>) {
        let Some(annots) = page_dict.get::<Array<'a>>(b"Annots") else { return };
        for obj in annots.iter::<Object<'a>>() {
            if self.aborted {
                return;
            }
            let Some(annot) = obj.into_dict() else { continue };
            let flags = annot.get::<i64>(b"F").unwrap_or(0);
            if flags & 4 == 0 || flags & 2 != 0 {
                continue;
            }
            let Some(ap) = annot.get::<Dict<'a>>(b"AP") else { continue };
            let normal = ap.get::<Stream<'a>>(b"N").or_else(|| {
                let states = ap.get::<Dict<'a>>(b"N")?;
                let state = annot.get::<Name<'_>>(b"AS")?;
                states.get::<Stream<'a>>(state.as_ref())
            });
            let Some(stream) = normal else { continue };
            let placement = annotation_matrix(&annot, &stream);
            self.run_form(&stream, stream.obj_id(), None, "annotation", 0, GState::initial(placement));
        }
    }
}

/// Matrix A that maps the transformed form BBox onto the annotation /Rect.
fn annotation_matrix(annot: &Dict<'_>, form: &Stream<'_>) -> Matrix {
    let (rect, bbox) = (numbers(annot, b"Rect"), numbers(form.dict(), b"BBox"));
    let (Ok(rect), Ok(bbox)) = (<[f64; 4]>::try_from(rect.as_slice()), <[f64; 4]>::try_from(bbox.as_slice())) else {
        return IDENTITY;
    };
    let t = transform_rect(&read_matrix(form.dict()), bbox);
    let (tw, th) = (t[2] - t[0], t[3] - t[1]);
    let (rw, rh) = ((rect[2] - rect[0]).abs(), (rect[3] - rect[1]).abs());
    if tw.abs() < 1e-9 || th.abs() < 1e-9 {
        return IDENTITY;
    }
    let (sx, sy) = (rw / tw, rh / th);
    [sx, 0.0, 0.0, sy, rect[0].min(rect[2]) - t[0] * sx, rect[1].min(rect[3]) - t[1] * sy]
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matrix_multiplication() {
        let scale = [2.0, 0.0, 0.0, 3.0, 0.0, 0.0];
        let translate = [1.0, 0.0, 0.0, 1.0, 10.0, 20.0];
        // Scale first, then translate.
        assert_eq!(mul(&scale, &translate), [2.0, 0.0, 0.0, 3.0, 10.0, 20.0]);
        // Translate first, then scale.
        assert_eq!(mul(&translate, &scale), [2.0, 0.0, 0.0, 3.0, 20.0, 60.0]);
        assert_eq!(apply(&mul(&translate, &scale), 1.0, 1.0), (22.0, 63.0));
        assert_eq!(transform_rect(&[0.0, 1.0, -1.0, 0.0, 0.0, 0.0], [0.0, 0.0, 2.0, 1.0]), [-1.0, 0.0, 0.0, 2.0]);
    }
}
