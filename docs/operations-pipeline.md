# DoubleSleeve — pricing, intake and inventory architecture

A plan, not yet built. Four systems that together are the actual product:

1. **Price engine** — multi-source, confidence-gated, refreshed every 12h
2. **Serialised inventory** — every physical copy is its own record, with a QR
3. **Intake pipeline** — photos in, sellable stock out, in seconds per card
4. **Taxonomy sync** — new sets, rarities and attributes land without a developer

The unifying idea: **the physical card is the unit of record, not the printing.**
Everything else follows from that.

---

## 0. Why not Collectr

Collectr publishes no developer API — the "Collectr APIs" you can find are
third-party scrapers of an undocumented endpoint. Setting the terms-of-service
problem aside, Collectr's own prices are aggregated from TCGplayer and eBay, so
consuming it would mean taking a fragile second-hand copy of feeds available
first-hand. This plan goes to those sources directly.

---

## 1. Price engine

### 1.1 Sources

| Source | What it gives | Cost / access | Role |
|---|---|---|---|
| **TCGCSV** | Daily mirror of TCGplayer catalogue + market prices | Free, no key | **Baseline anchor** (already wired in) |
| **TCGplayer API** | Market / low / mid / high / direct-low, live | Application + approval, partner-oriented | Upgrade path from TCGCSV |
| **pokemontcg.io** | TCGplayer + Cardmarket snapshots per card | Free, keyed | Cross-check, already wired in |
| **eBay Browse / Marketplace Insights** | Active listings, and *sold* comps with approval | Free tier + approval for sold data | **Reality check** — what things actually sell for |
| **PriceCharting** | Graded per-tier estimates + sold comps | Free (scraped) | Card pages carry per-tier sold-listing tables; see `src/ops/lib/graded-quotes.php` |
| **130point** | eBay sold listings | Free (scraped) | Freshest graded sales; rate-limits by IP, engine degrades gracefully |
| **Cardmarket** | European prices in EUR | Keyed | Only if you sell into the EU |
| **JustTCG / tcgapi.dev** | Commercial aggregated TCG pricing | Paid subscription | Shortcut if you'd rather buy than build |

Start with **TCGCSV + pokemontcg.io** (free, already integrated), add **eBay sold**
for corroboration, add **PriceCharting** when graded inventory justifies it.

### 1.2 Do not naively average

Averaging these is the obvious move and it is wrong. They measure different
populations:

- TCGplayer "market" is *already* an average of recent TCGplayer sales
- eBay sold includes shipping games, auction noise and mixed conditions
- Cardmarket is a different continent, currency and supply pool

Mean-averaging incompatible distributions produces a number that describes nothing.
Worse, one broken feed drags every price with it.

**Anchor + corroboration, not a blend.** This was the biggest correction to come
out of the first dry run: TCGplayer and Cardmarket disagreed by **55% on average**
across the catalogue. That is not two sources arguing about one number — it is two
different economies. You sell into North America in CAD, so:

```
1. Normalise      → every source to CAD via Bank of Canada FX
2. Anchor          → TCGplayer family sets the price (the market you sell in)
                     TCGCSV and pokemontcg.io both mirror TCGplayer: one vote, not two
3. Corroborate     → Cardmarket moves CONFIDENCE, never the price
4. Confidence      → f(anchor present, corroboration agreement, spread)
5. Pricing policy  → market × margin × FX buffer
6. Gate            → auto-publish, or queue for review
```

**Confidence gate** is the piece that lets you sleep:

| Confidence | Condition | Action |
|---|---|---|
| High | TCGplayer anchor + Cardmarket within 45% | Auto-publish |
| Medium | Anchor + Cardmarket within 120%, **or** anchor with no Cardmarket equivalent (all sealed) | Auto-publish **only** if change ≤10% |
| Low | No TCGplayer anchor, or Cardmarket disagrees >120% | Queue for human review |

The cross-market tolerances look enormous next to a normal price-comparison system.
They are correct here: a 40% NA/EU gap on the same card is ordinary. What a >120%
gap usually means is that the two sources matched **different printings** — which is
exactly the case a human should see.

### 1.3 Guard rails (non-negotiable)

- **Change clamp** — never move a price more than ±15% in one cycle. A feed glitch
  should cost you a day, not your margin.
- **Floor** — never below `cost_basis`, and never below the minimum viable listing
  price. Note it must **raise the price to the floor**, not skip the update: the
  first implementation skipped, which left bulk commons sitting at their old $0.01
  price — i.e. below the very floor the guard existed to enforce.
- **Sanity ceiling** — reject >5× jumps outright; they are almost always a
  mis-matched product, not a real spike. (In the first dry run this caught 29 of
  them — all caused by the printing-variant bug in §1.7, not by the market.)
- **Percentage guards only above $2** — a 1¢ bulk common moving to 21¢ is not a
  "2000% move", it is 20¢ of floor noise. Below the threshold, judge in dollars.
- **Freeze flag** — per-product opt-out for anything hand-priced.
- **Full journal** — every change writes `(product, old, new, sources, confidence,
  timestamp)`. Auditable and revertible in one query. This table is also your
  margin analytics later.
- **Dry-run mode** — first two weeks, run the engine and journal *proposed* changes
  without applying any. Compare against your judgement before handing it the keys.

### 1.4 Pricing policy layer

Market price is an input, not your price:

```
your_price = market_nm
           × condition_multiplier      (NM 1.00, LP .85, MP .70, HP .55, DMG .40)
           × velocity_rule             (fast movers at market, stale stock stepped down)
           × margin_floor              (never below cost + fees)
```

**Velocity rule** is where money is made: anything unsold after 60 days steps down
5% per cycle until it moves. Anything selling within 48h steps *up*. This runs
itself once the journal exists.

### 1.5 FX

**The storefront currency switcher is PrestaShop's own** (`ps_currencyselector`);
nothing custom was built. It was inert for two config reasons, both fixed:

- `PS_CURRENCY_FEATURE_ACTIVE` was never set, so PrestaShop treated the shop as
  single-currency and the switcher could not change anything. `setup.php` now
  sets it.
- USD sat at `conversion_rate = 1.000000`, identical to CAD, so even with the
  feature on every price rendered the same number with a different label.

`src/ops/pricing/currency-sync.php` sets the rates and runs from cron at **00:05 and
12:05**, deliberately ahead of the price engine at :15 — both read the same Bank
of Canada rate, and a stale `conversion_rate` mis-prices every USD order. If Valet
is unreachable it falls back to the last rate cached in `price_fx` rather than
leaving prices wrong.

Direction matters: Valet quotes `FXUSDCAD` as **CAD per 1 USD**, PrestaShop wants
**units per 1 unit of the default currency**. The rate is the reciprocal —
inverting it would price every US order roughly 94% too high.



Prices arrive in USD; CAD is your default currency. Pull the daily rate from the
**Bank of Canada Valet API** (free, authoritative, no key) rather than a scraped
rate, and hold a small buffer (~2%) so intra-day FX movement doesn't erode margin.

### 1.6 Schedule

Every 12h, staggered so sources aren't all hit at once:

```
00:00  FX rate refresh
00:15  TCGCSV pull → staging
00:45  pokemontcg.io + eBay corroboration
01:15  blend, score, gate → apply or queue
01:45  reindex Meilisearch, invalidate caches
```

Same again at 12:00. Every job idempotent, journaled, and alerting on failure —
a silently dead price job is worse than no price job.

### 1.7 Printing variants are part of a card's identity

The dry run surfaced a catalogue gap, not a feed problem. Across the 12 seeded sets:

| Printing variant | Cards |
|---|---|
| reverseHolofoil | 1,055 |
| normal | 1,009 |
| holofoil | 802 |
| 1stEdition / unlimited | 160 each |
| 1stEditionHolofoil / unlimitedHolofoil | 49 each |

**1,265 of 2,019 cards (63%) exist as two or more printings.** The dominant pair is
`normal + reverseHolofoil` — that is every modern set, where the plain and reverse
holo are separate products trading at different prices. On vintage it is
`1st Edition + unlimited`, where the gap is often 10×.

The engine now pins each product to one printing (`variant_key` /
`tcgplayer_subtype` in the source map) and reads only that printing's price.
Before that fix it took `max()` across a card's variants, which priced a $2.39
Base Set Haunter at $26.84 off its 1st-edition-holo variant. Twenty-nine products
were wrong that way; the sanity guard caught every one, and after the fix zero
products trip it.

> **Status: built.** `make align` (vocabularies), `make sets-align` (set taxonomy),
> `make sku-rebuild` (Printing made price-bearing). See "Alignment with TCGplayer"
> in the README for exactly what does and does not match.

#### The axis is called **Printing**, not "Finish"

"Finish" is Magic/Scryfall vocabulary (nonfoil / foil / etched). The Pokémon trade
term — and the label on TCGplayer's own product pages, and the concept behind their
API's `subTypeName` — is **Printing**. The shop's attribute group should be renamed
to match what buyers already read on every other site.

The complete vocabulary, counted across 19 TCGplayer groups spanning every era from
WotC to Scarlet & Violet, is exactly **seven** values:

| Printing | Occurrences |
|---|---|
| Normal | 1,966 |
| Reverse Holofoil | 1,567 |
| Holofoil | 1,190 |
| 1st Edition | 247 |
| Unlimited | 247 |
| 1st Edition Holofoil | 65 |
| Unlimited Holofoil | 64 |

Our current `Finish` attribute is partly invented — it carries `Cosmos Holo`,
`Poke Ball Pattern`, `Master Ball Pattern` and `Shadowless`, none of which are
printings in the source data. The pattern reverse-holos are separate *products* on
TCGplayer, not printings, and Shadowless is a set (below).

#### 1st Edition behaves the same way; Shadowless does not

- **1st Edition / Unlimited → printings.** Identical mechanism to Normal vs
  Reverse Holofoil: same product, different `subTypeName`, different price. They
  need no special handling.
- **Shadowless → a set.** TCGplayer models it as its own group,
  `Base Set (Shadowless)`, and it is the **only** variant print-run group among all
  217 Pokémon groups. It is a one-off, not a pattern to generalise from.

TCGplayer runs the two Base Set groups **in parallel**, not as a split: group 604
`Base Set` holds 109 products and group 1663 `Base Set (Shadowless)` holds 111, with
100 card names in common. Charizard 004/102 exists in *both*, at very different
values. So a shadowless card is a **separate product in a separate set**, carrying
its own printings, stock and price — not a variant of the shadowed one.

Two consequences that are easy to get wrong:

1. **The set must be stated on the product page.** Breadcrumb alone is not enough
   when two products share a name *and* a card number. Every product therefore
   carries a `Set` feature showing the TCGplayer group name, and Set is a facet.
2. **References must key on the TCGplayer group abbreviation** (`BS` vs `BSS`), not
   on a set code borrowed from another catalogue. `PKM-BS-4` built from card number
   alone would collide across the two groups, and because the price source map is
   keyed on reference, the second card would silently inherit the first one's
   prices. `make price-setup` now fails loudly on duplicate references.

Mirroring TCGplayer here is not deference for its own sake: it is simultaneously
the buyer's mental model *and* our price source's schema, so the source map stays a
1:1 lookup instead of a translation layer that has to be maintained.

**The remaining gap is in the catalogue, not the engine.** Today there is one
product per card number, carrying one price. That cannot represent a card whose
normal printing sells for $0.30 and whose reverse holo sells for $3.00. The fix:

- rename the `Finish` attribute group to **Printing** and reduce its values to the
  seven above (moving `Shadowless` out to a set category under Base);
- keep one product page per card — the SEO and the buyer's mental model both want
  "Charizard — Base 4/102", not three near-identical pages;
- make **Printing** a *price-bearing* dimension: each printing combination carries
  its own price impact, sourced from its own `subTypeName`;
- extend the source map to one row per **(product, printing)** instead of per
  product, keyed on `subTypeName` — the engine already selects by variant, so this
  is widening the key, not rewriting the logic.

Until that lands, each product is priced correctly for **the single printing it was
seeded as**, and any other printing of that card cannot be listed at its own price.

---

## 2. Serialised inventory — "pick the exact card"

This is the differentiator. TCGplayer sends you *a* Near Mint copy. You will show
customers **the** copy and let them choose.

### 2.1 Why the current model can't do it

Standard e-commerce assumes fungibility: `product + combination = quantity N`. Any
NM copy is interchangeable. Per-copy selection breaks that assumption.

The naive fix — one product per physical card — destroys the catalogue: ten NM
Charizards become ten product pages competing for the same search term.

### 2.2 The model

**What gets serialised: everything sellable.** Singles raw, singles graded, and
sealed product alike — anything that can carry a photograph of the individual
item gets a serial. A graded copy is serialised as the combination it lives on,
so two PSA 10s of one card are two copies of one SKU, each photographable.
Sealed product has no combinations at all and is serialised against the product
itself (`id_product_attribute = 0`).

That last case is easy to lose: PrestaShop keeps a `stock_available` row at
`id_product_attribute = 0` for *every* product, holding the running total for
products that have combinations. So "serialise the stock" means *combinations,
plus products that have none* — never a flat `id_product_attribute > 0`, which
silently excludes all sealed, and never both, which double-counts everything
else.

Keep the product page as the printing. Add a copy layer beneath it.

```
Product           Charizard — Base 4/102          (one page, keeps its SEO)
 └ Combination    NM / English / Holofoil         (price + condition basis)
    └ Copy #A7K2QX   photos, cost basis, location, QR
    └ Copy #B3M8LP   photos, cost basis, location, QR
    └ Copy #C9F1TR   photos, cost basis, location, QR
```

```sql
cc_card_copy
  id_copy, copy_uid          -- short opaque code, printed as QR
  id_product, id_product_attribute
  condition
  photo_front, photo_back
  cost_basis, acquired_at, acquired_from
  status                     -- available | reserved | sold | returned | quarantined
  reserved_until, id_cart, id_order
                             -- NB: id_cart is stamped at CHECKOUT entry, not when
                             -- the item is added to a cart. See 2.3.
  location                   -- box / row / sleeve position
```

**Stock quantity becomes derived**, not stored: `COUNT(*) WHERE status='available'`.
A listener mirrors that into PrestaShop's `stock_available` so checkout, facets and
the storefront keep working normally.

### 2.3 Reservation flow — reserve at CHECKOUT, not at add-to-cart

**The cart reserves nothing.** Stock is only held once a customer actually enters
checkout, for a configurable window (default 30 minutes). This is the corrected
model; the first implementation reserved on `actionCartSave`, which is wrong.

```
add to cart      → NO reservation. Quantity is capped at available stock, and
                   that is the only constraint. Any number of shoppers may hold
                   the same card in their carts simultaneously.

enter checkout   → reserve now, atomically, for RESERVATION_MINUTES.
                   First to reach checkout wins the copy.

order confirmed  → status = sold, stamp id_order.

window expires   → cron returns the copy to available.

lost the race    → the later shopper is told at checkout that the item is no
                   longer available and the line is removed or reduced.
```

**Why not reserve on add-to-cart.** A card sitting in an abandoned cart is taken
off sale for the whole window while nobody is buying it. On one-of-one stock that
is the difference between selling a $1,400 Charizard and not. Carts are browsing;
checkout is intent. Only intent should hold inventory.

**Consequences that must be handled, not assumed away:**

- **Add-to-cart validates against `available`, but guarantees nothing.** Two
  shoppers can each legitimately add the last copy. That is by design — the loser
  finds out at checkout, not before.
- **Checkout entry is the contention point**, so the atomic claim moves there. One
  statement, check affected rows, never read-then-write:

  ```sql
  UPDATE cc_card_copy SET status='reserved', id_cart=?, reserved_until=?
   WHERE id_copy=? AND status='available'   -- 0 rows affected = someone beat you
  ```

- **A partial claim must not half-reserve an order.** If a cart holds three cards
  and only two can be claimed, the customer needs a clear message naming the card
  that was lost, and the two successful claims should be released rather than
  silently held while they decide.
- **Re-entering checkout extends, never double-books.** A reservation already held
  by this cart refreshes its expiry; it must not be treated as a fresh claim.
- **The release cron already runs every 5 minutes** (`copies-release.php`). With
  reservations now starting later and lasting 30 minutes, that cadence still bounds
  the worst-case hold at 35 minutes.

**`RESERVATION_MINUTES` must become configurable** rather than a class constant —
a `Configuration` value so it is changeable without a deploy.

**Critically: don't force the choice.** Most buyers don't care. Default to "any NM
copy" (FIFO — oldest stock first, which is also correct inventory hygiene) and make
copy selection an opt-in *"choose your exact card"* affordance. Forcing a gallery on
someone buying a $2 common adds friction to your highest-volume path.

Note this interacts with copy *selection*: picking a specific copy in the gallery
is a preference recorded on the cart line, not a hold. It is honoured at checkout
if that copy is still available, and falls back to FIFO if it is not.

### 2.4 Photography rules

Two kinds of image exist, and which one a shopper sees is decided per SKU, not per
product.

| Image | Source | Purpose |
|---|---|---|
| **Stock photo** | Reference catalogue (pokemontcg.io / TCGplayer) | Shows what the printing *is*. Collected once when the product is created. |
| **Copy photo** | Our own camera, per physical card | Shows the exact item a buyer receives. Attached to a `card_copy`. |

**The display rule, per SKU:**

```
always                  → the stock photo owns the gallery,
                          on the product page AND in the browser tile.
any copies photographed → plus a "choose your exact card" picker.
                          Picking one swaps the gallery to that copy's photos.
no copy photographed    → stock photo, and say photography is pending.
policy = stock_only     → stock photo, and say so plainly (bulk).
```

**The stock photo is never replaced without the shopper asking.** It is the best
image on the page — for a graded copy it is the composited slab, showing the
holder and its label — so a listing must not quietly downgrade itself to a
snapshot nobody requested. Photographs of the actual item are worth showing *on
demand*; they are not worth showing *instead*. Two earlier rules said otherwise
and are gone:

- *"sealed → always the stock photo, never serialised"*. Sealed is serialised
  now. The old reasoning was that one factory-sealed box is interchangeable with
  another, which is true of the cards inside and false of the box: condition,
  dents, shrink-wrap and print run all vary, and a buyer paying box prices wants
  to see the one being shipped.
- *"1 copy available → that copy's own photo as the main image"*. A lone copy no
  longer promotes its photograph over the stock image. It had the effect of
  replacing a composited slab with a placeholder snapshot, on the product page
  and in the listing tile, on exactly the listings that most needed to look good.

Serialising is not the same as photographing. Every copy starts `pending`, and
what is worth a camera is still a business decision — a $0.30 common with
eighteen interchangeable copies is flagged `stock_only` and says so.

**The reference card back ships as an asset.** `provisioning/assets/card-back.jpg`
is the standard English back (745×1040), used for every stock back and for the
`back` frame of every copy photo set — one asset for the whole catalogue, since
every modern English card shares one back. Two traps found while sourcing it:
`images.pokemontcg.io/back.png` returns **HTTP 404 with a PNG body**, so a fetch
that checks bytes instead of status "succeeds" and stores their not-found graphic;
and `seed-photos.php` skips products that already have a back, so replacing the
old drawn placeholders needed an explicit rewrite-in-place path.

**The panel always renders on a serialised SKU.** Falling back to the stock photo
is a display decision; it is never a reason to say nothing. A missing panel reads
as an omission — the shopper cannot tell whether photos exist and the page failed
to show them, or whether they were never going to exist.

`photo_state` and `photo_policy` are deliberately separate columns:

| Column | Question it answers | Values |
|---|---|---|
| `photo_state` | Has this copy been shot? | `pending` / `captured` |
| `photo_policy` | Are we ever *going* to shoot it? | `per_copy` / `stock_only` |

They look identical in the data when no photo exists, and mean opposite things to
a buyer. So the four states each get their own wording:

| SKU state | What the panel says |
|---|---|
| any count, some captured | "Choose your exact card" picker, one tile per photographed serial |
| 1 copy, pending | Serial, and that the photo is pending |
| 2+ copies, none captured, `per_copy` | "Individual copies not photographed **yet**" |
| any count, all `stock_only` | "Sold by condition — **not** individually photographed" |

Note the first row covers a single copy too. A one-of-one still goes through the
picker rather than seizing the gallery, so the shopper sees the slab or the
reference scan first and the photograph second, by choice.

A SKU counts as `stock_only` only when *every* copy in it is flagged that way. One
copy still queued for the camera keeps the whole SKU on the "pending" wording, so
the shop never tells a buyer "no photos" while one is actually coming.

**The panel never states a stock count.** The stock box directly above it already
gives the count for the selected printing and condition. Saying it again in
different words ("3 in stock" above, "One in stock" below) read as two conflicting
facts about the same SKU. The stock box owns quantity; this panel owns photography.

### 2.5 Choosing copies

The picker is **open on arrival** whenever the selected SKU has photographed
copies. Collapsed behind a summary, the shop's best feature was a thing you had
to know to go looking for.

**Click to look, confirm to buy.** Clicking a tile only previews that copy in the
main gallery, so a shopper can work along the row comparing cards without
committing to any of them; a separate confirm adds the previewed copy to the
order. That split is what makes selecting several possible — with
click-to-select there is no way to inspect a card you have not already chosen.

**The selection drives the line quantity.** Three chosen copies is an order for
three, and the serials ride along on the form so the cart hook reserves those
exact physical cards rather than any three of the SKU. Choosing nothing leaves
the quantity alone, because the picker is optional and must not overwrite a
number the shopper set by hand. The ceiling is stock, not the tile count — the
picker can show copies that are photographed but no longer available.

**A chosen copy keeps the gallery.** Once a copy is confirmed, the gallery shows
it — including across the product refresh that a quantity change triggers, which
otherwise rebuilt the gallery from the stock images and left the page
contradicting itself: the reference scan on the left, two cards marked chosen on
the right. The most recent choice is what is shown, and clicking any other tile
still previews it without changing the selection.

Removing a copy falls back to the one chosen before it, and only when nothing is
chosen at all does the stock gallery return. Leaving a photograph of a card the
shopper has just declined on screen states the opposite of what they did.

Photos are remembered by serial, separately from the picker's own list: a copy
chosen from page three is no longer in that list after a re-render, and would
otherwise become undisplayable.

```sql
cc_card_copy_choice
  PRIMARY KEY (id_cart, id_product, id_product_attribute, copy_uid)
```

The key includes `copy_uid`. It was one row per line, which allowed exactly one
chosen serial — buying two photographed copies of a card meant picking one and
taking pot luck on the second. The choice list REPLACES what was stored for that
line rather than merging, or deselecting would leave a serial recorded and the
line would keep asking for a card nobody wanted.

**Paging.** Only the first `COPY_PAGE` tiles ship with the page; the rest arrive
from the copies controller as the carousel is dragged toward its end. A card we
hold fifty photographed copies of carries two hundred photo URLs, and embedding
all of them costs every visitor whether or not they open the picker. The SKU's
`count` and `photographed` totals are computed **before** the list is trimmed —
one is the selection ceiling, the other is what the badge reports.

Drag and click share the row and are told apart by distance: a press that travels
more than a few pixels is a drag, and the click that follows it is suppressed in
the capture phase. Without that, flicking the row previews whatever happened to
be under your finger when you let go.

### 2.6 The cart

A cart line is keyed on `(id_product, id_product_attribute)`, so the rules that
split the product browser already split the cart: 1st Edition and Unlimited are
separate lines, and every graded copy is its own line per grader and tier. The
line has to SAY so — without a grading chip a raw copy and a PSA 9 of one card
render as two identical lines differing only in price.

**The line reads as a description on top and its controls along the bottom.**
Hummingbird stacked the stepper and the line total in a column beside the title
and gave "Remove" a row of its own underneath, so the three things you can do to
a line lived in three different places. Everything that describes the card —
picture, title, chips, unit price — is one block; everything that acts on it is a
single row ending at the total those actions change. The picture is usually what
makes a line tall, so the control row is pinned to the **bottom** of whatever
height the picture sets rather than stopping where the text runs out — the
description row absorbs the slack. A floor under the line keeps rows comparable
when the pictures are not: a sealed case photo is wide and short, about 70px
against a card scan's 180, and without one a cart mixing the two gave every line
a different height and the short ones a cramped strip. The picture is centred in
whatever room the line has. Remove is the Material
Icons bin the rest of the shop's chrome uses, not the word: it was the only thing
in that row that was not a control, and it read as a label for the numbers beside
it. The word it replaced becomes the accessible name, so the control stays
labelled and stays translated without a string of ours.

Three behaviours hang off `cartLines()`, which gathers the same three facts once
per line — the copies chosen, what is in stock, and whether photos exist:

- **Show selected cards** — a modal listing the exact serials on that line. It is
  sized to the stepper it shares the row with; a control half the height of the
  one beside it reads as a caption rather than as something to press.
- **A quantity ceiling** at available stock, stated when it is reached rather
  than after a round trip that reads as the shop losing the stock.
- **A picker on increase** — raising the quantity asks which additional physical
  card, and the quantity does not move until it is answered. One press adds one
  card, so it asks about one card, never the backlog of units that happen to be
  unchosen. It carries a **Skip**, because choosing by photo is optional: the
  extra unit ships FIFO, exactly as it would for a shopper who never opened it.
- **A required choice on decrease** — lowering the quantity below the number of
  chosen copies asks WHICH card is being given up, and the quantity does not move
  until it is answered. The shop cannot pick that for them.
- **Decreasing past one removes the line**, and asks nothing. It used to stop
  dead at a quantity of one, which left the control doing nothing — and a button
  that does nothing cannot be told apart from one that failed. There is no
  question to put even when the line has a chosen card: the shopper is removing
  their only copy, and the bin sitting beside that button does the same thing
  without asking.

Which means the picker is never opened to ask a question with one possible
answer. A single chosen copy cannot reach it: dropping below one chosen card is
dropping below one unit, which is the rule above.

The asymmetry is the point. Adding can fall back to "any copy" and still be
truthful; removing cannot, because there is no neutral answer to which of the
shopper's three chosen cards to drop.

Both dialogs are operated the same way and say the same things with colour:

- **Select card** carries the accent while it is the outstanding step, because it
  is what unlocks Confirm. Once the card in view is already held it becomes an
  undo and steps down to an outline, so it stops competing with the choice still
  to be made.
- **Confirm is dead until the count is met.** It means "these are my cards", so
  it cannot be pressed while it would be a lie. Disabled, it drops its colour
  entirely rather than showing a dimmer version of it — a merely faded button
  reads as a styling accident, and the shopper presses it and concludes the
  dialog is broken.
- **The colour tracks what the press does**, not where the button sits: green to
  add, red to remove. Green on a button that takes a card off the order would say
  the opposite of what happens.
- **Skip is red**, the only other action that gives something up. Outline and
  wash rather than a fill: it is a real alternative, not the thing to press.

Neither dialog carries a lead sentence. The title asks the question and the
counter states the number, so a paragraph repeating both only pushed the cards
down the panel.

All three dialogs share one gallery, and **it decodes the next picture before it
swaps.** Assigning to `src` blanks the element the moment it is set and leaves it
empty until the file decodes, so every thumbnail click flashed an empty stage —
even on an image the browser already held, because the gap is in the paint, not
the network. Two things follow from doing it this way: a click that lands while
an earlier one is still decoding wins outright rather than queueing, and the
thumbnail row is left alone when the shots have not changed, since rebuilding it
tore down images that were already on screen. The stage is as tall as an image is
allowed to be, so a slab composite and a flat scan do not move the panel between
them.

Choosing fewer cards than the line gained is still allowed — that is exactly what
Skip is for, and it says out loud what happens to the remainder.

**A dialog owns the viewport.** The backdrop stopped clicks but not the wheel, so
the page slid about behind an open question; on the cart that meant the line
being edited could scroll out of sight. The root scroller is locked for as long
as any modal is up — derived from what is on screen rather than counted, because
the confirmation step opens a second modal over the first and a plain toggle
would hand the page back the moment the inner one closed. The scrollbar's width
is handed back as padding, or the whole page jumps sideways as the dialog opens.

The requirement is scoped to copies that are actually chosen. A line of five with
three chosen can fall to three freely — those two units were never anybody's
particular card. Only below that does the modal appear.

**A line can never have more chosen copies than it is buying.** Lowering a
quantity used to leave the choices untouched, so a line of two still claimed
three chosen cards, and the surplus would have been reserved at checkout for a
card the shopper had already given up. `cartLines()` deletes the surplus rather
than hiding it, so a cart carrying that state repairs itself on the next view.

**Both directions ask first and move the quantity afterwards.** The increase used
to do the opposite — add, then offer the picker when the cart reported back — and
the gap between the two was wide enough to press again, and again, so a fast hand
ran the count up without ever seeing a card. Opening the dialog as the first
thing the press does closes that gap by construction: there is no window in which
the count has moved and the question has not been put. Abandoning the dialog is
therefore simply a no, with nothing to unwind, and every route that can change a
quantity — the two buttons and the text field — goes through the same gate.

The answer is applied by re-pressing the button PrestaShop already owns rather
than writing the quantity directly; only that route is proven to actually move
the cart.

**Nothing about how a line LOOKS waits for JavaScript.** The chips, the "show
selected cards" button and the bin all used to be applied after the page had
loaded, so every arrival at the cart — and every refresh, and every quantity
change — showed PrestaShop's bare line first and the finished one a moment
later. None of it depends on anything the client knows, so none of it has any
business waiting for the client:

- **Chips and the button** are rendered by the server, through the cart line
  template's own per-product hooks. That also makes the AJAX re-render free: the
  cart block comes back from the server already complete.
- **The bin** is CSS. The theme's icon font is a ligature font, so the glyph is
  reachable from `content` and the word "Remove" is simply not drawn — it stays
  in the accessibility tree, and the template already gives the link an
  aria-label.
- **PrestaShop's label/value rows**, which the chips replace, are hidden in CSS.
  They used to be deleted by the same pass that added the chips, so moving the
  chips to the server stopped that pass and brought the rows back; a row that has
  to be deleted after the fact is a row the shopper can watch arrive and leave.

What is left for JavaScript is behaviour, which has nothing to show. It is still
re-attached after a re-render, from a MutationObserver rather than a timer — and
that observer watches the **body**, because the update replaces the
`cart-overview` node itself rather than its children, so an observer bound there
is left holding a detached element and never fires again. It fails silently,
which is how the first attempt at this looked correct and fixed nothing.

Three traps, all from the cart re-rendering on every change:

- A line that is mid-operation is locked, and the lock is held **outside the
  DOM**, keyed by SKU. Held on the element, both the flag and the class that
  dimmed it were destroyed by the very update they were meant to guard — the
  line came back fresh and clickable while the dialog was still opening.
- `cryptocardsCartLines` is replaced wholesale on each refresh, so handlers
  attached once and closed over their entry were deciding from a chosen-count
  several edits out of date. They re-read it per press instead.

- It does **not** always replace the line element, so clearing a guard and
  re-running stacked a second increment handler — one click asked for two more
  cards. Listeners are attached once per line and never re-attached; only the
  visual parts are rebuilt.
- Everything the theme adds to a line is otherwise destroyed by the first
  quantity change, which reverted lines to PrestaShop's raw attribute list.

### 2.7 Gallery composition

A card's stock images are the front scan, the card back, and one composited slab
per graded combination it holds. Which of them the gallery shows, and in what
order, depends on the selection:

```
ungraded selected  → front, back, then every graded composite
graded selected    → that grading's composite, then front, then back
```

The composite leads when graded because the holder and its label are what is
being bought; the front and back still follow, because they are the only view of
the card inside it.

Sealed products get no card back. The back is a fact about a *card*, and a
photograph of a Pokémon card back on a sealed booster pack is a picture of
something that is not the product — on a listing whose entire premise is that it
has never been opened. Cards are identified for this by `cc_card_identity`, not
by being serialised: those meant the same thing until sealed was serialised too.

Only the graded case needs code. PrestaShop already renders the ungraded order
correctly, but selecting a graded SKU makes it filter the gallery down to that
combination's single image — leaving a slab listing with no picture of the card's
front or back at all. The theme appends the images that belong to no combination,
which yields the order above and changes nothing when ungraded. Two details that
bite:

- The page has **two** carousels, the inline gallery and the fullscreen viewer.
  Appending to only the first leaves zoom — the one place a buyer goes to look
  closely — showing the slab alone.
- Images are addressed as `/<id_image>-<type>/<rewrite>.jpg`, so a slide is
  cloned and its id swapped. Emitting URLs instead would mean naming image types
  server-side and rebuilding the responsive `srcset` by hand.

**Choosing a copy is always optional.** Most buyers want "a Near Mint one" and
should never be forced through a gallery. Selecting a specific copy is an opt-in
affordance; taking no action reserves the oldest available copy (FIFO, which is
also correct stock rotation).

### 2.8 What copies are *not* for

Copies are not a pricing axis. **NM is NM** — the market does not pay a premium for
a better-centred NM raw single, and a PSA 10 is a PSA 10 regardless of how it looks
inside the slab. Condition grade is the price; per-copy variation is not.

So per-copy exists for **trust and operations**, not margin:

- the buyer sees the actual card they will receive
- pick/pack verifies the right physical card shipped
- returns re-shelve with history intact

### 2.9 QR / barcode

Every copy gets a label on its sleeve encoding `copy_uid` (Code128 for a 1-D scanner,
QR if phones are doing the scanning). Encode **only the opaque id** — never price or
name, both of which change.

What it buys you:

- **Pick/pack** — scan before sealing the envelope. The right physical card in the
  right order, verified, every time.
- **Returns** — scan to re-shelf the exact copy with its history intact.
- **Location tracking** — where is copy A7K2QX right now
- **Customer trust** — the QR on the toploader resolves to the listing they bought
  from, with the photos they saw. *"The card you received is the card you chose."*
  That is a marketing asset, not just an ops one.

---

## 3. Intake pipeline — photos to sellable stock

The bottleneck in card retail is not listing. It is **identification and condition**.
Automate the first, keep humans on the second.

### 3.1 Capture

The unglamorous part that makes everything else work: **a fixed rig**. Copy stand,
diffuse LED either side, matte black background, camera at fixed distance. Consistent
geometry and lighting is what makes image matching reliable, and what makes your product
photos look like a shop rather than a phone camera roll.
Shoot **front and back** — backs are where condition problems hide, and buyers of
expensive cards expect them.

### 3.2 Identify (automated first pass)

Two cheap signals that together are near-conclusive:

1. **Perceptual hash** (pHash/dHash) against a reference library — every card image
   from pokemontcg.io, which you already pull. Returns ranked candidates in
   milliseconds, no ML infrastructure.
2. **Read the collector number** — "125/197" plus the set symbol uniquely identifies
   a printing. A vision model handles this far more robustly than classical OCR,
   especially on foils and textured cards, and can read name, number and set in one
   call.

Combine → candidate + confidence. Above threshold, auto-accept. Below, human picks
from the ranked list. This is how the commercial scanners work; none of it is exotic.

### 3.3 Review queue

A grid of the batch, each tile showing the photo, the top match, the suggested
condition and the confidence. The interaction that matters:

- **Bulk-accept everything above the confidence threshold** in one click
- Only ambiguous cards get individual attention
- Unmatched → search, or "create new printing" (which triggers §4)

Target throughput: **under 10 seconds per card end-to-end**, dominated by handling
the physical card, not the software.

### 3.4 Publish

Accepting a row creates a `cc_card_copy` with its photos, prints its QR label, and
increments derived stock. If the printing doesn't exist as a product yet, create it
from the reference data and let the price engine price it on the next cycle.

**Intake never types a title.** The match produces a `cc_card_identity` row — set
category, collector number, card language, plus the card name per storefront
language — and the title is composed from it:

```
<card name> — <set name> <collector number> [<CARD LANGUAGE>]
```

`make derive-names` rebuilds every title from the TCGplayer match; `OFFLINE=1`
recomposes from the stored atoms without touching the network. The
`cryptocards_i18n` save hook does the same recomposition on every product save, so
a title edited by hand in the back office is overwritten — deliberately. See the
naming rules in docs/information-architecture.md.

This replaced `rename-products.php`, which wrote one title into every language
slot. The French storefront was still showing `Charizard — Base 4/102`: not merely
untranslated but stale, in a format the English side had abandoned, because the
script compared only the default language before concluding it had nothing to do.

---

## 4. Taxonomy sync — new sets, new rarities

New sets ship constantly, and occasionally something genuinely new appears (a new
rarity tier, a new regulation mark, a new finish).

**Nightly job:** poll the reference sources, diff against the database, and split the
result by risk:

| Change | Risk | Action |
|---|---|---|
| New set in an existing series | Low | **Auto-apply** — create category, pull logo, add to facets |
| New rarity / subtype / regulation mark | Medium | **Propose** — needs a French translation and a facet position |
| A new *kind* of attribute entirely | High | **Alert a human** — needs modelling, not a row |

Auto-applying everything is wrong: a garbage value from an upstream feed becomes a
permanent facet and a live indexed URL. Propose-then-approve is the correct default
for taxonomy, with auto-apply reserved for the shape you've already seen.

Two extras worth building in:

- **Translation gap detection** — flag any new value lacking an `fr-CA` string, so
  the French storefront never silently falls back to English.
- **Pre-orders** — sets are announced before release. The same job can create the
  category and pre-order products with a release date attached.

---

## 5. Roadmap

Sequenced so each phase is useful on its own.

| Phase | Scope | Why here |
|---|---|---|
| **1** | Price engine, TCGCSV + pokemontcg.io only, **dry-run**, journal, FX | Highest value, lowest risk. Watch it for two weeks before it touches a price. |
| **2** | Enable auto-publish behind confidence gates + guard rails | Only after you trust phase 1's proposals. |
| **3** | Serialised inventory: `cc_card_copy`, derived stock, reservations, QR labels | Foundation for everything distinctive. Do before intake — intake writes into it. |
| **4** | Intake pipeline: capture rig, pHash + number reading, review queue | The throughput unlock. |
| **5** | Copy galleries on the product page + "choose your exact card" | The customer-facing payoff of 3 and 4. |
| **6** | Taxonomy sync with propose/approve | Removes the developer from routine catalogue growth. |
| **7** | Velocity rules (stale stock steps down, fast movers step up) | Where the margin actually is. |
| **8** | Buylist quoting, using the price engine inverted | Buying stock in is the other half of the business. |

**Corrections queued ahead of the above** (they change existing behaviour rather
than adding new capability, so they land first):

| # | Change | Why it jumps the queue |
|---|---|---|
| **C1** | Move reservation from add-to-cart to checkout entry (§2.3) | Current behaviour takes one-of-one stock off sale for abandoned carts. Directly costs sales. |
| **C2** | Make `RESERVATION_MINUTES` a `Configuration` value | Needed by C1 and currently requires a deploy to change. |
| **C3** | Translate attribute values into fr-CA (conditions yes, hobby terms no) | The French storefront reads `État: Near Mint`. Half-translated is worse than untranslated. |
| **C4** | Auto-translate admin entry into every enabled language | Blocks routine stock entry the moment a human starts adding products by hand. |

**Phase 3 before phase 4** is the one ordering constraint that matters. Building
intake against quantity-based stock means rewriting it when copies arrive.

---

## 6. Honest constraints

- **Approval-gated sources.** TCGplayer's API and eBay's sold-comps data both need
  applications. Design so sources are pluggable and the engine degrades to fewer
  sources rather than breaking.
- **Card images are copyrighted.** Reference images from public catalogues are fine
  for *matching*. Customer-facing photos should be your own — which the intake
  pipeline produces as a side effect. That is a compliance win, not just a quality one.
- **Serialised inventory is a real schema change.** Reservations, expiry, race
  conditions and pick/pack all need testing under concurrency. It is the highest-risk
  item here and the highest-reward.
- **The rig matters more than the algorithm.** Inconsistent lighting will do more
  damage to match rates than any model choice.
