# Image pipeline

Every image the storefront shows is generated, not uploaded. Card scans arrive on
a white sweep, set logos arrive on whatever plate the source exported, and both
have to end up on a transparent background so they sit on a dark page without a
box drawn around them.

`src/ops/lib/cutout.php` is the shared floor. `src/ops/media/*` are the passes that use
it. Nothing else should touch GD directly.

---

## 1. The two rules that break everything

**GD's alpha channel is inverted relative to every other library.**

```
GD:   0 = opaque, 127 = transparent      (7-bit)
PIL:  0 = transparent, 255 = opaque      (8-bit)
```

Reading a probe script written in Python and applying its conclusion to PHP —
or the reverse — produces confident nonsense. Both conventions appear in this
repo's tooling, so state which one a number is in whenever it matters.

**Everything is written as PNG into a `.jpg` path.**

`cutoutSave()` calls `imagepng()` regardless of the filename. The extension is
PrestaShop's convention and its templates depend on it; the bytes are PNG,
because JPEG cannot carry an alpha channel at all. This is why
`src/ops/assets/card-back.jpg` could never have had a transparent background no
matter what the source asset was.

Corollary: **never use `ImageManager::resize()`** on anything in this pipeline.
It composites onto an opaque canvas and puts the background straight back. Use
`cutoutResize()`, which is alpha-preserving.

---

## 2. Two shapes of cutout

A card and a logo are different problems and get different functions.

| Function | Subject | Method |
|---|---|---|
| `cutoutCard()` | A card — a known rounded rectangle | Crop to the content box, then round the corners *geometrically* |
| `cutoutLogo()` | A logo — an arbitrary silhouette | Sample the background at the corners, flood-fill inwards with a tolerance |

The card corner is computed rather than flood-filled on purpose: a geometric
radius cuts every card to the same silhouette, where a flood fill follows
whatever the scanner happened to leave and gives each card slightly different
corners.

### Background removal, and when it must refuse

`cutoutLogo()` sums the RGB distance from the sampled background and fades across
a band (`CUTOUT_TOLERANCE` → `CUTOUT_FEATHER_MAX`) rather than flipping pixels
on a threshold. A logo's edge is anti-aliased — a yes/no cut either keeps a halo
of background colour or leaves stair-steps, and both were visible on the set
tiles.

Each corner is walked **diagonally inward** to the first opaque pixel, because a
plate is not always flush with the edge — plenty of wiki logos are transparent
PNGs wrapping an opaque rectangle, and reading the literal corner samples the
margin instead of the plate that needs removing.

It then **refuses** unless all four corners found an opaque pixel *and they
agree* within tolerance. Both halves are load-bearing:

- `imagecolorat()` reports a colour for a transparent pixel too — almost always
  black — so without the first half a fully transparent image yields a black
  "background" and the flood erases every dark pixel it can reach.
- Without the second half, walking inward on already-cut artwork samples the
  *artwork* and erases that.

A real plate gives four matching readings. A photograph or a silhouette gives
four different ones and is left alone — which is why box shots of sealed product
(the wiki's fallback art for decks and gift sets) keep their packaging edge.

### Two traps that cost real time

**`imagecrop()` destroys the alpha channel.** It builds its destination with
`imagecreatetruecolor()` — filled with opaque black — and does not carry alpha
across. Crop a transparent logo and every transparent pixel comes back solid
black. Copy onto a `cutoutCanvas()` instead.

This one hid for a long time because it only struck images that were *already*
transparent: anything freshly through `cutoutLogo()` carried a canvas that
survived the crop, so the bug appeared to be random.

**Palette images convert to truecolour before anything touches them.** An
indexed PNG carries one fully transparent palette slot, so its transparency is
all-or-nothing and its edges are hard by construction. `cutoutLoad()` converts up
front; any path that builds an image from a byte string
(`imagecreatefromstring()`) must do the same by hand.

**The flood fill is memory-bounded.** It marks pixels seen at *push* time, into a
byte string rather than a PHP array. Queuing a pixel per neighbour and
deduplicating on pop held millions of pending arrays and exhausted a 512 MB limit
on the real logo corpus.

---

## 3. Slab frames

`src/ops/media/make-slab-frames.php` generates one frame per grader per grade — 52 of
them — into `src/ops/assets/slabs/`.

Each grading company gets its own **template photograph** of its holder rather
than a recoloured PSA one: a buyer looking at a listing should see the holder
they are actually being sent. Within a company the holder never changes, only the
tier name and the numeral, so every grade is derived from that company's template
by erasing those two boxes and redrawing them.

Company marks, barcodes and subgrade rows are never touched — they are part of
the holder, and a label missing its barcode reads as counterfeit at a glance.

Some labels exist at exactly one grade and are copied **verbatim**: Beckett's gold
Pristine and Black Label, and CGC's gold Pristine, all of which exist only at 10.
The numerals are part of those designs, not a field.

Erasing a box has three requirements, each learned the hard way:

- **Blending off.** TAG's label is not a black rectangle, it is *transparent*,
  with only the white line-art opaque. With blending on, writing a transparent
  pixel composites to nothing and the old grade survives underneath the new one.
- **Interpolate between the box's own edges.** Beckett's silver and CGC's white
  both run a horizontal gradient, so a single sampled fill — even a median —
  paints a visibly lighter rectangle.
- **Sample each box's own edges, not a shared pair.** Beckett's silver is 155 grey
  beside the tier text and 210 beside the numeral.

Text is positioned by **baseline**, not by the box: `imagettfbbox()` index 7 is
the top (negative) and index 1 the bottom. Centring on height instead of that top
edge draws text a full line too high.

Also: PHP coerces numeric array keys to int, so a grade of `'10'` arrives as
`10` and must be cast back before it is used in a filename.

**SGC and ACE have no template and therefore no frames.** `slabFramePath()`
returns null and the listing falls back to a plain scan — deliberately, because
dressing a card in a competitor's holder misrepresents the item.

## 4. Slab photos

`src/ops/media/slab-photos.php` composites each graded copy's card scan into the frame
for *its* grader and grade, and wires the result to that combination.

It covers the whole catalogue, not a fixture list, and is **self-healing**: a
listing is re-shot when its photo is older than the frame it was cut from. So
regenerating artwork (`make slab-frames`) and running `make slab-photos` is
enough — nobody has to remember which cards used which artwork.

The card window is found by probing for the longest transparent run on a row
clear of the label, then the column through its centre.

---

## 5. Card backs

Backs are region- and date-dependent, resolved by `src/ops/lib/cardback.php`:

| Back | Applies to |
|---|---|
| Western blue Poké Ball | Every Western printing, unchanged since 1996 |
| Japanese "Pocket Monsters" (©1996) | Japanese sets released **before 2002** |
| Japanese yellow-bordered | Japanese sets **2002 onward** |

Resolved on the set's release date, not its era: the Japanese back changed in
2002, which lands *inside* the e-Card block, so an era mapping puts the wrong
back on part of it.

**Cards only.** A back is a fact about a card, and a photograph of a card back on
a sealed booster pack is a picture of something that is not the product.
Membership is established by `cc_card_identity` — *not* by being serialised,
which meant the same thing until sealed product was serialised too.

Chinese and Korean backs are deliberately absent rather than approximated.

---

## 6. Category and navigation artwork

Set logos come from pokemontcg.io (Western) and the Bulbagarden Archives
(Japanese, indexed by set code — the one identifier unambiguous across
languages). `src/ops/media/seed-category-images.php` writes the category image;
`src/ops/media/seed-nav-images.php` writes the menu and homepage tiles.

Both **remove a flat background before trimming**. Trimming alone does not help:
it looks for transparent *margins*, and an image that is opaque everywhere has
none, so the plate survives into the file and renders as a white box on a dark
tile.

Homepage and nav section tiles are **fixed promotional artwork**, not derived
from stock — they advertise a section rather than report its contents.

A set with no logo of its own falls back down a cascade (own logo → block logo →
the Western era of the same generation), and borrowed art is rendered dimmer so
the grid does not imply it is that set's real wordmark.
