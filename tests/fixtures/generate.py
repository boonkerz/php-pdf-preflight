#!/usr/bin/env python3
"""Generates the PDF test fixtures in this directory.

The files are written byte-for-byte deterministically (fixed IDs, no
timestamps) with the Python standard library only, so they can be regenerated
and diffed in CI. They are synthetic and free of third-party content.

Font programs embedded in the fixtures are *stubs*: streams that start with a
valid font file signature but contain no glyph data. They are sufficient to
test embedding detection, which inspects PDF structure and the program
signature, but they are not usable fonts.

Usage: python3 generate.py [output_dir]
"""

import hashlib
import os
import struct
import sys
import zlib

MM = 72 / 25.4


def mm(v):
    return round(v * MM, 4)


def fmt(v):
    if isinstance(v, float):
        s = f"{v:.4f}".rstrip("0").rstrip(".")
        return s if s not in ("", "-0") else "0"
    return str(v)


def rect(*vals):
    return "[" + " ".join(fmt(v) for v in vals) + "]"


# --------------------------------------------------------------------------
# RC4 / standard security handler (revision 3, 128 bit)
# --------------------------------------------------------------------------

PAD = bytes.fromhex(
    "28BF4E5E4E758A4164004E56FFFA01082E2E00B6D0683E802F0CA9FE6453697A"
)


def rc4(key, data):
    s = list(range(256))
    j = 0
    for i in range(256):
        j = (j + s[i] + key[i % len(key)]) % 256
        s[i], s[j] = s[j], s[i]
    out = bytearray()
    i = j = 0
    for b in data:
        i = (i + 1) % 256
        j = (j + s[i]) % 256
        s[i], s[j] = s[j], s[i]
        out.append(b ^ s[(s[i] + s[j]) % 256])
    return bytes(out)


def pad_pw(pw):
    return (pw.encode("latin-1") + PAD)[:32]


class Security:
    def __init__(self, user, owner, doc_id, permissions=-3904):
        self.n = 16
        self.p = permissions
        # Algorithm 3: O value
        h = hashlib.md5(pad_pw(owner)).digest()
        for _ in range(50):
            h = hashlib.md5(h).digest()
        okey = h[: self.n]
        o = rc4(okey, pad_pw(user))
        for i in range(1, 20):
            o = rc4(bytes(b ^ i for b in okey), o)
        self.o = o
        # Algorithm 2: file key
        h = hashlib.md5(pad_pw(user) + o + struct.pack("<i", self.p) + doc_id).digest()
        for _ in range(50):
            h = hashlib.md5(h[: self.n]).digest()
        self.key = h[: self.n]
        # Algorithm 5: U value
        u = rc4(self.key, hashlib.md5(PAD + doc_id).digest())
        for i in range(1, 20):
            u = rc4(bytes(b ^ i for b in self.key), u)
        self.u = u + b"\x00" * 16

    def obj_key(self, num, gen):
        k = self.key + struct.pack("<i", num)[:3] + struct.pack("<i", gen)[:2]
        return hashlib.md5(k).digest()[: min(self.n + 5, 16)]

    def encrypt(self, num, data):
        return rc4(self.obj_key(num, 0), data)

    def dict(self):
        return (
            f"<< /Filter /Standard /V 2 /R 3 /Length 128 /P {self.p} "
            f"/O <{self.o.hex()}> /U <{self.u.hex()}> >>"
        )


# --------------------------------------------------------------------------
# Minimal PDF writer
# --------------------------------------------------------------------------


class Stream:
    def __init__(self, entries, data, compress=True):
        self.entries = entries
        self.data = data if isinstance(data, bytes) else data.encode("latin-1")
        self.compress = compress


class Pdf:
    def __init__(self, version="1.7"):
        self.version = version
        self.objects = {}
        self.next_id = 1
        self.info = None
        self.root = None

    def reserve(self):
        oid = self.next_id
        self.next_id += 1
        return oid

    def add(self, obj, oid=None):
        if oid is None:
            oid = self.reserve()
        self.objects[oid] = obj
        return oid

    def ref(self, oid):
        return f"{oid} 0 R"

    def text_string(self, s, num=None, security=None):
        try:
            raw = s.encode("latin-1")
        except UnicodeEncodeError:
            raw = b"\xfe\xff" + s.encode("utf-16-be")
        if security is not None:
            raw = security.encrypt(num, raw)
        return "<" + raw.hex() + ">"

    def _stream_bytes(self, oid, st, security):
        data = st.data
        entries = st.entries
        if st.compress:
            data = zlib.compress(data, 9)
            entries = entries + " /Filter /FlateDecode"
        if security is not None:
            data = security.encrypt(oid, data)
        head = f"<< {entries} /Length {len(data)} >>\nstream\n".encode("latin-1")
        return head + data + b"\nendstream"

    def write(self, path, object_streams=False, security=None, doc_id=None, xref_shift=0, truncate=None, prefix=b""):
        doc_id = doc_id or hashlib.md5(os.path.basename(path).encode()).digest()
        out = bytearray(prefix)
        out += f"%PDF-{self.version}\n%\xe2\xe3\xcf\xd3\n".encode("latin-1")
        offsets = {}
        encrypt_id = None
        if security is not None:
            encrypt_id = self.add(security.dict())

        if object_streams:
            plain = {k: v for k, v in self.objects.items() if not isinstance(v, Stream)}
            streams = {k: v for k, v in self.objects.items() if isinstance(v, Stream)}
            objstm_id = self.next_id
            self.next_id += 1
            header, body = [], b""
            for oid in sorted(plain):
                header.append(f"{oid} {len(body)}")
                body += plain[oid].encode("latin-1") + b"\n"
            head = (" ".join(header) + "\n").encode()
            st = Stream(f"/Type /ObjStm /N {len(plain)} /First {len(head)}", head + body)
            for oid in sorted(streams):
                offsets[oid] = len(out)
                out += f"{oid} 0 obj\n".encode() + self._stream_bytes(oid, streams[oid], None) + b"\nendobj\n"
            offsets[objstm_id] = len(out)
            out += f"{objstm_id} 0 obj\n".encode() + self._stream_bytes(objstm_id, st, None) + b"\nendobj\n"
            # cross-reference stream
            xref_id = self.next_id
            size = xref_id + 1
            rows = b""
            index_in_stm = {oid: i for i, oid in enumerate(sorted(plain))}
            for oid in range(size):
                if oid == 0:
                    rows += struct.pack(">BIH", 0, 0, 65535)
                elif oid in index_in_stm:
                    rows += struct.pack(">BIH", 2, objstm_id, index_in_stm[oid])
                elif oid == xref_id:
                    rows += struct.pack(">BIH", 1, len(out), 0)
                else:
                    rows += struct.pack(">BIH", 1, offsets[oid], 0)
            trailer = f"/Type /XRef /Size {size} /W [1 4 2] /Root {self.ref(self.root)}"
            if self.info:
                trailer += f" /Info {self.ref(self.info)}"
            trailer += f" /ID [<{doc_id.hex()}> <{doc_id.hex()}>]"
            xref_pos = len(out)
            out += f"{xref_id} 0 obj\n".encode() + self._stream_bytes(xref_id, Stream(trailer, rows), None) + b"\nendobj\n"
            out += f"startxref\n{xref_pos - len(prefix)}\n%%EOF\n".encode()
        else:
            for oid in sorted(self.objects):
                obj = self.objects[oid]
                offsets[oid] = len(out)
                enc = security if oid != encrypt_id else None
                if isinstance(obj, Stream):
                    body = self._stream_bytes(oid, obj, enc)
                else:
                    body = obj.encode("latin-1")
                out += f"{oid} 0 obj\n".encode() + body + b"\nendobj\n"
            xref_pos = len(out)
            size = max(self.objects) + 1
            out += f"xref\n0 {size}\n0000000000 65535 f \n".encode()
            for oid in range(1, size):
                if oid in offsets:
                    out += f"{offsets[oid] - len(prefix) + xref_shift:010d} 00000 n \n".encode()
                else:
                    out += b"0000000000 65535 f \n"
            trailer = f"/Size {size} /Root {self.ref(self.root)}"
            if self.info:
                trailer += f" /Info {self.ref(self.info)}"
            if encrypt_id:
                trailer += f" /Encrypt {self.ref(encrypt_id)}"
            trailer += f" /ID [<{doc_id.hex()}> <{doc_id.hex()}>]"
            out += f"trailer\n<< {trailer} >>\nstartxref\n{xref_pos - len(prefix) + xref_shift}\n%%EOF\n".encode()

        data = bytes(out)
        if truncate is not None:
            data = data[:truncate]
        with open(path, "wb") as f:
            f.write(data)


def simple_page(pdf, parent_ref_placeholder, content, resources="", extra=""):
    cid = pdf.add(Stream("", content))
    return f"<< /Type /Page /Parent {parent_ref_placeholder} /Contents {pdf.ref(cid)} /Resources << {resources} >> {extra} >>"


def build_flat(pdf, page_dicts, pages_extra="", info=None):
    """Adds a flat page tree. `page_dicts` contain the placeholder PARENT."""
    pages_id = pdf.reserve()
    kid_ids = [pdf.add(d.replace("PARENT", pdf.ref(pages_id))) for d in page_dicts]
    pdf.add(
        f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kid_ids)}] /Count {len(kid_ids)} {pages_extra} >>",
        pages_id,
    )
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(pages_id)} >>")
    if info:
        pdf.info = pdf.add(info)
    return pages_id


HELV = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"
A4 = rect(0, 0, 595.276, 841.89)


def text(font="F1", s="Hello", x=72, y=700, size=12):
    return f"BT /{font} {size} Tf {x} {y} Td ({s}) Tj ET\n"


# --------------------------------------------------------------------------
# Fixtures
# --------------------------------------------------------------------------


def single_a4(out):
    pdf = Pdf("1.4")
    f1 = pdf.add(HELV)
    page = simple_page(pdf, "PARENT", text(), f"/Font << /F1 {pdf.ref(f1)} >>", f"/MediaBox {A4}")
    build_flat(pdf, [page])
    pdf.info = pdf.add(
        f"<< /Title {pdf.text_string('Einseitiges A4 – Prüfdatei')} /Producer (pdf-preflight fixtures) "
        f"/CreationDate (D:20260101120000+01'00') >>"
    )
    pdf.write(os.path.join(out, "single_a4.pdf"))


def multipage(out):
    """Four pages with inherited attributes and an intermediate Pages node."""
    pdf = Pdf("1.7")
    f1 = pdf.add(HELV)
    res = f"/Font << /F1 {pdf.ref(f1)} >>"
    root_id = pdf.reserve()
    mid_id = pdf.reserve()
    p1 = pdf.add(simple_page(pdf, pdf.ref(root_id), text(s="Page 1"), res))
    p2 = pdf.add(simple_page(pdf, pdf.ref(root_id), text(s="Page 2"), res, f"/MediaBox {rect(0, 0, 841.89, 595.276)}"))
    p3 = pdf.add(simple_page(pdf, pdf.ref(root_id), text(s="Page 3"), res, f"/MediaBox {rect(0, 0, 419.528, 595.276)}"))
    p4 = pdf.add(simple_page(pdf, pdf.ref(mid_id), text(s="Page 4"), res))
    pdf.add(
        f"<< /Type /Pages /Parent {pdf.ref(root_id)} /Kids [{pdf.ref(p4)}] /Count 1 "
        f"/MediaBox {rect(0, 0, 841.89, 1190.551)} /CropBox {rect(10, 10, 831.89, 1180.551)} >>",
        mid_id,
    )
    pdf.add(
        f"<< /Type /Pages /Kids [{pdf.ref(p1)} {pdf.ref(p2)} {pdf.ref(p3)} {pdf.ref(mid_id)}] /Count 4 /MediaBox {A4} >>",
        root_id,
    )
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root_id)} >>")
    pdf.write(os.path.join(out, "multipage.pdf"))


def rotated(out):
    pdf = Pdf("1.7")
    root_id = pdf.reserve()
    mid_id = pdf.reserve()
    kids = []
    for rot in ["90", "180", "270", "-90", "45"]:
        kids.append(pdf.add(simple_page(pdf, pdf.ref(root_id), "", "", f"/Rotate {rot}")))
    inherited = pdf.add(simple_page(pdf, pdf.ref(mid_id), "", ""))
    pdf.add(f"<< /Type /Pages /Parent {pdf.ref(root_id)} /Kids [{pdf.ref(inherited)}] /Count 1 /Rotate 90 >>", mid_id)
    kids.append(mid_id)
    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count 6 /MediaBox {A4} >>", root_id)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root_id)} >>")
    pdf.write(os.path.join(out, "rotated.pdf"))


def boxes(out):
    pdf = Pdf("1.7")
    root_id = pdf.reserve()
    node_id = pdf.reserve()
    # Page 1: business card 85 x 55 mm with 3 mm bleed on a 99 x 71 mm sheet
    card = (
        f"/MediaBox {rect(0, 0, mm(99), mm(71))} "
        f"/BleedBox {rect(mm(4), mm(5), mm(95), mm(66))} "
        f"/TrimBox {rect(mm(7), mm(8), mm(92), mm(63))} "
        f"/ArtBox {rect(mm(10), mm(11), mm(89), mm(60))}"
    )
    p1 = pdf.add(simple_page(pdf, pdf.ref(root_id), "", "", card))
    # Page 2: only a MediaBox, all other boxes derived
    p2 = pdf.add(simple_page(pdf, pdf.ref(root_id), "", "", f"/MediaBox {A4}"))
    # Page 3: reversed TrimBox corners, BleedBox exceeding the MediaBox;
    # parent node carries a (non-inheritable) TrimBox
    p3 = pdf.add(
        simple_page(
            pdf,
            pdf.ref(node_id),
            "",
            "",
            f"/BleedBox {rect(-20, -20, 615.276, 861.89)} /TrimBox {rect(585.276, 831.89, 10, 10)}",
        )
    )
    p4 = pdf.add(simple_page(pdf, pdf.ref(node_id), "", ""))
    pdf.add(
        f"<< /Type /Pages /Parent {pdf.ref(root_id)} /Kids [{pdf.ref(p3)} {pdf.ref(p4)}] /Count 2 "
        f"/MediaBox {A4} /TrimBox {rect(0, 0, 100, 100)} >>",
        node_id,
    )
    # Page 5: UserUnit 2 -> A4 numbers describe an A2 sheet
    p5 = pdf.add(simple_page(pdf, pdf.ref(root_id), "", "", f"/MediaBox {A4} /UserUnit 2"))
    # Page 6: invalid MediaBox, page 7: no MediaBox at all
    p6 = pdf.add(simple_page(pdf, pdf.ref(root_id), "", "", "/MediaBox [0 0 /Foo 10]"))
    p7 = pdf.add(simple_page(pdf, pdf.ref(root_id), "", ""))
    kids = [p1, p2, node_id, p5, p6, p7]
    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count 7 >>", root_id)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root_id)} >>")
    pdf.write(os.path.join(out, "boxes.pdf"))


def stub_truetype():
    return b"\x00\x01\x00\x00" + b"\x00" * 60 + b"STUB-TRUETYPE-NO-GLYPHS"


def stub_cff():
    return bytes([1, 0, 4, 2]) + b"STUB-CFF-NO-GLYPHS" * 4


def fonts(out):
    pdf = Pdf("1.7")
    root = pdf.reserve()
    helv = pdf.add(HELV)

    ff2 = pdf.add(Stream(f"/Length1 {len(stub_truetype())}", stub_truetype()))
    fd_tt = pdf.add(
        f"<< /Type /FontDescriptor /FontName /ABCDEF+StubSans /Flags 32 /FontBBox [0 0 1000 1000] "
        f"/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 {pdf.ref(ff2)} >>"
    )
    tt = pdf.add(
        f"<< /Type /Font /Subtype /TrueType /BaseFont /ABCDEF+StubSans /FirstChar 32 /LastChar 32 "
        f"/Widths [500] /FontDescriptor {pdf.ref(fd_tt)} >>"
    )

    ff3 = pdf.add(Stream("/Subtype /CIDFontType0C", stub_cff()))
    fd_cid = pdf.add(
        f"<< /Type /FontDescriptor /FontName /StubCID /Flags 4 /FontBBox [0 0 1000 1000] "
        f"/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile3 {pdf.ref(ff3)} >>"
    )
    cid = pdf.add(
        f"<< /Type /Font /Subtype /CIDFontType0 /BaseFont /StubCID "
        f"/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor {pdf.ref(fd_cid)} >>"
    )
    type0 = pdf.add(
        f"<< /Type /Font /Subtype /Type0 /BaseFont /StubCID /Encoding /Identity-H /DescendantFonts [{pdf.ref(cid)}] >>"
    )

    glyph = pdf.add(Stream("", "500 0 0 0 500 500 d1 0 0 500 500 re f"))
    type3 = pdf.add(
        f"<< /Type /Font /Subtype /Type3 /FontBBox [0 0 500 500] /FontMatrix [0.001 0 0 0.001 0 0] "
        f"/CharProcs << /a {pdf.ref(glyph)} >> /Encoding << /Differences [97 /a] >> /FirstChar 97 /LastChar 97 /Widths [500] >>"
    )
    times = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman >>")
    invisible = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>")

    p1_res = (
        f"/Font << /F1 {pdf.ref(helv)} /F2 {pdf.ref(tt)} /F3 {pdf.ref(type0)} /F4 {pdf.ref(type3)} "
        f"/F5 {pdf.ref(times)} /F6 {pdf.ref(invisible)} >>"
    )
    p1_content = (
        text("F1", "Helvetica")
        + text("F2", " ", y=680)
        + "BT /F3 12 Tf 72 660 Td <0001> Tj ET\n"
        + text("F4", "a", y=640)
        + "BT 3 Tr /F6 12 Tf 72 620 Td (invisible) Tj ET\n"
    )
    p1 = pdf.add(simple_page(pdf, pdf.ref(root), p1_content, p1_res, f"/MediaBox {A4}"))

    # Page 2: font only used inside a form nested two levels deep
    courier = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>")
    inner = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4} /Resources << /Font << /F7 {pdf.ref(courier)} >> >>", text("F7", "nested")))
    outer = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4} /Resources << /XObject << /Inner {pdf.ref(inner)} >> >>", "q /Inner Do Q"))
    p2 = pdf.add(simple_page(pdf, pdf.ref(root), "q /Outer Do Q", f"/XObject << /Outer {pdf.ref(outer)} >>", f"/MediaBox {A4}"))

    # Page 3: broken embeddings
    fd_dangling = pdf.add(
        "<< /Type /FontDescriptor /FontName /Dangling /Flags 32 /FontBBox [0 0 1000 1000] "
        "/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 999 0 R >>"
    )
    dangling = pdf.add(f"<< /Type /Font /Subtype /TrueType /BaseFont /Dangling /FontDescriptor {pdf.ref(fd_dangling)} >>")
    wrong_ff = pdf.add(Stream("", stub_truetype()))
    fd_wrong = pdf.add(
        f"<< /Type /FontDescriptor /FontName /WrongProgram /Flags 32 /FontBBox [0 0 1000 1000] "
        f"/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile {pdf.ref(wrong_ff)} >>"
    )
    wrong = pdf.add(f"<< /Type /Font /Subtype /Type1 /BaseFont /WrongProgram /FontDescriptor {pdf.ref(fd_wrong)} >>")
    empty_ff = pdf.add(Stream("", b"", compress=False))
    fd_empty = pdf.add(
        f"<< /Type /FontDescriptor /FontName /EmptyProgram /Flags 32 /FontBBox [0 0 1000 1000] "
        f"/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 {pdf.ref(empty_ff)} >>"
    )
    empty = pdf.add(f"<< /Type /Font /Subtype /TrueType /BaseFont /EmptyProgram /FontDescriptor {pdf.ref(fd_empty)} >>")
    p3 = pdf.add(
        simple_page(
            pdf,
            pdf.ref(root),
            text("D1", "x") + text("W1", "y", y=680) + text("E1", "z", y=660),
            f"/Font << /D1 {pdf.ref(dangling)} /W1 {pdf.ref(wrong)} /E1 {pdf.ref(empty)} >>",
            f"/MediaBox {A4}",
        )
    )

    # Page 4: font via ExtGState, printable and non-printable annotations
    gs_font = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>")
    annot_font = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>")
    hidden_font = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Symbol >>")
    ap_print = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox [0 0 100 20] /Resources << /Font << /A1 {pdf.ref(annot_font)} >> >>", text("A1", "field", 2, 5)))
    ap_hidden = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox [0 0 100 20] /Resources << /Font << /H1 {pdf.ref(hidden_font)} >> >>", text("H1", "hidden", 2, 5)))
    annot1 = pdf.add(f"<< /Type /Annot /Subtype /Widget /Rect [72 500 172 520] /F 4 /AP << /N {pdf.ref(ap_print)} >> >>")
    annot2 = pdf.add(f"<< /Type /Annot /Subtype /Widget /Rect [72 400 172 420] /F 0 /AP << /N {pdf.ref(ap_hidden)} >> >>")
    p4 = pdf.add(
        simple_page(
            pdf,
            pdf.ref(root),
            "/GS1 gs BT 72 700 Td (via gs) Tj ET\n",
            f"/ExtGState << /GS1 << /Type /ExtGState /Font [{pdf.ref(gs_font)} 12] >> >>",
            f"/MediaBox {A4} /Annots [{pdf.ref(annot1)} {pdf.ref(annot2)}]",
        )
    )

    # Page 5: missing font resource, text in a tiling pattern
    pattern_font = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold >>")
    pattern = pdf.add(
        Stream(
            f"/Type /Pattern /PatternType 1 /PaintType 1 /TilingType 1 /BBox [0 0 50 50] /XStep 50 /YStep 50 "
            f"/Resources << /Font << /P1 {pdf.ref(pattern_font)} >> >>",
            text("P1", "pat", 0, 10, 8),
        )
    )
    p5 = pdf.add(
        simple_page(
            pdf,
            pdf.ref(root),
            text("Missing", "?") + "/Pattern cs /Pat1 scn 0 0 100 100 re f\n",
            f"/Pattern << /Pat1 {pdf.ref(pattern)} >>",
            f"/MediaBox {A4}",
        )
    )

    kids = [p1, p2, p3, p4, p5]
    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count {len(kids)} >>", root)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root)} >>")
    pdf.write(os.path.join(out, "fonts.pdf"))


def nested_forms(out):
    pdf = Pdf("1.7")
    root = pdf.reserve()
    courier = pdf.add("<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>")

    def chain(depth, leaf_content, leaf_res):
        child = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4} /Resources << {leaf_res} >>", leaf_content))
        for _ in range(depth - 1):
            child = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4} /Resources << /XObject << /X {pdf.ref(child)} >> >>", "q 0.9 0 0 0.9 10 10 cm /X Do Q"))
        return child

    font_res = f"/Font << /C {pdf.ref(courier)} >>"
    deep5 = chain(5, text("C", "deep"), font_res)
    p1 = pdf.add(simple_page(pdf, pdf.ref(root), "/X Do", f"/XObject << /X {pdf.ref(deep5)} >>", f"/MediaBox {A4}"))

    cyc = pdf.reserve()
    pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4} /Resources << /XObject << /Self {pdf.ref(cyc)} >> {font_res} >>", text("C", "loop") + "/Self Do"), cyc)
    p2 = pdf.add(simple_page(pdf, pdf.ref(root), "/Self Do", f"/XObject << /Self {pdf.ref(cyc)} >>", f"/MediaBox {A4}"))

    deep40 = chain(40, text("C", "too deep"), font_res)
    p3 = pdf.add(simple_page(pdf, pdf.ref(root), "/X Do", f"/XObject << /X {pdf.ref(deep40)} >>", f"/MediaBox {A4}"))

    logo = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox [0 0 10 10] /Resources << {font_res} >>", text("C", "logo", 0, 0, 4)))
    many = "".join(f"q 1 0 0 1 {i % 50 * 10} {i // 50 * 10} cm /L Do Q\n" for i in range(1000))
    p4 = pdf.add(simple_page(pdf, pdf.ref(root), many, f"/XObject << /L {pdf.ref(logo)} >>", f"/MediaBox {A4}"))

    # Form without own resources inherits those of the page
    nores = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {A4}", text("C", "inherited resources")))
    p5 = pdf.add(simple_page(pdf, pdf.ref(root), "/N Do", f"{font_res} /XObject << /N {pdf.ref(nores)} >>", f"/MediaBox {A4}"))

    kids = [p1, p2, p3, p4, p5]
    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count {len(kids)} >>", root)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root)} >>")
    pdf.write(os.path.join(out, "nested_forms.pdf"))


def objstm(out):
    pdf = Pdf("1.5")
    f1 = pdf.add(HELV)
    pages = [simple_page(pdf, "PARENT", text(s=f"ObjStm page {i}"), f"/Font << /F1 {pdf.ref(f1)} >>", f"/MediaBox {A4}") for i in (1, 2)]
    build_flat(pdf, pages)
    pdf.info = pdf.add("<< /Title (Object streams) /GTS_PDFXVersion (PDF/X-4) >>")
    pdf.write(os.path.join(out, "objstm.pdf"), object_streams=True)


def pdfx_declared(out):
    """Declares PDF/X-4 (Info, XMP, OutputIntent) without being conforming."""
    pdf = Pdf("1.6")
    f1 = pdf.add(HELV)
    page = simple_page(pdf, "PARENT", text(), f"/Font << /F1 {pdf.ref(f1)} >>", f"/MediaBox {A4} /TrimBox {A4}")
    pages_id = pdf.reserve()
    page_id = pdf.add(page.replace("PARENT", pdf.ref(pages_id)))
    pdf.add(f"<< /Type /Pages /Kids [{pdf.ref(page_id)}] /Count 1 >>", pages_id)
    xmp = (
        '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/">'
        '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
        '<rdf:Description rdf:about="" xmlns:pdfxid="http://www.npes.org/pdfx/ns/id/" pdfxid:GTS_PDFXVersion="PDF/X-4"/>'
        '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>'
    )
    meta = pdf.add(Stream("/Type /Metadata /Subtype /XML", xmp, compress=False))
    icc = pdf.add(Stream("/N 4", b"STUB-ICC-PROFILE"))
    pdf.root = pdf.add(
        f"<< /Type /Catalog /Pages {pdf.ref(pages_id)} /Metadata {pdf.ref(meta)} /OutputIntents [<< /Type /OutputIntent "
        f"/S /GTS_PDFX /OutputConditionIdentifier (FOGRA39) /RegistryName (http://www.color.org) /DestOutputProfile {pdf.ref(icc)} >>] >>"
    )
    pdf.info = pdf.add("<< /Title (PDF/X declared) /GTS_PDFXVersion (PDF/X-4) >>")
    pdf.write(os.path.join(out, "pdfx_declared.pdf"))


def encrypted(out):
    for name, user in [("encrypted_empty_password.pdf", ""), ("encrypted_user_password.pdf", "secret")]:
        doc_id = hashlib.md5(name.encode()).digest()
        sec = Security(user, "owner", doc_id)
        pdf = Pdf("1.6")
        f1 = pdf.add(HELV)
        page = simple_page(pdf, "PARENT", text(s="encrypted"), f"/Font << /F1 {pdf.ref(f1)} >>", f"/MediaBox {A4}")
        build_flat(pdf, [page])
        info_id = pdf.reserve()
        pdf.add(f"<< /Title {pdf.text_string('Verschlüsselt', info_id, sec)} >>", info_id)
        pdf.info = info_id
        pdf.write(os.path.join(out, name), security=sec, doc_id=doc_id)


def damaged(out):
    d = os.path.join(out, "damaged")
    os.makedirs(d, exist_ok=True)

    def base():
        pdf = Pdf("1.4")
        f1 = pdf.add(HELV)
        pages = [simple_page(pdf, "PARENT", text(s=f"page {i}"), f"/Font << /F1 {pdf.ref(f1)} >>", f"/MediaBox {A4}") for i in (1, 2)]
        build_flat(pdf, pages)
        return pdf

    base().write(os.path.join(d, "bad_xref_offsets.pdf"), xref_shift=7)
    base().write(os.path.join(d, "header_offset.pdf"), prefix=b"JUNK-BEFORE-HEADER\n")
    full = os.path.join(d, "_tmp.pdf")
    base().write(full)
    data = open(full, "rb").read()
    os.remove(full)
    open(os.path.join(d, "truncated.pdf"), "wb").write(data[: len(data) * 2 // 3])
    open(os.path.join(d, "no_eof.pdf"), "wb").write(data[: data.rfind(b"%%EOF")])
    open(os.path.join(d, "empty.pdf"), "wb").write(b"")
    open(os.path.join(d, "not_a_pdf.pdf"), "wb").write(b"This is plain text, not a PDF document.\n" * 20)
    open(os.path.join(d, "header_only.pdf"), "wb").write(b"%PDF-1.7\n%%EOF\n")


def bombs(out):
    # Content stream: 64 MiB of spaces, compresses to ~64 KiB.
    pdf = Pdf("1.4")
    payload = b" " * (64 * 1024 * 1024)
    page = simple_page(pdf, "PARENT", payload, "", f"/MediaBox {A4}")
    build_flat(pdf, [page])
    pdf.write(os.path.join(out, "bomb_content_stream.pdf"))

    # Object stream: padded with 64 MiB of whitespace.
    pdf = Pdf("1.5")
    page = simple_page(pdf, "PARENT", text(), "", f"/MediaBox {A4}")
    build_flat(pdf, [page])
    pdf.info = pdf.add("<< /Title (padding) >>" + " " * (64 * 1024 * 1024))
    pdf.write(os.path.join(out, "bomb_object_stream.pdf"), object_streams=True)


def business_cards(out):
    """Business cards 85 x 55 mm: a correct two-sided file and a faulty one."""

    def card(pdf, trim_w, trim_h, bleed, font_ref, label):
        media = (trim_w + 2 * bleed, trim_h + 2 * bleed)
        boxes = (
            f"/MediaBox {rect(0, 0, mm(media[0]), mm(media[1]))} "
            f"/BleedBox {rect(0, 0, mm(media[0]), mm(media[1]))} "
            f"/TrimBox {rect(mm(bleed), mm(bleed), mm(bleed + trim_w), mm(bleed + trim_h))}"
        )
        return simple_page(pdf, "PARENT", text("F1", label, 20, 40, 10), f"/Font << /F1 {pdf.ref(font_ref)} >>", boxes)

    pdf = Pdf("1.6")
    ff2 = pdf.add(Stream(f"/Length1 {len(stub_truetype())}", stub_truetype()))
    fd = pdf.add(
        f"<< /Type /FontDescriptor /FontName /QWERTY+StubSans /Flags 32 /FontBBox [0 0 1000 1000] "
        f"/ItalicAngle 0 /Ascent 800 /Descent -200 /CapHeight 700 /StemV 80 /FontFile2 {pdf.ref(ff2)} >>"
    )
    font = pdf.add(
        f"<< /Type /Font /Subtype /TrueType /BaseFont /QWERTY+StubSans /FirstChar 32 /LastChar 32 "
        f"/Widths [500] /FontDescriptor {pdf.ref(fd)} >>"
    )
    build_flat(pdf, [card(pdf, 85, 55, 3, font, "Front"), card(pdf, 85, 55, 3, font, "Back")])
    pdf.write(os.path.join(out, "business_card_ok.pdf"))

    pdf = Pdf("1.4")
    font = pdf.add(HELV)
    build_flat(pdf, [card(pdf, 90, 55, 1, font, "Wrong")])
    pdf.write(os.path.join(out, "business_card_bad.pdf"))


# --------------------------------------------------------------------------
# Minimal baseline JPEG encoder (8x8 blocks of constant colour, quantisation
# table of ones, DC-only). Sufficient to produce small, valid JPEG fixtures
# without third-party libraries.
# --------------------------------------------------------------------------

DC_BITS = [0, 1, 5, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0]
DC_VALS = list(range(12))


def _huffman_codes(bits, vals):
    codes, code, k = {}, 0, 0
    for length in range(1, 17):
        for _ in range(bits[length - 1]):
            codes[vals[k]] = (code, length)
            code += 1
            k += 1
        code <<= 1
    return codes


class _BitWriter:
    def __init__(self):
        self.out = bytearray()
        self.acc = 0
        self.n = 0

    def write(self, value, length):
        for i in range(length - 1, -1, -1):
            self.acc = (self.acc << 1) | ((value >> i) & 1)
            self.n += 1
            if self.n == 8:
                self.out.append(self.acc)
                if self.acc == 0xFF:
                    self.out.append(0)
                self.acc = 0
                self.n = 0

    def flush(self):
        while self.n:
            self.write(1, 1)
        return bytes(self.out)


def jpeg(width, height, pixel):
    """`pixel(x, y)` returns a tuple of 1 (gray) or 3 (RGB) ints; each 8x8
    block takes the colour of its top-left pixel."""
    ncomp = len(pixel(0, 0))
    seg = lambda marker, payload: struct.pack(">BBH", 0xFF, marker, len(payload) + 2) + payload
    out = b"\xff\xd8"
    out += seg(0xE0, b"JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00")
    out += seg(0xDB, b"\x00" + b"\x01" * 64)
    comps = b"".join(bytes([i + 1, 0x11, 0]) for i in range(ncomp))
    out += seg(0xC0, struct.pack(">BHHB", 8, height, width, ncomp) + comps)
    out += seg(0xC4, b"\x00" + bytes(DC_BITS) + bytes(DC_VALS))
    out += seg(0xC4, b"\x10" + bytes([1] + [0] * 15) + b"\x00")  # AC: EOB only, code "0"
    out += seg(0xDA, bytes([ncomp]) + b"".join(bytes([i + 1, 0]) for i in range(ncomp)) + b"\x00\x3f\x00")
    codes = _huffman_codes(DC_BITS, DC_VALS)
    bw = _BitWriter()
    pred = [0] * ncomp
    for by in range(0, height, 8):
        for bx in range(0, width, 8):
            px = pixel(bx, by)
            if ncomp == 3:
                r, g, b = px
                ycc = (
                    0.299 * r + 0.587 * g + 0.114 * b,
                    -0.168736 * r - 0.331264 * g + 0.5 * b + 128,
                    0.5 * r - 0.418688 * g - 0.081312 * b + 128,
                )
            else:
                ycc = px
            for c in range(ncomp):
                dc = int(round(8 * (round(ycc[c]) - 128)))
                diff = dc - pred[c]
                pred[c] = dc
                size = abs(diff).bit_length()
                code, length = codes[size]
                bw.write(code, length)
                if size:
                    bw.write(diff if diff > 0 else diff + (1 << size) - 1, size)
                bw.write(0, 1)  # EOB
    out += bw.flush() + b"\xff\xd9"
    return out


SMALL = rect(0, 0, 200, 200)


def colors(out):
    pdf = Pdf("1.7")
    root = pdf.reserve()
    helv = pdf.add(HELV)
    hks = pdf.add("[/Separation /HKS#2013#20K /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [1 0.5 0 0] /N 1 >>]")
    all_sep = pdf.add("[/Separation /All /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [1 1 1 1] /N 1 >>]")
    black_sep = pdf.add("[/Separation /Black /DeviceGray << /FunctionType 2 /Domain [0 1] /C0 [1] /C1 [0] /N 1 >>]")
    devn = pdf.add("[/DeviceN [/Black /Cyan] /DeviceCMYK << /FunctionType 4 /Domain [0 1 0 1] /Range [0 1 0 1 0 1 0 1] /Length 1 >>]")
    icc3 = pdf.add(Stream("/N 3 /Alternate /DeviceRGB", b"STUB-ICC-RGB"))
    icc1 = pdf.add(Stream("/N 1 /Alternate /DeviceGray", b"STUB-ICC-GRAY"))
    kids = []

    def page(content, resources=""):
        kids.append(pdf.add(simple_page(pdf, pdf.ref(root), content, resources, f"/MediaBox {SMALL}")))

    font = f"/Font << /F1 {pdf.ref(helv)} >>"
    # 1 gray only (DeviceGray, neutral RGB, K-only CMYK, text)
    page("0.5 g 0 0 50 50 re f 0.2 G 0 0 m 50 50 l S 0.5 0.5 0.5 rg 60 0 50 50 re f 0 0 0 1 k 120 0 50 50 re f "
         "0 g BT /F1 12 Tf 10 100 Td (gray) Tj ET", font)
    # 2 RGB red, 3 CMYK cyan
    page("1 0 0 rg 0 0 50 50 re f")
    page("1 0 0 0 k 0 0 50 50 re f")
    # 4 spot colour fill and registration (All) crop marks
    page("/CS0 cs 1 scn 0 0 50 50 re f /CS1 CS 1 SCN 0 0 m 10 0 l S",
         f"/ColorSpace << /CS0 {pdf.ref(hks)} /CS1 {pdf.ref(all_sep)} >>")
    # 5 Separation Black and DeviceN Black only
    page("/B cs 1 scn 0 0 50 50 re f /N cs 1 0 scn 60 0 50 50 re f",
         f"/ColorSpace << /B {pdf.ref(black_sep)} /N {pdf.ref(devn)} >>")
    # 6 colour only inside a nested form
    inner = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {SMALL}", "0 1 0 rg 0 0 10 10 re f"))
    outer = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {SMALL} /Resources << /XObject << /I {pdf.ref(inner)} >> >>", "/I Do"))
    page("0 g 0 0 5 5 re f /O Do", f"/XObject << /O {pdf.ref(outer)} >>")
    # 7 form paints with the colour inherited from the page
    plain = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {SMALL}", "0 0 50 50 re f"))
    page("0 0 1 rg /P Do", f"/XObject << /P {pdf.ref(plain)} >>")
    # 8 ICC (N=3 neutral, N=1) and Lab neutral
    page("/I3 cs 0.3 0.3 0.3 sc 0 0 10 10 re f /I1 cs 0.5 sc 20 0 10 10 re f /L cs 50 0 0 sc 40 0 10 10 re f",
         f"/ColorSpace << /I3 [/ICCBased {pdf.ref(icc3)}] /I1 [/ICCBased {pdf.ref(icc1)}] "
         f"/L [/Lab << /WhitePoint [0.9505 1 1.089] /Range [-100 100 -100 100] >>] >>")
    # 9 Indexed with a gray palette
    page("/X cs 1 sc 0 0 10 10 re f", "/ColorSpace << /X [/Indexed /DeviceRGB 1 <000000808080>] >>")
    # 10 empty page
    page("")
    # 11 coloured axial shading, 12 neutral axial shading (in DeviceRGB)
    page("/Sh sh", "/Shading << /Sh << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 200 0] "
         "/Function << /FunctionType 2 /Domain [0 1] /C0 [1 0 0] /C1 [0 0 1] /N 1 >> >> >>")
    page("/Sh sh", "/Shading << /Sh << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 200 0] "
         "/Function << /FunctionType 2 /Domain [0 1] /C0 [0 0 0] /C1 [1 1 1] /N 1 >> >> >>")
    # 13 coloured tiling pattern with red content
    pat = pdf.add(Stream("/Type /Pattern /PatternType 1 /PaintType 1 /TilingType 1 /BBox [0 0 10 10] /XStep 10 /YStep 10 /Resources << >>",
                         "1 0 0 rg 0 0 5 5 re f"))
    page("/Pattern cs /P1 scn 0 0 100 100 re f", f"/Pattern << /P1 {pdf.ref(pat)} >>")
    # 14 RGB colour space declared but only gray used
    page("0 g 0 0 10 10 re f", "/ColorSpace << /Unused /DeviceRGB /UnusedSpot " + pdf.ref(hks) + " >>")
    # 15 invisible red text
    page("1 0 0 rg BT 3 Tr /F1 12 Tf 10 100 Td (invisible) Tj ET", font)
    # 16 RGB shading with a sampled function (cannot be evaluated)
    samp = pdf.add(Stream("/FunctionType 0 /Domain [0 1] /Range [0 1 0 1 0 1] /Size [2] /BitsPerSample 8", b"\xff\x00\x00\x00\x00\xff", compress=False))
    page("/Sh sh", f"/Shading << /Sh << /ShadingType 2 /ColorSpace /DeviceRGB /Coords [0 0 200 0] /Function {pdf.ref(samp)} >> >>")
    # 17 uncoloured tiling pattern painted in gray; its red operator is ignored
    upat = pdf.add(Stream("/Type /Pattern /PatternType 1 /PaintType 2 /TilingType 1 /BBox [0 0 10 10] /XStep 10 /YStep 10 /Resources << >>",
                          "1 0 0 rg 0 0 5 5 re f"))
    page("/PG cs 0.5 /P2 scn 0 0 100 100 re f", f"/ColorSpace << /PG [/Pattern /DeviceGray] >> /Pattern << /P2 {pdf.ref(upat)} >>")

    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count {len(kids)} >>", root)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root)} >>")
    pdf.write(os.path.join(out, "colors.pdf"))


def images(out):
    pdf = Pdf("1.7")
    root = pdf.reserve()
    kids = []

    def page(content, resources=""):
        kids.append(pdf.add(simple_page(pdf, pdf.ref(root), content, resources, f"/MediaBox {SMALL}")))

    def image(w, h, cs, bpc, data, extra="", compress=True):
        return pdf.add(Stream(f"/Type /XObject /Subtype /Image /Width {w} /Height {h} /ColorSpace {cs} /BitsPerComponent {bpc} {extra}", data, compress))

    gray_rgb = image(300, 300, "/DeviceRGB", 8, bytes(v for y in range(300) for x in range(300) for v in [(x + y) % 256] * 3))
    color_rgb = image(100, 100, "/DeviceRGB", 8, bytes(v for y in range(100) for x in range(100) for v in (x % 256, 0, y % 256)))
    gray8 = image(50, 50, "/DeviceGray", 8, bytes((x * 5) % 256 for y in range(50) for x in range(50)))
    mask = pdf.add(Stream("/Type /XObject /Subtype /Image /Width 16 /Height 16 /ImageMask true /BitsPerComponent 1", b"\xaa\x55" * 16))
    jpeg_gray = image(16, 16, "/DeviceRGB", 8, jpeg(16, 16, lambda x, y: (x * 8, x * 8, x * 8)), "/Filter /DCTDecode", compress=False)
    jpeg_color = image(16, 16, "/DeviceRGB", 8, jpeg(16, 16, lambda x, y: (200, 30 + x * 4, 40)), "/Filter /DCTDecode", compress=False)
    jpeg_luma = image(16, 16, "/DeviceGray", 8, jpeg(16, 16, lambda x, y: (x * 10,)), "/Filter /DCTDecode", compress=False)
    indexed = image(4, 1, "[/Indexed /DeviceRGB 2 <000000FF0000808080>]", 8, bytes([0, 2, 2, 0]))
    bitonal = image(16, 16, "/DeviceGray", 1, b"\xf0\x0f" * 16)
    cmyk_k = image(10, 10, "/DeviceCMYK", 8, bytes([0, 0, 0, 200] * 100))

    # 1 neutral RGB image at 1 inch: 300 dpi, page grayscale
    page("q 72 0 0 72 10 10 cm /Im Do Q", f"/XObject << /Im {pdf.ref(gray_rgb)} >>")
    # 2 the same image at 1 and 2 inches
    page("q 72 0 0 72 0 0 cm /Im Do Q q 144 0 0 144 50 50 cm /Im Do Q", f"/XObject << /Im {pdf.ref(gray_rgb)} >>")
    # 3 coloured image, non-uniform scaling: 50 x 100 dpi
    page("q 144 0 0 72 0 0 cm /C Do Q", f"/XObject << /C {pdf.ref(color_rgb)} >>")
    # 4 image in a scaled form (300 dpi) and rotated by 90 degrees (300 dpi)
    form = pdf.add(Stream(f"/Type /XObject /Subtype /Form /BBox {SMALL} /Matrix [0.5 0 0 0.5 0 0] /Resources << /XObject << /Im {pdf.ref(gray_rgb)} >> >>",
                          "q 144 0 0 144 0 0 cm /Im Do Q"))
    page("/F Do q 0 72 -72 0 150 10 cm /Im Do Q", f"/XObject << /F {pdf.ref(form)} /Im {pdf.ref(gray_rgb)} >>")
    # 5 gray image and a stencil mask painted red
    page("q 50 0 0 50 0 0 cm /G Do Q 1 0 0 rg q 16 0 0 16 100 100 cm /M Do Q", f"/XObject << /G {pdf.ref(gray8)} /M {pdf.ref(mask)} >>")
    # 6 neutral RGB JPEG, 7 coloured RGB JPEG, 8 grayscale JPEG
    page("q 16 0 0 16 0 0 cm /J Do Q", f"/XObject << /J {pdf.ref(jpeg_gray)} >>")
    page("q 16 0 0 16 0 0 cm /J Do Q", f"/XObject << /J {pdf.ref(jpeg_color)} >>")
    page("q 16 0 0 16 0 0 cm /J Do Q", f"/XObject << /J {pdf.ref(jpeg_luma)} >>")
    # 9 image placed entirely outside the page
    page("q 50 0 0 50 300 300 cm /G Do Q", f"/XObject << /G {pdf.ref(gray8)} >>")
    # 10 coloured inline image
    page("q 20 0 0 20 0 0 cm BI /W 2 /H 2 /CS /RGB /BPC 8 ID \xff\x00\x00\x00\xff\x00\x00\x00\xff\xff\xff\xff EI Q")
    # 11 indexed image with a red palette entry that is never used
    page("q 40 0 0 10 0 0 cm /X Do Q", f"/XObject << /X {pdf.ref(indexed)} >>")
    # 12 1-bit gray scan, 13 CMYK image with K only
    page("q 16 0 0 16 0 0 cm /B Do Q", f"/XObject << /B {pdf.ref(bitonal)} >>")
    page("q 10 0 0 10 0 0 cm /K Do Q", f"/XObject << /K {pdf.ref(cmyk_k)} >>")

    pdf.add(f"<< /Type /Pages /Kids [{' '.join(pdf.ref(k) for k in kids)}] /Count {len(kids)} >>", root)
    pdf.root = pdf.add(f"<< /Type /Catalog /Pages {pdf.ref(root)} >>")
    pdf.write(os.path.join(out, "images.pdf"))


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))
    os.makedirs(out, exist_ok=True)
    for fn in [single_a4, multipage, rotated, boxes, fonts, nested_forms, objstm, pdfx_declared, encrypted, damaged, bombs, business_cards, colors, images]:
        fn(out)


if __name__ == "__main__":
    main()
