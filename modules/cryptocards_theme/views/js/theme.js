/**
 * Decorates product cards with condition and finish chips.
 *
 * Hummingbird's miniature template does not expose combination attributes, but the
 * card link carries the default combination as an anchor
 * (#/26-condition-near_mint/42-finish-holofoil) — so the data is already in the DOM
 * and can be surfaced without overriding the theme template.
 */
(function () {
  'use strict';

  /**
   * English fallback only. The live map comes from the module keyed on the
   * current language (window.cryptocardsConditionClasses) - see conditionClasses().
   */
  var CONDITION_CLASS = {
    'near mint': 'cc-chip--nm',
    'lightly played': 'cc-chip--lp',
    'moderately played': 'cc-chip--mp',
    'heavily played': 'cc-chip--hp',
    damaged: 'cc-chip--dmg'
  };

  /**
   * Translated string by key, with %s / %d substitution.
   *
   * The dictionary comes from the module (window.cryptocardsI18n) because strings
   * written here bypass PrestaShop's translation system entirely - a literal in
   * this file renders identically on both storefronts. Falls back to the key so a
   * missing entry is visible in testing rather than rendering blank.
   */
  function t(key, substitution) {
    var dict = window.cryptocardsI18n || {};
    var value = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
    return substitution === undefined ? value : value.replace(/%[sd]/, substitution);
  }

  function titleCase(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function chipsFor(href) {
    if (!href || href.indexOf('#/') === -1) return [];

    return href
      .split('#/')[1]
      .split('/')
      .map(function (segment) {
        // Two shapes in the wild: "26-condition-near_mint" from the listing
        // template, and "condition-near_mint" from Link::getProductLink().
        var match = segment.match(/^(?:\d+-)?([a-z_]+?)-(.+)$/);
        if (!match) return null;
        return { group: match[1], value: decodeURIComponent(match[2]) };
      })
      .filter(Boolean);
  }

  function decorate(card) {
    if (card.querySelector('.cc-chips')) return;

    var link = card.querySelector('.product-miniature__image-link, a[href]');
    // No variant fragment to read: sealed has no variants, and its chips are
    // rendered with the tile by the server instead - see the module's
    // displayProductListReviews hook.
    var parts = chipsFor(link && link.getAttribute('href'));
    if (!parts.length) return;

    var chips = [];
    // Group slugs are localised ("etat"/"impression" in French), and so are the
    // VALUE slugs, so both are resolved from maps the module emits rather than
    // from English literals in this file.
    var slugs = window.cryptocardsAttrSlugs || { condition: 'condition', printing: 'printing' };
    var conditions = window.cryptocardsConditions || {};
    var printings = window.cryptocardsPrintings || {};
    var gradings = window.cryptocardsGradings || {};

    var languages = window.cryptocardsLanguages || {};

    parts.forEach(function (part) {
      if (part.group === slugs.language) {
        // Language is a variant, so a tile states the one it is offering.
        var lang = languages[part.value];
        if (lang) chips.push({ label: lang.label, cls: 'cc-chip--language' });

        return;
      }

      if (part.group === slugs.condition) {
        // Prefer the real label: title-casing the slug turned "Quasi neuf (NM)"
        // into "Quasi Neuf Nm" and lost the brackets entirely.
        var cond = conditions[part.value];
        var condLabel = cond ? cond.label : titleCase(part.value);
        var condCls = cond ? cond.cls : (CONDITION_CLASS[condLabel.toLowerCase()] || '');
        chips.push({ label: condLabel, cls: 'cc-chip--cond ' + condCls });
        return;
      }

      if (part.group === slugs.grading) {
        /**
         * The grader leads the pair it forms with the condition chip: "PSA"
         * then "10 Gem Mint". A tier number on its own says nothing - a 9.5 is
         * a different market at Beckett than at CGC - so the two are always
         * read together, and the grader has to come first.
         */
        var grading = gradings[part.value];
        if (grading ? grading.skip : part.value === 'ungraded') {
          return;
        }
        chips.push({
          label: grading ? grading.label : part.value.toUpperCase(),
          cls: 'cc-chip--grading'
        });

        return;
      }

      if (part.group !== slugs.printing) {
        return;
      }

      var printing = printings[part.value];
      // "Normal" means "no special printing", so it earns no chip. The module
      // decides this from the English name - comparing the slug here showed a
      // "Normale" chip on every plain French card.
      if (printing ? printing.skip : part.value === 'normal') {
        return;
      }
      var label = printing ? printing.label : titleCase(part.value);

      /**
       * One colour for every printing.
       *
       * 1st Edition and Unlimited used to get their own colours, decided here by
       * matching the label against the English literals "1st edition" and
       * "unlimited" - which is a TRANSLATED string, so on the French storefront
       * neither ever matched and the tiles disagreed with the product page, which
       * makes the same decision server-side. The edition is carried as a data
       * attribute instead: a fact, not a colour.
       */
      chips.push({
        label: label,
        cls: 'cc-chip--printing',
        edition: printing ? printing.edition : null
      });
    });

    paintChips(card, foldGradeChips(chips));
  }

  /** The words a grade tier uses, in the short forms the hobby writes. */
  var GRADE_WORDS = [['Black Label', 'BLK LBL'], ['Gem Mint', 'GEM MT'], ['Pristine', 'PRIS']];

  /**
   * Grader and grade are ONE badge - "PSA 10 GEM MT", not a company chip beside
   * a condition chip. The server does the same fold for the tiles it renders;
   * this covers the ones the printing expansion builds here.
   */
  function foldGradeChips(chips) {
    var gradingAt = -1;
    var conditionAt = -1;
    chips.forEach(function (chip, at) {
      if (chip.cls.indexOf('cc-chip--grading') > -1) gradingAt = at;
      else if (chip.cls.indexOf('cc-chip--cond') > -1) conditionAt = at;
    });
    if (gradingAt < 0 || conditionAt < 0) return chips;

    var tier = chips[conditionAt].label;
    GRADE_WORDS.forEach(function (pair) {
      tier = tier.replace(new RegExp(pair[0], 'i'), pair[1]);
    });
    chips[gradingAt].label = (chips[gradingAt].label + ' ' + tier).trim();

    return chips.filter(function (chip, at) { return at !== conditionAt; });
  }

  function paintChips(card, chips) {
    if (!chips.length) return;

    var host = card.querySelector('.product-miniature__infos') || card.querySelector('.product-miniature__inner');
    if (!host) return;

    var wrap = document.createElement('div');
    wrap.className = 'cc-chips';
    wrap.innerHTML = chips
      .map(function (chip) {
        return '<span class="cc-chip ' + chip.cls + '"' +
          (chip.edition ? ' data-edition="' + escapeHtml(chip.edition) + '"' : '') +
          '>' + escapeHtml(chip.label) + '</span>';
      })
      .join('');
    host.insertBefore(wrap, host.firstChild);
  }

  /**
   * Splits "Charizard — Base Set (Shadowless) 004/102" into a strong card name
   * and a quiet meta line.
   *
   * The generated name is accurate but front-loads the set into the same two
   * clamped lines as the card itself, so long set names pushed the actual card
   * name out of view. The set still has to be visible - a buyer scanning search
   * results needs it - so it drops to a second line instead of being removed.
   */
  function splitTitle(card) {
    // Hummingbird renders the title *as* the anchor, not as a wrapper around one.
    var anchor = card.querySelector('a.product-miniature__title, .product-miniature__title a');
    if (!anchor || anchor.querySelector('.cc-title__name')) return;

    var raw = anchor.textContent.trim();
    var match = raw.match(/^(.*?)\s+—\s+(.*)$/);
    if (!match) return;

    anchor.innerHTML =
      '<span class="cc-title__name">' + escapeHtml(match[1]) + '</span>' +
      '<span class="cc-title__meta">' + escapeHtml(match[2]) + '</span>';
    anchor.setAttribute('title', raw);
  }


  /**
   * Filter controls for the set directory.
   *
   * The page is CMS content and PS_USE_HTMLPURIFIER strips <script>, so the
   * controls cannot ship with the markup - they are injected here instead. Both
   * filters work on classes already present on the tiles, so no data round-trip.
   */
  function wireSetDirectory() {
    var root = document.querySelector('.cc-sets');
    if (!root || root.querySelector('.cc-sets__tools')) return;

    var tools = document.createElement('div');
    tools.className = 'cc-sets__tools';
    tools.innerHTML =
      '<label class="cc-sets__search">' +
      '<input type="search" class="cc-sets__search-input" ' +
      'placeholder="' + escapeHtml(t('filterSetsByName')) + '" ' +
      'aria-label="' + escapeHtml(t('filterSetsByName')) + '">' +
      '</label>' +
      '<button type="button" class="cc-sets__toggle" aria-pressed="false">' +
      escapeHtml(t('inStockOnly')) + '</button>' +
      '<span class="cc-sets__result" role="status"></span>';

    var empty = document.createElement('p');
    empty.className = 'cc-sets__empty';
    empty.hidden = true;
    root.appendChild(empty);

    var jump = root.querySelector('.cc-sets__jump');
    if (jump) {
      jump.parentNode.insertBefore(tools, jump.nextSibling);
    } else {
      root.insertBefore(tools, root.firstChild);
    }

    var input = tools.querySelector('.cc-sets__search-input');
    var toggle = tools.querySelector('.cc-sets__toggle');
    var result = tools.querySelector('.cc-sets__result');
    var stockOnly = false;

    function apply() {
      var term = input.value.trim().toLowerCase();
      var shown = 0;

      root.querySelectorAll('.cc-sets__era').forEach(function (era) {
        var visibleHere = 0;

        era.querySelectorAll('.cc-set').forEach(function (tile) {
          var name = (tile.querySelector('.cc-set__name') || {}).textContent || '';
          var matches = (!term || name.toLowerCase().indexOf(term) !== -1)
            && (!stockOnly || tile.classList.contains('cc-set--stocked'));
          tile.hidden = !matches;
          if (matches) visibleHere++;
        });

        // An era with nothing left is noise, not an empty state.
        era.hidden = visibleHere === 0;
        shown += visibleHere;
      });

      // Jump links that lead nowhere are worse than no jump links.
      root.querySelectorAll('.cc-sets__jump-link').forEach(function (link) {
        var target = document.getElementById((link.getAttribute('href') || '').slice(1));
        link.hidden = !target || target.hidden;
      });

      var filtering = term || stockOnly;
      result.textContent = filtering ? t(shown === 1 ? 'setSingular' : 'setPlural', shown) : '';
      root.classList.toggle('cc-sets--filtered', !!filtering);

      // A blank page is not an answer - say what matched nothing, and what to try.
      empty.hidden = shown !== 0;
      if (shown === 0) {
        empty.textContent = term && stockOnly
          ? t('noSetMatchesInStock', term)
          : (stockOnly ? t('nothingInStock') : t('noSetMatches', term));
      }
    }

    input.addEventListener('input', apply);
    toggle.addEventListener('click', function () {
      stockOnly = !stockOnly;
      toggle.setAttribute('aria-pressed', String(stockOnly));
      toggle.classList.toggle('is-on', stockOnly);
      apply();
    });

    apply();
  }

  /**
   * Marks the print run on listing tiles for sets that have a parallel run.
   *
   * "Base Set" and "Base Set (Shadowless)" hold the SAME cards at the SAME
   * collector numbers - audited across all 217 TCGplayer groups, it is the only
   * such pair - and a Charizard from one is worth several times the other. The
   * only cue on a tile was the set name in small grey meta text, which is not
   * good enough for a distinction that size.
   */
  function markPrintRun(card) {
    var runs = window.cryptocardsPrintRuns;
    var chips = card.querySelector('.cc-chips');
    if (!runs || !chips || chips.querySelector('.cc-chip--run')) return;

    var meta = card.querySelector('.cc-title__meta');
    if (!meta) return;
    var text = meta.textContent.trim();

    // Longest set name wins, so "Base Set (Shadowless)" beats "Base Set".
    var best = null;
    Object.keys(runs).forEach(function (name) {
      if (text.indexOf(name) === 0 && (!best || name.length > best.length)) best = name;
    });
    if (!best) return;

    var run = runs[best];
    var chip = document.createElement('span');
    chip.className = 'cc-chip cc-chip--run cc-chip--' + run;
    chip.textContent = t(run === 'shadowless' ? 'shadowless' : 'shadowed');
    chip.setAttribute('title', t(run === 'shadowless' ? 'shadowlessTitle' : 'shadowedTitle'));
    chips.appendChild(chip);
  }

  /**
   * Canary for edition-split sets.
   *
   * In Jungle, Fossil, Team Rocket, the Gym pair and the four Neo sets, every
   * single is either 1st Edition or Unlimited - TCGplayer's bare "Normal" subtype
   * in those groups is sealed product, not singles. So a tile in one of those sets
   * showing no edition means the SKU was built wrong, and silently rendering
   * nothing would hide it in exactly the sets where the price gap is largest.
   */
  function checkEdition(card) {
    var sets = window.cryptocardsEditionSets;
    var chips = card.querySelector('.cc-chips');
    var meta = card.querySelector('.cc-title__meta');
    if (!sets || !sets.length || !chips || !meta) return;
    if (chips.querySelector('.cc-chip--noedition')) return;

    var text = meta.textContent.trim();
    var inSplitSet = sets.some(function (name) { return text.indexOf(name) === 0; });
    if (!inSplitSet) return;

    if (chips.querySelector('[data-edition]')) return;

    var chip = document.createElement('span');
    chip.className = 'cc-chip cc-chip--noedition';
    chip.textContent = t('editionNotSet');
    chip.setAttribute('title', t('editionNotSetTitle'));
    chips.appendChild(chip);
  }


  /** Price shown on a tile, as a number. Handles "$1,234.50" and "1 234,50 $". */
  function tilePrice(tile) {
    var el = tile.querySelector('.product-miniature__price');
    var raw = el ? el.textContent.trim() : '';
    if (!raw) return NaN;

    var digits = raw.replace(/[^0-9.,]/g, '');
    // Whichever separator appears last is the decimal one.
    var lastDot = digits.lastIndexOf('.');
    var lastComma = digits.lastIndexOf(',');
    if (lastComma > lastDot) {
      digits = digits.replace(/\./g, '').replace(',', '.');
    } else {
      digits = digits.replace(/,/g, '');
    }

    return parseFloat(digits);
  }

  /** The active sort, from the URL (PrestaShop drives sorting through it). */
  function activeOrder() {
    var match = /[?&]order=([^&]+)/.exec(window.location.search);
    return match ? decodeURIComponent(match[1]) : '';
  }

  /**
   * Re-orders tiles by their own price after expansion.
   *
   * PrestaShop sorts PRODUCTS, then expandPrintings() inserts each extra printing
   * next to the product it came from. Under "Price, high to low" that put a $147
   * Unlimited Pikachu directly after its $599 1st Edition twin, ahead of a $567
   * Magneton - the list looked broken because each tile carries its own price but
   * only the parent's price had been sorted on.
   *
   * Only price sorts are re-ordered. For name or relevance, keeping a card's
   * printings adjacent is the more useful arrangement.
   */
  function resortByPrice() {
    var order = activeOrder();
    if (order.indexOf('price') === -1) return;

    var grid = document.querySelector('.products');
    if (!grid) return;

    var tiles = Array.prototype.slice.call(grid.querySelectorAll(':scope > .product-miniature'));
    if (tiles.length < 2) return;

    var descending = order.indexOf('desc') !== -1;
    var decorated = tiles.map(function (tile, index) {
      return { tile: tile, price: tilePrice(tile), index: index };
    });

    decorated.sort(function (a, b) {
      var aBad = isNaN(a.price);
      var bBad = isNaN(b.price);
      // A tile with no readable price keeps its original position rather than
      // being flung to one end of the list.
      if (aBad || bBad) return aBad && bBad ? a.index - b.index : (aBad ? 1 : -1);
      if (a.price === b.price) return a.index - b.index;
      return descending ? b.price - a.price : a.price - b.price;
    });

    var fragment = document.createDocumentFragment();
    decorated.forEach(function (entry) { fragment.appendChild(entry.tile); });
    grid.appendChild(fragment);
  }

  /**
   * PrestaShop renders the active-filters bar whether or not anything is in it,
   * leaving an empty rounded panel above the grid.
   */
  function hideEmptyFilterBar() {
    var bar = document.getElementById('js-active-search-filters');
    if (!bar) return;
    bar.hidden = bar.querySelectorAll('a, button, li').length === 0;
  }


  /**
   * Adds a type-to-filter box to long facets in the rail.
   *
   * Set, Pokemon and Artist are multi-select now rather than single-select
   * dropdowns - a card shop where you cannot ask for Charizard OR Pikachu is
   * missing the point. That makes them long (Pokemon has 155 values in stock), so
   * PrestaShop caps the visible rows and this makes the rest reachable without
   * expanding a 155-row list and scrolling for it.
   */
  var FACET_SEARCH_MIN = 10;

  function wireFacetSearch() {
    // Scope to real facet blocks. Option rows also carry .search-filters__item, so
    // a looser selector treats every single row as a facet of its own.
    document.querySelectorAll('.accordion-item[data-name]').forEach(function (block) {
      if (block.querySelector('.cc-facet__search')) return;

      var list = block.querySelector('ul.accordion-body');
      if (!list) return;

      var rows = list.querySelectorAll(':scope > li');
      if (rows.length < FACET_SEARCH_MIN) return;

      var label = block.getAttribute('data-name') || 'options';

      var wrap = document.createElement('div');
      wrap.className = 'cc-facet__search';
      wrap.innerHTML = '<input type="search" class="cc-facet__search-input" ' +
        'placeholder="' + escapeHtml(t('filterBy', label.toLowerCase())) + '" ' +
        'aria-label="' + escapeHtml(t('filterByAria', label)) + '">' +
        '<span class="cc-facet__search-empty" hidden>' + escapeHtml(t('noMatch')) + '</span>';
      list.parentNode.insertBefore(wrap, list);

      var input = wrap.querySelector('.cc-facet__search-input');
      var empty = wrap.querySelector('.cc-facet__search-empty');

      input.addEventListener('input', function () {
        var term = input.value.trim().toLowerCase();
        var shown = 0;

        rows.forEach(function (row) {
          var match = !term || row.textContent.toLowerCase().indexOf(term) !== -1;
          row.hidden = !match;
          if (match) shown++;
        });

        empty.hidden = shown !== 0;
      });
    });
  }


  /**
   * Hides facets that cannot express a choice.
   *
   * "Availability" renders whenever the stock filter is enabled, but when every
   * product is in stock it offers exactly one option - "In stock" - which filters
   * nothing. It is pure noise at the top of the rail.
   *
   * Deliberately scoped to availability rather than "any one-option facet": a
   * single-option ATTRIBUTE facet still narrows (Print Run offers only
   * "Shadowless", and ticking it takes 276 products down to 24). The stock facet
   * is different because its one option describes the entire result set.
   */
  /**
   * Inserts the Card Language facet when PrestaShop has decided to omit it.
   *
   * ps_facetedsearch drops any facet whose single value covers the whole result
   * set. That is sensible for most facets and wrong for this one: card language is
   * what separates a Western card from a Japanese one, and a rail that mentions it
   * only once a second language is in stock reads as "this shop sells one
   * language". The counts and the filter link are real, so ticking it is a valid
   * query rather than decoration.
   *
   * Does nothing once PrestaShop renders the facet itself.
   */
  function ensureLanguageFacet() {
    var facet = window.cryptocardsLanguageFacet;
    if (!facet || !facet.label || !facet.values || !facet.values.length) return;

    var rail = document.querySelector('#search-filters .accordion, #search-filters');
    if (!rail) return;
    if (document.querySelector('.accordion-item[data-name="' + facet.label.replace(/"/g, '') + '"]')) return;
    if (document.querySelector('.cc-facet--language')) return;

    var id = 'cc_facet_language';
    var rows = facet.values.map(function (value, index) {
      var url = new URL(window.location.href);
      url.searchParams.set('q', facet.label + '-' + value.name);

      return '<li><div class="search-filters__item facet-label">' +
        '<div class="search-filters__form-check form-check">' +
        '<input class="form-check-input" type="checkbox" id="' + id + '_' + index + '" ' +
        'data-search-url="' + escapeHtml(url.toString()) + '">' +
        '<label class="search-filters__form-label form-check-label" for="' + id + '_' + index + '">' +
        '<a href="' + escapeHtml(url.toString()) + '" class="search-filters__link search-link js-search-link" ' +
        'rel="nofollow" tabindex="-1">' + escapeHtml(value.name) +
        ' <span class="search-filters__magnitude">(' + value.count + ')</span>' +
        '</a></label></div></div></li>';
    }).join('');

    var block = document.createElement('div');
    block.className = 'accordion-item cc-facet--language';
    block.setAttribute('data-name', facet.label);
    block.setAttribute('data-type', 'feature');
    block.innerHTML =
      '<button class="accordion-button collapsed" type="button" data-bs-target="#' + id + '" ' +
      'data-bs-toggle="collapse" aria-expanded="false">' + escapeHtml(facet.label) + '</button>' +
      '<div id="' + id + '" class="accordion-collapse collapse"><ul class="accordion-body">' +
      rows + '</ul></div>';

    // After Condition, which is where a shopper narrows first.
    var after = document.querySelector('.accordion-item[data-type="availability"]')
      || rail.querySelector('.accordion-item');
    if (after && after.parentNode) {
      after.parentNode.insertBefore(block, after.nextSibling);
    } else {
      rail.appendChild(block);
    }
  }


  /**
   * Restates each cart line's facets as chips.
   *
   * The cart rendered "Impression: Holo" and "État: Quasi neuf (NM)" as two rows of
   * label/value text and nothing else - so the page where a four-figure purchase is
   * confirmed said LESS about the card than the tile the buyer clicked to get here.
   * Rarity, print run and card language were all missing.
   *
   * The chips come from the module fully composed. Reading them back off the
   * rendered labels was not an option: a condition chip's colour is decided by
   * attribute position, which no translated string carries.
   */
  function decorateCartLines() {
    var byS = window.cryptocardsCartChips;
    if (!byS) return;

    /**
     * The combination id, from the remove link or - on the checkout summary, which
     * has no data attributes at all - from the product URL, whose first path
     * segment is "{id_product}-{id_product_attribute}-{slug}".
     */
    function skuOf(line) {
      var handle = line.querySelector('[data-id-product-attribute]');
      if (handle) {
        var sku = String(handle.getAttribute('data-id-product-attribute'));
        /**
         * Sealed has no combination, so every one of its lines says 0. Keyed on
         * that alone they would all draw the first sealed line's chips, so a
         * line without a SKU is addressed by its product instead.
         */
        if (sku !== '0') return sku;
        var product = handle.getAttribute('data-id-product');

        return product ? 'p' + product : null;
      }
      var link = line.querySelector('a[href]');
      var match = link && link.getAttribute('href').match(/\/(\d+)-(\d+)-/);

      return match ? match[2] : null;
    }

    document.querySelectorAll('.product-line, .cart-summary-product').forEach(function (line) {
      if (line.querySelector('.cc-chips')) return;

      var sku = skuOf(line);
      var chips = sku && byS[sku];
      if (!chips || !chips.length) return;

      var wrap = document.createElement('div');
      wrap.className = 'cc-chips cc-chips--line';
      wrap.innerHTML = chips
        .map(function (chip) {
          return '<span class="cc-chip ' + chip.cls + '">' + escapeHtml(chip.label) + '</span>';
        })
        .join('');

      // The label/value rows are what the chips replace - leaving both would state
      // condition and printing twice on the same line.
      var rows = line.querySelectorAll('.product-line__item--info, .cart-summary-product__attribute');
      /**
       * The fallback anchor is the title in the TEXT column, not just the first
       * `.product-line__title` in the line - the thumbnail's own link carries
       * that class too and comes first in document order. A card line has
       * attribute rows to sit above so it never reached this branch; a sealed
       * line has none, and its chip landed inside the picture.
       */
      var anchor = rows.length
        ? rows[0]
        : line.querySelector(
            '.product-line__content-left .product-line__title, .cart-summary-product__link'
          );
      if (anchor && anchor.parentNode) {
        /**
         * The attribute rows sit BELOW the title, so inserting before the first
         * of them lands the chips under it. Falling back to the title itself
         * has to insert AFTER, or a sealed line reads chips-then-title while
         * the card line above it reads title-then-chips.
         */
        anchor.parentNode.insertBefore(wrap, rows.length ? anchor : anchor.nextSibling);
      }
      rows.forEach(function (row) { row.remove(); });
    });
  }


  function hideDeadFacets() {
    document.querySelectorAll('.accordion-item[data-type="availability"]').forEach(function (block) {
      var options = block.querySelectorAll('ul.accordion-body > li');
      block.hidden = options.length < 2;
    });
  }


  /**
   * Widens the responsive candidates for listing thumbnails.
   *
   * Hummingbird ships a <picture> whose srcset stops at default_lg (336w) and a
   * `sizes` hint of 25vw. The frame is now 300px tall, so on a high-DPI screen
   * the browser had nothing better than 336px to work with and the card looked
   * soft. Adding the 400w and 452w renditions - both already generated by
   * PrestaShop - lets it pick a sharp one, and an explicit `sizes` stops it
   * guessing from a viewport fraction that no longer matches the layout.
   */
  function upgradeThumbnails(card) {
    card.querySelectorAll('img.product-miniature__image').forEach(function (img) {
      if (img.getAttribute('data-cc-hires') === '1') return;

      var srcset = img.getAttribute('srcset') || '';
      var lg = srcset.match(/(\S+-default_lg\/\S+?)\s+\d+w/);
      if (!lg) return;

      var base = lg[1];
      img.setAttribute('srcset', srcset.trim()
        + ', ' + base.replace('-default_lg/', '-default_xl/') + ' 400w'
        + ', ' + base.replace('-default_lg/', '-medium_default/') + ' 452w');
      img.setAttribute('sizes', '(min-width: 992px) 300px, (min-width: 768px) 260px, 215px');
      img.setAttribute('data-cc-hires', '1');
    });
  }

  function watchCartLines() {
    if (document.body.ccCartWatched) return;
    document.body.ccCartWatched = true;

    /**
     * Watched on the BODY, not on the cart block.
     *
     * The update replaces the cart-overview node itself rather than just its
     * children, so an observer bound to that block is left holding a detached
     * element and never fires again - which is exactly how the first attempt at
     * this failed, silently. The body is the only ancestor guaranteed to be the
     * same element afterwards.
     *
     * Painting also mutates what is being watched, and it is not idempotent -
     * the "show selected cards" button is rebuilt every pass - so the flag is
     * what stops the observer calling itself forever.
     */
    var painting = false;
    new MutationObserver(function () {
      if (painting) return;
      // Watching the whole body means most callbacks are about something else
      // entirely, so the gate has to be one cheap selector: a line the paint
      // has not marked is a line that has just been re-rendered.
      if (!document.querySelector('.product-line:not([data-cc-painted])')) return;

      painting = true;
      decorateCartLines();
      wireCartCopies();
      document.querySelectorAll('.product-line').forEach(function (line) {
        line.setAttribute('data-cc-painted', '');
      });
      setTimeout(function () { painting = false; }, 0);
    }).observe(document.body, { childList: true, subtree: true });
  }

  function run() {
    hideEmptyFilterBar();
    ensureLanguageFacet();
    decorateCartLines();
    watchCartLines();
    hideDeadFacets();
    wireFacetSearch();
    document.querySelectorAll('.product-miniature').forEach(function (card) {
      decorate(card);
      splitTitle(card);
      markPrintRun(card);
      checkEdition(card);
      upgradeThumbnails(card);
    });
  }

  /**
   * Expands a listing so every separately sellable variant gets its own tile.
   *
   * A card that exists as 1st Edition Holofoil AND Unlimited Holofoil is two
   * different purchases at two very different prices, but PrestaShop renders one
   * tile per product and hides the rest. The same is true of every GRADED copy: a
   * PSA 10 and a CGC 9 of one card are different holders, different markets and
   * one-of-one objects. Each clone deep-links to its own variant so the product
   * page opens on what was actually clicked.
   */
  /**
   * Values currently ticked under a named facet in the rail.
   *
   * Tile expansion has to honour every facet that selects a SKU, not just the one
   * it was first written for. Filtering to "Unlimited Holofoil" and still being
   * shown 1st Edition tiles is worse than no filter at all - on these sets the two
   * runs differ several-fold in price.
   */
  function activeFacet(facetName) {
    var found = [];
    var wanted = new RegExp('^' + facetName + '$', 'i');

    document.querySelectorAll('.accordion-item, .search-filters__item').forEach(function (block) {
      var heading = block.querySelector('button, .h6, summary');
      if (!heading || !wanted.test(heading.textContent.trim())) return;

      block.querySelectorAll('input[type=checkbox]:checked').forEach(function (input) {
        var host = input.closest('label') || input.parentElement;
        var label = ((host && host.textContent) || '').trim()
          .replace(/\s*\(\d+[^)]*\)\s*$/, '')   // strip "(12 results)"
          .trim();
        if (label) found.push(label);
      });
    });

    return found;
  }

  function activeConditions() { return activeFacet('condition'); }
  function activePrintings() { return activeFacet('printing'); }
  function activeGradings() { return activeFacet('grading'); }

  function expandPrintings() {
    if (!window.cryptocardsPrintingsUrl) return;

    var cards = Array.prototype.slice.call(document.querySelectorAll('.product-miniature[data-id-product]'));
    if (!cards.length) return;

    var ids = cards.map(function (card) { return card.getAttribute('data-id-product'); });

    var query = 'ids=' + encodeURIComponent(ids.join(','));
    var conditions = activeConditions();
    if (conditions.length) {
      query += '&conditions=' + encodeURIComponent(conditions.join('|'));
    }
    var printings = activePrintings();
    if (printings.length) {
      query += '&printings=' + encodeURIComponent(printings.join('|'));
    }
    var gradings = activeGradings();
    if (gradings.length) {
      query += '&gradings=' + encodeURIComponent(gradings.join('|'));
    }

    fetch(window.cryptocardsPrintingsUrl + (window.cryptocardsPrintingsUrl.indexOf('?') === -1 ? '?' : '&') + query,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        cards.forEach(function (card) {
          var variants = data[card.getAttribute('data-id-product')];
          // A single variant is not expanded, but it is still APPLIED: the
          // endpoint only sends a lone one when it has its own photo to put on
          // the tile.
          if (!variants || !variants.length) return;
          if (card.getAttribute('data-cc-expanded') === '1') return;
          card.setAttribute('data-cc-expanded', '1');

          variants.forEach(function (variant, index) {
            var tile = index === 0 ? card : card.cloneNode(true);
            applyVariant(tile, variant);
            if (index > 0) card.parentNode.insertBefore(tile, card.nextSibling);
          });
        });

        run();
        resortByPrice();
      })
      .catch(function () { /* listing still works unexpanded */ });
  }

  /**
   * Repoints every image on a tile at another of the product's photos.
   *
   * PrestaShop addresses images as /<id_image>-<type>/<rewrite>.jpg, so swapping
   * only the leading id keeps whatever size each src and srcset entry already
   * asked for. Rewriting whole URLs instead would mean picking a type here and
   * throwing away the responsive set the theme built.
   */
  function repointImages(tile, idImage) {
    var pattern = /\/\d+-([A-Za-z0-9_]+)\//g;
    var swap = function (value) {
      return value.replace(pattern, '/' + idImage + '-$1/');
    };

    tile.querySelectorAll('img').forEach(function (img) {
      ['src', 'srcset', 'data-src', 'data-srcset'].forEach(function (attr) {
        var value = img.getAttribute(attr);
        if (value) img.setAttribute(attr, swap(value));
      });
      // Its hi-res variants were derived from the OLD id, so let the upgrade
      // pass rebuild them against the photo now in place.
      img.removeAttribute('data-cc-hires');
    });
  }

  function applyVariant(tile, printing) {
    // The endpoint hands back the canonical combination URL, anchor included -
    // building it client-side from bare attribute ids produced links PrestaShop
    // could not resolve.
    if (printing.url) {
      tile.querySelectorAll('a[href]').forEach(function (link) {
        link.setAttribute('href', printing.url);
      });
    }

    // Quick view reads the combination off the tile, so it must point at this
    // printing too - otherwise the modal opens on a different variant than the
    // tile the shopper clicked.
    tile.setAttribute('data-id-product-attribute', String(printing.idpa));
    tile.querySelectorAll('[data-id-product-attribute]').forEach(function (el) {
      el.setAttribute('data-id-product-attribute', String(printing.idpa));
    });
    var quickInput = tile.querySelector('input[name="id_product_attribute"]');
    if (quickInput) quickInput.value = String(printing.idpa);

    // The endpoint sends the price already formatted for the storefront's locale
    // and currency; formatting it here would hardcode one of them.
    var price = tile.querySelector('.product-miniature__price');
    if (price && printing.priceFormatted) {
      price.textContent = printing.priceFormatted;
    }

    // The chips are rebuilt from the (now rewritten) href by run().
    var chips = tile.querySelector('.cc-chips');
    if (chips) chips.remove();

    /**
     * A graded tile shows the slab, not the loose card.
     *
     * The product cover is the bare scan - correct for the raw copies, wrong for
     * a slab, where the holder and its label are most of what is being bought.
     * The composite is wired to the combination, so the tile can point at it.
     */
    if (printing.idImage) repointImages(tile, printing.idImage);

    tile.setAttribute('data-cc-printing', printing.printing);
    if (printing.grading) {
      tile.setAttribute('data-cc-grading', printing.grading);
    } else {
      tile.removeAttribute('data-cc-grading');
    }
  }

  /**
   * Product page: show stock for the selected SKU and for the card overall.
   * Data comes from the module (window.cryptocardsStock) because the theme's
   * markup carries neither number.
   */
  function renderStock(ctx, root) {
    var data = ctx.stock;
    if (!data) return;

    // Hummingbird's price block is BEM: .product__prices, not .product-prices.
    var anchor = root.querySelector('.product__prices, .js-product-prices');
    if (!anchor) return;

    var box = root.querySelector('.cc-stock');
    if (!box) {
      box = document.createElement('div');
      box.className = 'cc-stock';
      anchor.parentNode.insertBefore(box, anchor.nextSibling);
    }

    // Variant <select> option values are attribute ids; sorted, they form the same
    // signature the module built server-side.
    var ids = Array.prototype.map
      .call(root.querySelectorAll('select[name^="group"]'), function (sel) { return parseInt(sel.value, 10); })
      .filter(function (id) { return !isNaN(id); })
      .sort(function (a, b) { return a - b; });

    var signature = ids.join(',');
    var here = Object.prototype.hasOwnProperty.call(data.variants, signature)
      ? data.variants[signature]
      : null;

    var level = here === null ? '' : (here <= 0 ? 'out' : (here <= 3 ? 'low' : 'ok'));
    var variantLine = here === null
      ? '<span class="cc-stock__variant">' + t('selectVariant') + '</span>'
      : '<span class="cc-stock__variant cc-stock__variant--' + level + '">' +
        (here <= 0 ? t('outOfStock') : here + ' ' + t('inStock')) +
        (data.skuCount ? '<small>' + t('thisPrintingCondition') + '</small>' : '') +
        '</span>';

    /**
     * No variant axes means no split to show.
     *
     * The two-box layout exists to separate "this printing and condition" from
     * "this card across all its variants". A sealed box has neither - one line
     * of stock is the whole truth about it, and pairing it with "across 0
     * variants" read as an error rather than as an absence.
     */
    if (!data.skuCount) {
      box.innerHTML = variantLine;

      return;
    }

    box.innerHTML =
      variantLine +
      '<span class="cc-stock__total">' + data.total + ' ' + t('total') +
      '<small>' + t(data.skuCount === 1 ? 'acrossVariant' : 'acrossVariants', data.skuCount) +
      '</small></span>';
  }

  /**
   * Set + print-run badges directly under the product title.
   *
   * On Base Set, "shadowed" vs "shadowless" is the difference between two entirely
   * different products at very different prices, so it is stated explicitly on both
   * — never left to be inferred from the absence of a label.
   */
  /**
   * The printing currently chosen in the variant selector, or null.
   *
   * Resolved by ATTRIBUTE ID - the option values - not by the visible label. Every
   * label on this page is translated, and matching one would work in English and
   * quietly stop working in French, which is exactly how the chips, the search
   * index and the shadowless badge each broke in turn.
   */
  /**
   * The entry from `byId` matching whichever variant option is currently chosen.
   *
   * Matched on the input NAME ("group[7]"), not on a wrapper class - Hummingbird
   * calls the wrapper `product-variant`, singular, and a selector built on
   * `.product-variants` matched nothing at all. The map is keyed by attribute id
   * because every label on this page is translated.
   */
  function selectedValue(byId, root) {
    if (!byId) return null;

    var chosen = null;
    root.querySelectorAll('select[name^="group["]').forEach(function (select) {
      var entry = byId[String(select.value)];
      if (entry) chosen = entry;
    });
    root.querySelectorAll('input[type="radio"][name^="group["]:checked').forEach(function (radio) {
      var entry = byId[String(radio.value)];
      if (entry) chosen = entry;
    });

    return chosen;
  }

  function selectedPrinting(root) {
    var chosen = selectedValue(window.cryptocardsPrintingsById, root);

    return chosen && !chosen.skip ? chosen : null;
  }

  function selectedCondition(root) {
    return selectedValue(window.cryptocardsConditionsById, root);
  }

  function selectedLanguage(root) {
    return selectedValue(window.cryptocardsLanguagesById, root);
  }

  /**
   * The card's identity, under its title.
   *
   * Uses the SAME chip classes as the listing tiles and the cart rather than a
   * parallel badge vocabulary - two visual languages for the same five facts made a
   * card look like a different kind of object depending on the page it was on.
   */
  function renderSetBadges(ctx, root) {
    var set = ctx.set;
    if (!set || !set.name) return;

    // The printing chip tracks the selector, so an existing line is replaced
    // rather than left stale after a variant switch.
    var existing = root.querySelector('.cc-setline');
    if (existing) existing.remove();

    var title = root.querySelector('.product__name, h1[itemprop="name"], .page-header h1, h1');
    if (!title) return;

    // One canonical order everywhere a card appears:
    // set, condition, printing, print run, rarity, card language.
    var badges = '<span class="cc-chip cc-chip--set">' + escapeHtml(set.name) + '</span>';

    // Condition of the SELECTED variant. A tile states the grade it is offering,
    // and the product page was the one surface that made you read the dropdown
    // for it - on the page where the decision is actually made.
    var condition = selectedCondition(root);
    if (condition) {
      badges += '<span class="cc-chip ' + condition.cls + '">' +
        escapeHtml(condition.label) + '</span>';
    }

    // The printing of the SELECTED variant, so the whole SKU is stated on one line
    // rather than half in badges and half in dropdowns.
    var printing = selectedPrinting(root);
    if (printing) {
      badges += '<span class="cc-chip ' + printing.cls + '"' +
        (printing.edition ? ' data-edition="' + escapeHtml(printing.edition) + '"' : '') +
        '>' + escapeHtml(printing.label) + '</span>';
    }

    if (set.printRun === 'shadowless') {
      badges += '<span class="cc-chip cc-chip--run cc-chip--shadowless">' +
        escapeHtml(t('shadowless')) + '</span>';
    } else if (set.printRun === 'shadowed') {
      badges += '<span class="cc-chip cc-chip--run cc-chip--shadowed">' +
        escapeHtml(t('shadowed')) + '</span>';
    }

    // Rarity was body text here and a chip in the cart, so the two pages disagreed
    // about how much it matters. It is card identity - it belongs on the line.
    if (set.rarity) {
      badges += '<span class="cc-chip cc-chip--rarity">' +
        escapeHtml(set.rarity) + '</span>';
    }

    /**
     * The card's language, from the SELECTED variant.
     *
     * It used to come from a product-level feature and sat in the title as "[EN]".
     * Language is a variant axis now - one product can hold an English and a French
     * copy - so it is read off the SKU like printing and condition, and stated in
     * full rather than as a code.
     */
    var language = selectedLanguage(root);
    if (language) {
      badges += '<span class="cc-chip ' + language.cls + '">' +
        escapeHtml(language.label) + '</span>';
    }

    var line = document.createElement('div');
    line.className = 'cc-setline';
    line.innerHTML = badges;
    title.parentNode.insertBefore(line, title.nextSibling);
  }

  /** The SKU currently selected, as an attribute-id signature. */
  function currentSignature(root) {
    return Array.prototype.map
      .call(root.querySelectorAll('select[name^="group"]'), function (sel) { return parseInt(sel.value, 10); })
      .filter(function (id) { return !isNaN(id); })
      .sort(function (a, b) { return a - b; })
      .join(',');
  }

  /**
   * Serialised-copy display. See docs/operations-pipeline.md §2.4 — the rule is
   * driven by how many of THIS sku are in stock, not by the product.
   */
  function renderCopies(ctx, root) {
    var data = ctx.copies;
    var stock = ctx.stock;
    if (!data || !data.serialised || !stock) return;

    var host = root.querySelector('.cc-stock');
    if (!host) return;

    var existing = root.querySelector('.cc-copies');
    if (existing) existing.remove();

    // cryptocardsCopies is keyed by id_product_attribute; the selectors give an
    // attribute signature, so translate via the stock map's ordering.
    var sig = currentSignature(root);
    var skuId = stock.signatureToSku ? stock.signatureToSku[sig] : null;
    var sku = skuId ? data.skus[skuId] : null;
    if (!sku) return;

    var box = document.createElement('div');
    box.className = 'cc-copies';

    // Deliberately no stock numbers anywhere below: the .cc-stock box directly
    // above already states the count for this printing and condition, and saying
    // it twice in two different phrasings ("3 in stock" / "One in stock") read as
    // two different facts. This panel is only ever about photography.
    /**
     * The stock photo owns the gallery until the shopper picks a serial.
     *
     * A single copy in stock used to swap its own photo straight into the
     * gallery, on the reasoning that the listing IS that card. It reads wrong:
     * the stock image is the best picture on the page - and for a slab it is the
     * composited holder - so a listing silently downgraded itself to a raw
     * photograph nobody asked to see, and did it on the product page AND in the
     * browser tile. Photos of the actual item are worth showing on demand, which
     * is what the picker below is for; they are not worth showing INSTEAD.
     */
    if (sku.count === 1 && !sku.photographed) {
      var only = sku.copies[0];
      if (sku.policy === 'stock_only') {
        box.innerHTML =
          '<p class="cc-copies__note"><span>' + escapeHtml(t('soldByCondition')) + '</span>' +
          '<small>' + escapeHtml(t('soldByConditionBody')) + '</small></p>';
      } else {
        box.innerHTML =
          '<p class="cc-copies__note"><span>' + escapeHtml(t('serial')) + ' ' +
          '<span class="cc-copies__uid">' + escapeHtml(only.uid) + '</span></span>' +
          '<small>' + escapeHtml(t('photoPending')) + '</small></p>';
      }
      host.parentNode.insertBefore(box, host.nextSibling);
      return;
    }

    if (sku.photographed > 0) {
      /**
       * A viewport wrapping a track, not a plain grid.
       *
       * The track scrolls horizontally so a SKU with fifty photographed copies
       * is dragged through rather than dumped down the page, and so more can be
       * appended to the end as they load.
       */
      box.innerHTML =
        '<details class="cc-copies__picker" open><summary>' + escapeHtml(t('chooseExactCard')) + ' ' +
        '<span class="cc-copies__badge">' + sku.photographed + ' ' +
        escapeHtml(t('photographed')) + '</span></summary>' +
        '<div class="cc-copies__viewport"><div class="cc-copies__track"></div></div>' +
        '<div class="cc-copies__actions">' +
        '<button type="button" class="cc-copies__confirm" disabled>' +
        escapeHtml(t('confirmCopy')) + '</button>' +
        '<span class="cc-copies__count" role="status"></span>' +
        '</div>' +
        '<p class="cc-copies__hint">' + escapeHtml(t('copyPickerHint')) + '</p></details>';
      host.parentNode.insertBefore(box, host.nextSibling);
      appendCopyTiles(box.querySelector('.cc-copies__track'), sku.copies);
      wireCopyPicker(box, sku, root);

      return;
    }

    // Several in stock, none photographed. Previously this rendered nothing at
    // all, which left the panel silently missing on exactly the listings where a
    // buyer most wants to know whether photos exist.
    box.innerHTML = sku.policy === 'stock_only'
      ? '<p class="cc-copies__note"><span>' + escapeHtml(t('soldByCondition')) + '</span>' +
        '<small>' + escapeHtml(t('soldByConditionBody')) + '</small></p>'
      : '<p class="cc-copies__note"><span>' + escapeHtml(t('notPhotographedYet')) + '</span>' +
        '<small>' + escapeHtml(t('notPhotographedYetBody')) + '</small></p>';
    host.parentNode.insertBefore(box, host.nextSibling);
  }

  /** Render copy tiles into the carousel track. Used for the first page and every page after. */
  function appendCopyTiles(track, copies) {
    if (!track || !copies) return;
    var html = copies.map(function (copy) {
      if (!copy.captured) return '';

      return '<button type="button" class="cc-copies__item" data-copy="' + escapeHtml(copy.uid) + '"' +
        ' aria-pressed="false">' +
        '<img src="' + escapeHtml(copy.image) + '" alt="Copy ' + escapeHtml(copy.uid) + '" loading="lazy">' +
        '<span class="cc-copies__tick" aria-hidden="true"></span>' +
        '<span>' + escapeHtml(copy.uid) + '</span></button>';
    }).join('');
    track.insertAdjacentHTML('beforeend', html);
  }

  /**
   * Choosing copies: click to look, confirm to buy.
   *
   * Clicking a tile only PREVIEWS that copy in the main gallery, so a shopper can
   * work along the row comparing cards without committing to any of them.
   * Confirming is what adds one to the order, which is also what makes selecting
   * several possible - with click-to-select there is no way to inspect a card you
   * have not already chosen.
   *
   * The selection drives the line quantity: three chosen copies is an order for
   * three, and the serials ride along on the form so the cart hook reserves those
   * exact physical cards instead of any three of the SKU.
   */
  /**
   * Chosen serials, kept OUTSIDE the picker, keyed by SKU.
   *
   * Setting the line quantity fires a change event, and PrestaShop answers it
   * with a full product refresh - so choosing a second copy tore down the panel
   * about two seconds later and took the selection with it, un-ticking both
   * cards and emptying the form field. Any refresh does this (changing a variant
   * does too), so the fix is to hold the choice somewhere the re-render cannot
   * reach rather than to avoid triggering one.
   *
   * Keyed by SKU because copies belong to one combination: switching from Near
   * Mint to Lightly Played must not carry the previous variant's serials over.
   */
  var copyChoices = {};

  /**
   * Photos per serial, kept beside the choices and for the same reason.
   *
   * A chosen copy has to be re-shown after a re-render, and by then the picker's
   * own list has been rebuilt from the first page - a copy chosen from page three
   * would no longer be in it. Remembering the photos by uid keeps a selection
   * displayable however it was found.
   */
  var copyPhotos = {};

  function wireCopyPicker(box, sku, root) {
    var choiceKey = (sku.idProduct || '') + ':' + (sku.id || 0);
    var selected = (copyChoices[choiceKey] || []).slice();
    var previewing = null;
    var confirm = box.querySelector('.cc-copies__confirm');
    var counter = box.querySelector('.cc-copies__count');

    /** Copies are looked up across every page loaded so far, not just the first. */
    var known = {};
    var remember = function (copy) {
      known[copy.uid] = copy;
      if (copy.photos && copy.photos.length) copyPhotos[copy.uid] = copy.photos;
    };
    (sku.copies || []).forEach(remember);
    box.addEventListener('cc:copies-added', function (event) {
      (event.detail || []).forEach(remember);
    });

    /** Open a copy's photos in the main gallery. */
    var showCopy = function (uid) {
      var photos = copyPhotos[uid] || (known[uid] && known[uid].photos);
      if (photos && photos.length) swapGallery(photos, root);
    };

    /**
     * Never more copies than the SKU actually has.
     *
     * The picker can show copies that are photographed but no longer available,
     * so the ceiling comes from stock rather than from the tile count.
     */
    var ceiling = Math.max(1, sku.count || 1);

    var paint = function () {
      box.querySelectorAll('.cc-copies__item').forEach(function (tile) {
        var uid = tile.getAttribute('data-copy');
        var isSelected = selected.indexOf(uid) !== -1;
        tile.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        tile.classList.toggle('cc-copies__item--chosen', isSelected);
        tile.classList.toggle('cc-copies__item--previewing', uid === previewing);
      });

      if (confirm) {
        var isSelected = previewing !== null && selected.indexOf(previewing) !== -1;
        var full = selected.length >= ceiling && !isSelected;
        confirm.disabled = previewing === null || full;
        confirm.textContent = isSelected ? t('removeCopy') : (full ? t('copyLimitReached') : t('confirmCopy'));
      }
      if (counter) {
        // Singular and plural are separate keys: French pluralises differently
        // and a bare "%d exemplaire(s)" reads as machine output.
        counter.textContent = selected.length
          ? t(selected.length === 1 ? 'copiesSelected' : 'copiesSelectedPlural', selected.length)
          : '';
      }

      copyChoices[choiceKey] = selected.slice();
      setChosenCopy(selected.join(','), root);
      syncQuantity(selected.length, root);
    };


    box.addEventListener('click', function (event) {
      var tile = event.target.closest('.cc-copies__item');
      if (tile) {
        event.preventDefault();
        // Looking, not choosing: open every shot of this copy in the gallery.
        previewing = tile.getAttribute('data-copy');
        showCopy(previewing);
        paint();

        return;
      }

      if (!event.target.closest('.cc-copies__confirm') || previewing === null) return;
      event.preventDefault();

      var at = selected.indexOf(previewing);
      if (at === -1) {
        if (selected.length >= ceiling) return;
        selected.push(previewing);
      } else {
        selected.splice(at, 1);
        /**
         * Dropping a copy falls back to the one chosen before it, and only when
         * nothing is chosen at all does the stock gallery return. Leaving a
         * photograph of a card the shopper just declined would state the
         * opposite of what they did.
         */
        if (selected.length === 0) {
          restoreStockGallery(root);
          previewing = null;
        } else {
          previewing = selected[selected.length - 1];
          showCopy(previewing);
        }
      }
      paint();
    });

    wireCopyCarousel(box, sku, root);

    /**
     * A chosen copy keeps the gallery, including across a re-render.
     *
     * The product refresh that follows a quantity change rebuilds the gallery
     * from the stock images, so a shopper who had picked two cards was returned
     * to the reference scan while the picker still showed both as chosen - the
     * page contradicted itself. The most recent choice is what is shown, and
     * clicking any other tile still previews it.
     */
    if (selected.length) {
      previewing = selected[selected.length - 1];
      showCopy(previewing);
    }

    paint();
  }

  /**
   * Drag to scroll, and load the next page as the end comes into reach.
   *
   * Two behaviours that have to coexist: a pointer drag must move the row, and a
   * plain click must still choose a card. They are told apart by distance - a
   * press that travels more than a few pixels is a drag, and suppressing the
   * click that follows it is what stops a shopper who flicked the row from
   * accidentally previewing whatever ended up under their finger.
   */
  /**
   * Drag, wheel and flick-suppression for any horizontal strip.
   *
   * Shared by the product-page picker and both cart modals: a row of cards
   * behaves the same wherever it appears, and a modal with twenty cards in it is
   * no less in need of dragging than the page is.
   */
  function wireDragScroll(viewport) {
    if (!viewport || viewport.ccDragWired) return;
    viewport.ccDragWired = true;

    var down = false;
    var moved = 0;
    var startX = 0;
    var startScroll = 0;

    viewport.addEventListener('pointerdown', function (event) {
      if (event.button !== 0) return;
      down = true;
      moved = 0;
      startX = event.clientX;
      startScroll = viewport.scrollLeft;
    });
    viewport.addEventListener('pointermove', function (event) {
      if (!down) return;
      var delta = event.clientX - startX;
      if (Math.abs(delta) > 3) {
        moved = Math.abs(delta);
        viewport.classList.add('cc-copies__viewport--dragging');
      }
      viewport.scrollLeft = startScroll - delta;
    });
    var release = function () {
      down = false;
      viewport.classList.remove('cc-copies__viewport--dragging');
    };
    viewport.addEventListener('pointerup', release);
    viewport.addEventListener('pointerleave', release);
    viewport.addEventListener('pointercancel', release);

    // Capture phase, so a flick never lands as a click on whatever stopped
    // under the pointer.
    viewport.addEventListener('click', function (event) {
      if (moved > 6) {
        event.preventDefault();
        event.stopPropagation();
        moved = 0;
      }
    }, true);

    /**
     * A vertical wheel scrolls the row horizontally.
     *
     * Only while there is somewhere left to go, so reaching either end hands the
     * wheel back to the page instead of trapping it.
     */
    viewport.addEventListener('wheel', function (event) {
      var delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
      if (!delta) return;
      var limit = viewport.scrollWidth - viewport.clientWidth;
      if ((delta < 0 && viewport.scrollLeft <= 0) || (delta > 0 && viewport.scrollLeft >= limit)) {
        return;
      }
      event.preventDefault();
      viewport.scrollLeft += delta;
    }, { passive: false });
  }

  function wireCopyCarousel(box, sku, root) {
    var viewport = box.querySelector('.cc-copies__viewport');
    var track = box.querySelector('.cc-copies__track');
    if (!viewport || !track) return;

    wireDragScroll(viewport);

    /**
     * Paging state lives on the closure, not the DOM.
     *
     * `loaded` counts tiles already rendered rather than pages fetched, so a
     * short final page cannot leave the two out of step.
     */
    var loaded = track.querySelectorAll('.cc-copies__item').length;
    var total = sku.photographed || loaded;
    var pageSize = (window.cryptocardsCopies && window.cryptocardsCopies.pageSize) || 24;
    var busy = false;

    var loadMore = function () {
      if (busy || loaded >= total || !window.cryptocardsCopiesUrl) return;
      busy = true;

      var note = document.createElement('span');
      note.className = 'cc-copies__loading';
      note.textContent = t('loadingCopies');
      track.appendChild(note);

      var url = window.cryptocardsCopiesUrl +
        (window.cryptocardsCopiesUrl.indexOf('?') === -1 ? '?' : '&') +
        'id_product=' + encodeURIComponent(sku.idProduct || currentProductId(root)) +
        '&id_product_attribute=' + encodeURIComponent(sku.id || '0') +
        '&offset=' + loaded + '&limit=' + pageSize;

      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          note.remove();
          var copies = (data && data.copies) || [];
          if (data && typeof data.total === 'number') total = data.total;
          if (!copies.length) {
            // Nothing came back: stop asking, or a wrong total loops forever.
            total = loaded;

            return;
          }
          appendCopyTiles(track, copies);
          loaded += copies.length;
          // The picker owns selection state, so it is told what arrived rather
          // than re-reading the DOM.
          box.dispatchEvent(new CustomEvent('cc:copies-added', { detail: copies }));
          busy = false;
        })
        .catch(function () { note.remove(); busy = false; });
    };

    viewport.addEventListener('scroll', function () {
      var remaining = viewport.scrollWidth - viewport.scrollLeft - viewport.clientWidth;
      if (remaining < 240) loadMore();
    });
  }

  /** The product id this page is showing, read from the listing markup. */
  function currentProductId(root) {
    var scope = root && root.querySelector ? root : document;
    var node = scope.querySelector('[data-id-product]');
    if (node) return node.getAttribute('data-id-product');
    var input = scope.querySelector('input[name="id_product"]');

    return input ? input.value : '';
  }

  /**
   * Recorded once the line is actually in the cart.
   *
   * There is no cart line to attach a choice to until then, so the selection is
   * held client-side and posted on PrestaShop's updateCart event.
   *
   * Registered ONCE, globally, rather than per picker. The picker is re-rendered
   * whenever the product refreshes, and a listener per instance meant several
   * firing on one add-to-cart, each posting the selection as it stood when that
   * instance was built. The endpoint replaces a line's choices, so whichever
   * stale one landed last won - two chosen copies were stored as one.
   */
  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updateCart', function () {
      Object.keys(copyChoices).forEach(function (key) {
        var parts = key.split(':');
        recordCopyChoice(parts[0], parts[1], copyChoices[key]);
      });

      /**
       * The cart re-renders its lines wholesale, exactly as the product list
       * does, so everything the theme adds to a line has to be added again -
       * the chips, the "show selected cards" button and the quantity ceiling.
       * Without this the first quantity change reverted a line to PrestaShop's
       * raw attribute list and lost the button with it.
       */
      setTimeout(function () {
        refreshCartLineData().then(function () {
          // The cart has spoken: drop our local running total and re-read it.
          document.querySelectorAll('.product-line').forEach(function (line) {
            delete line.ccPending;
          });

          /**
           * A dialog still open means the operation is not over - the cart
           * answering is only half of a flow that ends when the shopper does.
           */
          if (!document.querySelector('.cc-modal')) unlockLines();
          /**
           * The authoritative pass. watchCartLines() has already repainted from
           * what was known at swap time, which is right for everything except
           * the figures a quantity change moves - so this corrects those and,
           * on the common path where nothing moved, changes nothing at all.
           */
          decorateCartLines();
          watchCartLines();
          wireCartCopies();
        });
      }, 120);
    });
  }

  /**
   * Re-read the per-line data after a cart change.
   *
   * Stock, chosen serials and quantities all move when a line changes, and the
   * copy of cryptocardsCartLines that shipped with the page describes the cart as
   * it was before. Re-fetching the cart page and reading its emitted globals is
   * enough here and avoids a second endpoint that would have to agree with the
   * first.
   */
  function refreshCartLineData() {
    return fetch(window.location.pathname + window.location.search, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.text(); })
      .then(function (html) {
        var match = html.match(/cryptocardsCartLines\s*=\s*(\{[\s\S]*?\});/);
        if (match) {
          try { window.cryptocardsCartLines = JSON.parse(match[1]); } catch (e) { /* keep the old view */ }
        }
      })
      .catch(function () { /* the stale view still renders */ });
  }

  /** Tell the server which serials a cart line is asking for. */
  function recordCopyChoice(productId, skuId, uids) {
    if (!window.cryptocardsChooseUrl || !productId) return Promise.resolve();

    var body = new URLSearchParams();
    body.set('id_product', String(productId));
    body.set('id_product_attribute', String(skuId || 0));
    body.set('uids', (uids || []).join(','));

    return fetch(window.cryptocardsChooseUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body.toString()
    }).catch(function () { /* the order still ships FIFO */ });
  }

  /**
   * The line quantity follows the selection.
   *
   * Choosing three copies is an order for three; choosing none leaves the input
   * alone at whatever the shopper set by hand, because the picker is optional and
   * must not overwrite a deliberate quantity with 1.
   */
  function syncQuantity(count, root) {
    if (count < 1) return;
    var input = (root || document).querySelector('input[name="qty"], input.js-cart-line-product-quantity, #quantity_wanted');
    if (!input || Number(input.value) === count) return;

    /**
     * The value is set WITHOUT firing a change event.
     *
     * PrestaShop answers a quantity change by re-rendering the whole product,
     * and that re-render is what made the picker unusable: the gallery snapped
     * back to the stock photos a second after choosing, the tile row was rebuilt
     * and scrolled back to the start, and a click landing during the rebuild
     * selected whichever card had moved under it. It only bit when the selection
     * actually moved the quantity, which is why choosing one copy looked fine
     * and choosing two did not.
     *
     * Nothing needs the event: the input is the visible control, and the form
     * posts its value on add-to-cart.
     */
    input.value = String(count);
  }

  function setChosenCopy(uid, root) {
    var form = root.querySelector('.product-add-to-cart form, form[action*="cart"], #add-to-cart-or-refresh');
    if (!form) return;

    var input = form.querySelector('input[name="cc_copy_uid"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'cc_copy_uid';
      form.appendChild(input);
    }
    input.value = uid;

    var note = root.querySelector('.cc-copies__chosen');
    if (!note) {
      note = document.createElement('p');
      note.className = 'cc-copies__chosen';
      var picker = root.querySelector('.cc-copies__picker');
      if (picker) picker.appendChild(note);
    }
    // uid is a comma-separated list once several copies can be chosen, so the
    // note spaces them out rather than printing "A7K2QX,B3M8LP".
    note.textContent = uid
      ? t('reservedForYou') + ' ' + uid.split(',').join(', ')
      : t('noCopyChosen');
  }

  /**
   * Replaces the product gallery with one copy's own photographs.
   *
   * Used only when a SKU is down to its last card: at that point the listing IS
   * that physical item, so the reference scan would be misleading. Hummingbird
   * renders the gallery as a Bootstrap carousel plus a thumbnail strip, and both
   * have to be rewritten - swapping only the hidden quickview cover changes
   * nothing on screen.
   */
  /**
   * The stock gallery, kept so deselecting can put it back.
   *
   * Captured lazily at the first swap rather than at render: by then the theme
   * has finished appending the printing's own photos to a filtered graded
   * gallery, so what is stashed is the complete stock view a shopper started
   * from. Cleared whenever the SKU changes, because PrestaShop rebuilds the
   * gallery from scratch and the old markup describes a different variant.
   */
  var stockGallery = null;

  function galleryNodes(root) {
    var scope = root && root.querySelector ? root : document;

    return {
      track: scope.querySelector('.carousel-inner'),
      list: scope.querySelector('.product__thumbnails-list')
    };
  }

  function stashStockGallery(root) {
    if (stockGallery) return;
    var nodes = galleryNodes(root);
    if (!nodes.track) return;
    stockGallery = {
      track: nodes.track.innerHTML,
      thumbs: nodes.list ? nodes.list.innerHTML : null
    };
  }

  function restoreStockGallery(root) {
    if (!stockGallery) return false;
    var nodes = galleryNodes(root);
    if (!nodes.track) return false;
    nodes.track.innerHTML = stockGallery.track;
    if (nodes.list && stockGallery.thumbs !== null) nodes.list.innerHTML = stockGallery.thumbs;

    return true;
  }

  function swapGallery(photos, root) {
    if (!photos || !photos.length) return;
    stashStockGallery(root);

    var track = document.querySelector('.carousel-inner');
    var slides = Array.prototype.slice.call(document.querySelectorAll('.carousel-item'));
    if (track && slides.length) {
      // A copy can carry more shots than the printing had stock photos, so grow
      // the carousel rather than silently dropping the extras.
      while (slides.length < photos.length) {
        var clone = slides[0].cloneNode(true);
        clone.classList.remove('active');
        track.appendChild(clone);
        slides.push(clone);
      }
      slides.forEach(function (slide, index) {
        var img = slide.querySelector('img');
        if (index < photos.length && img) {
          img.src = photos[index].url;
          img.removeAttribute('srcset');
          img.setAttribute('alt', photos[index].side || 'photo');
          slide.classList.toggle('active', index === 0);
        } else {
          slide.remove();
        }
      });
    }

    var list = document.querySelector('.product__thumbnails-list');
    if (list) {
      var items = Array.prototype.slice.call(list.children);
      while (items.length && items.length < photos.length) {
        var thumbClone = items[0].cloneNode(true);
        list.appendChild(thumbClone);
        items.push(thumbClone);
      }
      items.forEach(function (item, index) {
        var img = item.tagName === 'IMG' ? item : item.querySelector('img');
        if (index < photos.length && img) {
          img.src = photos[index].url;
          img.removeAttribute('srcset');
          img.setAttribute('data-cc-slide', String(index));
        } else {
          item.remove();
        }
      });

      // Thumbnails drive the carousel; rebinding keeps that working after a swap.
      list.querySelectorAll('[data-cc-slide]').forEach(function (img) {
        img.addEventListener('click', function () {
          var index = parseInt(img.getAttribute('data-cc-slide'), 10);
          document.querySelectorAll('.carousel-item').forEach(function (slide, i) {
            slide.classList.toggle('active', i === index);
          });
        });
      });
    }
  }

  /** The product context published for the page's own product. */
  function pageContext() {
    return {
      stock: window.cryptocardsStock,
      set: window.cryptocardsSet,
      copies: window.cryptocardsCopies,
      gallery: window.cryptocardsGallery
    };
  }

  /**
   * Puts the printing's own photos back into a filtered gallery.
   *
   * Selecting a graded SKU makes PrestaShop show only the image wired to that
   * combination, so a slab listing lost the front and back scans entirely - the
   * two pictures that show the actual card inside the holder. They are appended
   * AFTER the composite, so a graded selection reads slab, front, back; an
   * ungraded one is untouched, because nothing was filtered out of it and it
   * already runs front, back, then every composite.
   */
  function ensureStockPhotos(ctx, root) {
    var base = ctx.gallery && ctx.gallery.base;
    if (!base || !base.length) return;

    var scope = root && root.querySelector ? root : document;

    /**
     * Applied to EVERY carousel and thumbnail list on the page.
     *
     * The inline gallery and the fullscreen viewer are separate .carousel-inner
     * containers holding the same images, so appending to just the first left
     * the zoom view showing the slab on its own - the one place a buyer goes
     * specifically to look closely at the card.
     */
    var tracks = Array.prototype.slice.call(scope.querySelectorAll('.carousel-inner'));
    var lists = Array.prototype.slice.call(scope.querySelectorAll('.product__thumbnails-list'));
    if (!tracks.length) return;

    // Which images a container already shows, read off the URLs themselves.
    // PrestaShop rebuilds this markup wholesale on every variant change, so any
    // state attached to the nodes would be thrown away with them.
    var shownIn = function (node) {
      var seen = {};
      Array.prototype.forEach.call(node.querySelectorAll('img'), function (img) {
        var found = (img.getAttribute('src') || img.getAttribute('srcset') || '')
          .match(/\/(\d+)-[A-Za-z0-9_]+\//);
        if (found) seen[found[1]] = true;
      });

      return seen;
    };

    tracks.forEach(function (track) {
      var slides = Array.prototype.slice.call(track.querySelectorAll('.carousel-item'));
      if (!slides.length) return;
      var present = shownIn(track);

      base.forEach(function (id) {
        if (present[String(id)]) return;
        var slide = slides[slides.length - 1].cloneNode(true);
        slide.classList.remove('active');
        repointImages(slide, id);
        track.appendChild(slide);
        present[String(id)] = true;
      });
    });

    lists.forEach(function (list) {
      if (!list.children.length) return;
      var present = shownIn(list);
      var index = list.children.length;

      base.forEach(function (id) {
        if (present[String(id)]) return;
        var thumb = list.children[list.children.length - 1].cloneNode(true);
        repointImages(thumb, id);
        // The thumbnail is what drives the carousel, so its slide index has to
        // match the position its slide actually landed in.
        thumb.classList.remove('active');
        thumb.removeAttribute('aria-current');
        thumb.setAttribute('data-bs-slide-to', String(index));
        thumb.querySelectorAll('.js-thumb-selected').forEach(function (el) {
          el.classList.remove('js-thumb-selected');
        });
        list.appendChild(thumb);
        present[String(id)] = true;
        ++index;
      });
    });
  }

  /** Everything the product page adds, applied to one product root. */
  function enhanceProduct(ctx, root) {
    renderStock(ctx, root);
    renderSetBadges(ctx, root);
    // Before renderCopies: the picker stashes the gallery to restore it on
    // deselect, and what it stashes has to be the COMPLETE stock view.
    ensureStockPhotos(ctx, root);
    renderCopies(ctx, root);
  }

  /**
   * The quick view is a product page in a modal, so it gets the product page's
   * enhancements - stock box, badge line and the serial picker included.
   *
   * It renders inside a LISTING, where none of the product globals exist, so the
   * context is fetched per product and cached. Without this the modal was the one
   * surface still showing a bare Hummingbird product: no stock depth, no print-run
   * badge, and no way to choose your exact card on a shop built around exactly that.
   */
  var quickViewContexts = {};

  function wireQuickView() {
    // Bootstrap's shown.bs.modal bubbles to the document, so one listener covers
    // every quick view without having to hook the tile that opened it.
    document.addEventListener('shown.bs.modal', function (event) {
      var modal = event.target;
      if (!modal || !modal.classList || !modal.classList.contains('quickview')) return;

      var productId = modal.getAttribute('data-id-product');
      if (!productId) return;

      applyQuickView(modal, productId);

      // The modal's own variant selectors swap the SKU in place, exactly as the
      // product page does, so the panels have to follow.
      if (!modal.hasAttribute('data-cc-wired')) {
        modal.setAttribute('data-cc-wired', '1');
        modal.addEventListener('change', function (change) {
          if (change.target && /^group\[/.test(change.target.name || '')) {
            setTimeout(function () { applyQuickView(modal, productId); }, 350);
          }
        });
      }
    });
  }

  function applyQuickView(modal, productId) {
    var cached = quickViewContexts[productId];
    if (cached) {
      enhanceProduct(cached, modal);

      return;
    }

    var url = window.cryptocardsContextUrl;
    if (!url) return;

    fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'id_product=' + encodeURIComponent(productId), {
      credentials: 'same-origin'
    })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.cryptocardsStock) return;
        quickViewContexts[productId] = {
          stock: data.cryptocardsStock,
          set: data.cryptocardsSet,
          copies: data.cryptocardsCopies
        };
        enhanceProduct(quickViewContexts[productId], modal);
      })
      // A failed lookup must leave the modal as PrestaShop rendered it, never
      // half-enhanced or broken.
      .catch(function () {});
  }

  /**
   * Writes the live figures into the hero.
   *
   * The hero is a CMS block, so its numbers are whatever provisioning last baked
   * in. Anything empty is left as the baked value rather than blanked - a stale
   * number beats an empty slot where a number should be.
   */
  function renderStats() {
    var stats = window.cryptocardsStats;
    if (!stats) return;

    document.querySelectorAll('[data-cc-stat]').forEach(function (slot) {
      var value = stats[slot.getAttribute('data-cc-stat')];
      if (value !== undefined && value !== null && value !== '') {
        slot.textContent = value;
      }
    });
  }

  /**
   * Puts each menu entry's artwork behind it.
   *
   * The category id comes out of the link, not from a data attribute: ps_mainmenu
   * renders no identifier into the markup, and its URLs are always
   * "/{id}-{slug}". Applied as a custom property so the CSS owns how faint it is;
   * a menu that competes with the page it sits over is worse than a plain one.
   */
  function renderNavImages() {
    var images = window.cryptocardsNavImages;
    if (!images) return;

    /**
     * The right panel's links only.
     *
     * `.ps-mainmenu__tree-item` is the TOP bar, which stays plain. `.submenu__left-item`
     * is the narrow left rail - Singles / Sealed / Graded - which stays plain too:
     * those three own no artwork so they inherit a random card, and the rail is too
     * narrow to show an image as anything but a fragment.
     */
    document.querySelectorAll('.menu-item').forEach(function (link) {
      if (link.hasAttribute('data-cc-art')) return;

      var match = (link.getAttribute('href') || '').match(/\/(\d+)-[^\/]*$/);
      var art = match && images[match[1]];
      if (!art) return;

      link.style.setProperty('--cc-nav-image', 'url("' + art + '")');
      link.setAttribute('data-cc-art', '1');
    });
  }

  /**
   * Turns the print regions in the Singles panel into a tab strip.
   *
   * ps_mainmenu renders a category that has children as a group: the parent
   * becomes `.menu-item__group-main-item` and the children follow it. With region
   * between Singles and the eras that is already the right shape - one group per
   * region, each holding that region's eras - it just stacks them, and two regions
   * of 15 eras is a 30-link wall.
   *
   * Switching is on HOVER and navigating on CLICK, deliberately. A collector who
   * wants "Japanese singles" as a page clicks the tab and gets one; a collector
   * browsing for an era just sweeps across and the grid follows. Making the tab
   * click-to-switch would have cost the first of those, which is the whole reason
   * region is a category and not a filter.
   *
   * The strip renders even with one region. A lone tab is a control with no choice
   * behind it, which argued for hiding it - but hiding it also hides the axis, and
   * a shopper who cannot see that the catalogue is Western cannot tell whether
   * Japanese is missing or simply not shown.
   */
  /**
   * The navigation, for a phone.
   *
   * The desktop panel is a matrix: three sections down the left, and for each of
   * them a region strip over a grid of eras. Hummingbird's own mobile menu
   * cannot express that - it collapsed our injected structure to a zero-height
   * strip, so the hamburger opened nothing at all - and the matrix is the point
   * of the navigation, not decoration on top of it.
   *
   * So the drawer is built from the SAME markup the panel uses rather than from
   * a second copy of the data: sections are its left rail, regions and eras are
   * the right panel that goes with each. Anything the server adds to one appears
   * in the other without being told twice.
   *
   * What changes is the verbs. Hover picks a region on a desktop; there is no
   * hover on a phone, so a region is a chip you tap to filter and the era rows
   * underneath are what navigate. The section itself stays reachable through a
   * "view all" row that follows whichever region is selected - so section,
   * section x region, and era are all one tap apart, which is the whole matrix.
   */
  function buildMobileNav() {
    if (document.querySelector('.cc-mnav')) return null;

    var sections = document.querySelectorAll('.submenu__left-item');
    var panels = document.querySelectorAll('.submenu__right-items');
    if (!sections.length) return null;

    var nav = document.createElement('div');
    nav.className = 'cc-mnav';
    nav.innerHTML =
      '<div class="cc-mnav__scrim"></div>' +
      '<aside class="cc-mnav__panel" role="dialog" aria-modal="true">' +
      '<header class="cc-mnav__head">' +
      '<span class="cc-mnav__title">' + escapeHtml(t('browse')) + '</span>' +
      '<button type="button" class="cc-mnav__close" aria-label="' +
      escapeHtml(t('close')) + '"><i class="material-icons" aria-hidden="true">close</i></button>' +
      '</header><div class="cc-mnav__body"></div></aside>';

    var body = nav.querySelector('.cc-mnav__body');

    [].forEach.call(sections, function (section, index) {
      var panel = panels[index];

      /**
       * The two shapes the panel comes in.
       *
       * Under Singles a region is a CATEGORY, so its eras arrive grouped by
       * region and the chips pick between those groups. Under Sealed and Graded
       * a region is a FACET, so there is one flat list of product types or
       * graders and the chips are links that filter the section. Same matrix,
       * different plumbing - and the drawer has to read both rather than assume
       * the first, which is how Sealed and Graded ended up as dead rows.
       */
      var groups = panel
        ? [].filter.call(panel.children, function (node) {
            return node.tagName === 'UL' && node.classList.contains('menu-item__group--child');
          })
        : [];
      var flat = panel
        ? [].filter.call(panel.children, function (node) {
            return node.tagName === 'UL' && node.classList.contains('menu-item__group--nochild');
          })
        : [];

      /**
       * A section with nothing under it is a link, not an accordion. "Browse
       * Pokémon Sets" is a page in its own right; giving it a chevron that opens
       * an empty tray would be a control that lies about having contents.
       */
      if (!groups.length && !flat.length) {
        var plain = document.createElement('a');
        plain.className = 'cc-mnav__row cc-mnav__row--plain';
        plain.href = section.getAttribute('href') || '#';
        plain.textContent = section.textContent.trim();
        body.appendChild(plain);

        return;
      }

      var block = document.createElement('section');
      block.className = 'cc-mnav__block';

      var head = document.createElement('button');
      head.type = 'button';
      head.className = 'cc-mnav__row cc-mnav__row--head';
      head.innerHTML = '<span>' + escapeHtml(section.textContent.trim()) + '</span>' +
        '<i class="material-icons cc-mnav__chev" aria-hidden="true">expand_more</i>';
      head.setAttribute('aria-expanded', 'false');
      block.appendChild(head);

      var tray = document.createElement('div');
      tray.className = 'cc-mnav__tray';

      var chips = document.createElement('div');
      chips.className = 'cc-mnav__regions';
      tray.appendChild(chips);

      var list = document.createElement('div');
      list.className = 'cc-mnav__eras';
      tray.appendChild(list);

      var viewAll = document.createElement('a');
      viewAll.className = 'cc-mnav__row cc-mnav__row--all';
      tray.appendChild(viewAll);

      /**
       * Facet sections: the chips NAVIGATE rather than filter.
       *
       * Their region strip is a row of filter links the server built, and the
       * list below it - product types, graders - does not change with region.
       * Making these look like the toggle chips Singles uses would promise a
       * filter that is really a page, so they are rendered as what they are.
       */
      if (!groups.length) {
        [].forEach.call(panel.querySelectorAll('.cc-region-tab'), function (tab) {
          var chip = document.createElement('a');
          chip.className = 'cc-mnav__region cc-mnav__region--link';
          chip.href = tab.getAttribute('href') || '#';
          chip.textContent = tab.textContent.trim();
          chips.appendChild(chip);
        });

        flat.forEach(function (group) {
          [].forEach.call(group.querySelectorAll('a.menu-item'), function (link) {
            var row = document.createElement('a');
            row.className = 'cc-mnav__row cc-mnav__row--era menu-item';
            row.href = link.getAttribute('href') || '#';
            row.textContent = link.textContent.trim();
            row.style.cssText = link.style.cssText;
            list.appendChild(row);
          });
        });

        viewAll.href = section.getAttribute('href') || '#';
        viewAll.textContent = t('viewAll', section.textContent.trim());
      }

      /**
       * One entry per region, plus "All" in front of them. Each remembers the
       * page it stands for, so the "view all" row can follow the selection.
       */
      var regions = groups.length ? [{
        label: t('all'),
        // "View all All" says nothing. Across every region the row leads to the
        // section itself, so that is what it is called.
        allLabel: section.textContent.trim(),
        href: section.getAttribute('href') || '#',
        groups: groups
      }] : [];
      groups.forEach(function (group) {
        var main = group.querySelector('.menu-item__group-main-item');
        if (!main) return;
        regions.push({
          label: main.textContent.trim(),
          href: main.getAttribute('href') || '#',
          groups: [group]
        });
      });

      var paint = function (chosen) {
        [].forEach.call(chips.children, function (chip, at) {
          chip.classList.toggle('is-active', at === chosen);
        });

        var region = regions[chosen];
        list.innerHTML = '';
        region.groups.forEach(function (group) {
          // The region's code is appended only when more than one region is on
          // screen, for the same reason the desktop panel does it: otherwise two
          // "Scarlet & Violet" rows sit together saying nothing about which is
          // which.
          var code = region.groups.length > 1 ? (group.getAttribute('data-cc-code') || '') : '';
          [].forEach.call(group.querySelectorAll('a.menu-item'), function (link) {
            if (link.classList.contains('menu-item__group-main-item')) return;
            var row = link.cloneNode(true);
            row.className = 'cc-mnav__row cc-mnav__row--era menu-item';
            row.textContent = (link.getAttribute('data-cc-label') || link.textContent.trim()) +
              (code ? ' (' + code + ')' : '');
            // The artwork rides along: it is an inline custom property on the
            // original, so the clone keeps it without being re-rendered.
            row.style.cssText = link.style.cssText;
            list.appendChild(row);
          });
        });

        viewAll.href = region.href;
        viewAll.textContent = t('viewAll', region.allLabel || region.label);
      };

      regions.forEach(function (region, at) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'cc-mnav__region';
        chip.textContent = region.label;
        chip.addEventListener('click', function () { paint(at); });
        chips.appendChild(chip);
      });
      if (regions.length) paint(0);

      head.addEventListener('click', function () {
        var open = block.hasAttribute('data-cc-open');
        // One tray at a time: three open at once is a wall of eras on a screen
        // that can show about eight rows.
        body.querySelectorAll('.cc-mnav__block[data-cc-open]').forEach(function (other) {
          other.removeAttribute('data-cc-open');
          other.querySelector('.cc-mnav__row--head').setAttribute('aria-expanded', 'false');
        });
        if (!open) {
          block.setAttribute('data-cc-open', '');
          head.setAttribute('aria-expanded', 'true');
        }
      });

      block.appendChild(tray);
      body.appendChild(block);
    });

    // The section the shopper is already in opens itself; failing that, the first.
    var here = body.querySelector('.cc-mnav__block');
    if (here) {
      here.setAttribute('data-cc-open', '');
      here.querySelector('.cc-mnav__row--head').setAttribute('aria-expanded', 'true');
    }

    var close = function () {
      nav.removeAttribute('data-cc-open');
      document.documentElement.classList.remove('cc-mnav-open');
    };
    nav.querySelector('.cc-mnav__scrim').addEventListener('click', close);
    nav.querySelector('.cc-mnav__close').addEventListener('click', close);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') close();
    });

    document.body.appendChild(nav);

    return nav;
  }

  /**
   * The hamburger opens OUR drawer, not the theme's.
   *
   * Bound in the capture phase so the theme's own handler never runs: leaving it
   * to run as well meant its empty tree opened behind the drawer and took the
   * page's scroll with it.
   */
  function wireMobileNav() {
    var toggle = document.querySelector('.ps-mainmenu__mobile-toggle');
    if (!toggle || toggle.ccWired) return;
    toggle.ccWired = true;

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      var nav = document.querySelector('.cc-mnav') || buildMobileNav();
      if (!nav) return;

      var open = nav.hasAttribute('data-cc-open');
      nav.toggleAttribute('data-cc-open', !open);
      document.documentElement.classList.toggle('cc-mnav-open', !open);
    }, true);
  }

  function wireNavRegions() {
    document.querySelectorAll('.submenu__right-items').forEach(function (panel) {
      if (panel.hasAttribute('data-cc-regions')) return;

      var groups = [].filter.call(panel.children, function (node) {
        return node.tagName === 'UL' && node.classList.contains('menu-item__group--child');
      });
      if (!groups.length) return;

      var strip = document.createElement('div');
      strip.className = 'cc-region-tabs';
      var codes = window.cryptocardsRegionCodes || {};

      /**
       * Era links remember their own label, because the ALL view rewrites them.
       *
       * A combined list puts two "Scarlet & Violet" rows next to each other -
       * one Western, one Japanese - which is unreadable without saying which is
       * which. Under All they gain their region's code; under a single region
       * the code is redundant and comes back off.
       */
      groups.forEach(function (group) {
        var head = group.querySelector('.menu-item__group-main-item');
        var code = head ? (codes[head.textContent.trim()] || '') : '';
        group.setAttribute('data-cc-code', code);
        [].forEach.call(group.querySelectorAll('a.menu-item'), function (link) {
          if (link === head) return;
          if (!link.hasAttribute('data-cc-label')) {
            link.setAttribute('data-cc-label', link.textContent.trim());
          }
        });
      });

      var applyLabels = function (showCodes) {
        groups.forEach(function (group) {
          var code = group.getAttribute('data-cc-code') || '';
          [].forEach.call(group.querySelectorAll('a.menu-item[data-cc-label]'), function (link) {
            var base = link.getAttribute('data-cc-label');
            link.textContent = (showCodes && code) ? base + ' (' + code + ')' : base;
          });
        });
      };

      var select = function (tab, shown) {
        [].forEach.call(strip.children, function (other) { other.removeAttribute('data-cc-active'); });
        groups.forEach(function (other) { other.removeAttribute('data-cc-active'); });
        shown.forEach(function (group) { group.setAttribute('data-cc-active', ''); });
        tab.setAttribute('data-cc-active', '');
        applyLabels(shown.length > 1);
      };

      // "All" first: every region's eras in one list, region-tagged.
      var allTab = document.createElement('a');
      allTab.className = 'cc-region-tab';
      allTab.href = (panel.closest('.js-sub-menu') || document)
        .querySelector('.submenu__left-item')?.getAttribute('href') || '#';
      allTab.textContent = (window.cryptocardsI18n && window.cryptocardsI18n.all) || 'All';
      var showAll = function () { select(allTab, groups); };
      allTab.addEventListener('mouseenter', showAll);
      allTab.addEventListener('focus', showAll);
      strip.appendChild(allTab);

      groups.forEach(function (group) {
        var head = group.querySelector('.menu-item__group-main-item');
        if (!head) return;

        var tab = document.createElement('a');
        tab.className = 'cc-region-tab';
        tab.href = head.getAttribute('href') || '#';
        tab.textContent = head.textContent.trim();

        var activate = function () { select(tab, [group]); };
        tab.addEventListener('mouseenter', activate);
        tab.addEventListener('focus', activate);

        strip.appendChild(tab);
      });

      showAll();
      panel.insertBefore(strip, panel.firstChild);
      panel.setAttribute('data-cc-regions', '');
    });
  }


  /**
   * Shortcut strips for the Sealed and Graded flyout panels.
   *
   * Singles gets real region TABS (wireNavRegions - its regions are categories
   * and hovering switches the era grid). Sealed and Graded get the same strip
   * visually, but every tab is a plain filter link built server-side, because
   * region is a facet on those sections. Graded additionally gets one chip per
   * grading company with stock.
   */
  function wireSectionStrips() {
    var strips = window.cryptocardsSectionStrips;
    if (!strips) return;

    var panels = document.querySelectorAll('.submenu__right-items');

    /**
     * A tab both navigates (click) and RESCOPES the panel's entry links
     * (hover): with Western active, "Booster Boxes" points at western booster
     * boxes and "PSA" at western PSA slabs - the same switching feel the
     * Singles panel has, expressed through facet queries because region is a
     * facet on these sections.
     */
    var makeStrip = function (panel, tabs) {
      var strip = document.createElement('div');
      strip.className = 'cc-region-tabs';
      var links = [].slice.call(panel.querySelectorAll('a.menu-item'));
      links.forEach(function (link) {
        link.setAttribute('data-cc-base', link.getAttribute('href') || '');
      });

      tabs.forEach(function (tab, index) {
        var a = document.createElement('a');
        a.className = 'cc-region-tab';
        a.href = tab.url;
        a.textContent = tab.label;
        var activate = function () {
          [].forEach.call(strip.children, function (other) { other.removeAttribute('data-cc-active'); });
          a.setAttribute('data-cc-active', '');
          links.forEach(function (link) {
            var base = link.getAttribute('data-cc-base');
            if (!tab.query) { link.setAttribute('href', base); return; }
            // A base that already filters (Graded's Grading-X) gets the region
            // appended INSIDE its q; a plain category link starts one.
            link.setAttribute('href', base.indexOf('?q=') !== -1 || base.indexOf('&q=') !== -1
              ? base + encodeURIComponent('/' + tab.query)
              : base + (base.indexOf('?') !== -1 ? '&' : '?') + 'q=' + encodeURIComponent(tab.query));
          });
        };
        a.addEventListener('mouseenter', activate);
        a.addEventListener('focus', activate);
        if (index === 0) activate();
        strip.appendChild(a);
      });
      return strip;
    };

    // Panels follow the left rail's order: Singles, Sealed, Graded.
    [{panel: panels[1], data: strips.sealed}, {panel: panels[2], data: strips.graded}]
      .forEach(function (entry) {
        if (!entry.panel || !entry.data || !entry.data.tabs || !entry.data.tabs.length) return;
        if (entry.panel.hasAttribute('data-cc-strip')) return;
        entry.panel.insertBefore(makeStrip(entry.panel, entry.data.tabs), entry.panel.firstChild);
        entry.panel.setAttribute('data-cc-strip', '');
      });
  }

  /**
   * The game root's Categories facet gains a synthetic "Graded" entry.
   *
   * Singles and Sealed appear there naturally - they are child categories with
   * stock - but graded copies live as combinations on Singles products, so the
   * third form a shopper thinks in never shows up. The entry looks like its
   * siblings and applies the grading filter, merged into whatever q is already
   * active. Re-injected after every faceted ajax re-render.
   */
  function injectGradedFacetOption() {
    var strips = window.cryptocardsSectionStrips;
    if (!strips || !strips.isGameRoot || !strips.gradedFacetOption) return;

    var section = document.querySelector('#search_filters section[data-type="category"], #search_filters_wrapper section[data-type="category"]');
    if (!section || section.querySelector('[data-cc-graded-option]')) return;
    var list = section.querySelector('ul');
    var sibling = list && list.querySelector('li');
    if (!sibling) return;

    var item = sibling.cloneNode(true);
    item.setAttribute('data-cc-graded-option', '');

    var target = (function () {
      var q = new URLSearchParams(location.search).get('q');
      var merged = q ? q + '/' + strips.gradedFacetOption.query : strips.gradedFacetOption.query;
      return location.pathname + '?q=' + encodeURIComponent(merged);
    })();

    // The facet row's name is a bare TEXT NODE inside the js-search-link
    // anchor, beside a magnitude span. Rebuild the anchor's content outright
    // and keep the module's own classes, so its delegated click handling
    // treats this entry exactly like the real ones.
    var anchor = item.querySelector('a.js-search-link, label a');
    if (!anchor) return;
    anchor.setAttribute('href', target);
    anchor.textContent = strips.gradedFacetOption.label;

    var input = item.querySelector('input');
    if (input) {
      input.checked = false;
      input.id = 'facet_input_cc_graded';
      input.setAttribute('data-search-url', target);
      input.addEventListener('change', function () { anchor.click(); });
      var forLabel = item.querySelector('label[for]');
      if (forLabel) forLabel.setAttribute('for', input.id);
    }
    list.appendChild(item);
  }

  /**
   * Browse pages: the subcategory chip wall becomes a scrollable row of art
   * cards, the homepage treatment. The chip wall scaled like a tag cloud - the
   * Japanese Scarlet & Violet era rendered SEVENTY-SIX badges before a single
   * product appeared.
   *
   * One row, horizontal scroll, drag-to-scroll everywhere, arrow controls on
   * desktop (hidden when nothing overflows).
   */
  /**
   * The card row is RENDERED by the server; this gives it behaviour.
   *
   * It used to be built here, which meant the header's plain chips were on
   * screen until the swap - see the module's displayHeaderCategory hook.
   */
  function buildCategoryCards() {
    var wrap = document.querySelector('.cc-scards');
    if (!wrap || wrap.ccWired) return;
    wrap.ccWired = true;

    var row = wrap.querySelector('.cc-scards__row');
    if (!row) return;

    wrap.querySelectorAll('.cc-scards__arrow').forEach(function (btn) {
      var back = btn.classList.contains('cc-scards__arrow--prev');
      btn.addEventListener('click', function () {
        row.scrollBy({ left: (back ? -1 : 1) * row.clientWidth * 0.8, behavior: 'smooth' });
      });
    });

    var syncArrows = function () {
      var overflow = row.scrollWidth > row.clientWidth + 4;
      wrap.classList.toggle('cc-scards--overflow', overflow);
      wrap.classList.toggle('cc-scards--at-start', row.scrollLeft < 4);
      wrap.classList.toggle('cc-scards--at-end', row.scrollLeft + row.clientWidth > row.scrollWidth - 4);
    };
    row.addEventListener('scroll', syncArrows, { passive: true });
    window.addEventListener('resize', syncArrows);

    /**
     * Drag-to-scroll with pointer events, desktop and mobile alike. The click
     * suppressor matters: without it every drag that starts on a card ends by
     * navigating to it.
     */
    var drag = null;
    row.addEventListener('pointerdown', function (e) {
      drag = { x: e.clientX, left: row.scrollLeft, moved: false };
    });
    row.addEventListener('pointermove', function (e) {
      if (!drag) return;
      var dx = e.clientX - drag.x;
      if (Math.abs(dx) > 6) {
        drag.moved = true;
        row.classList.add('cc-scards__row--dragging');
        row.scrollLeft = drag.left - dx;
      }
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
      row.addEventListener(type, function () {
        if (drag && drag.moved) {
          var suppress = function (e) { e.preventDefault(); e.stopPropagation(); };
          row.addEventListener('click', suppress, { capture: true, once: true });
        }
        drag = null;
        row.classList.remove('cc-scards__row--dragging');
      });
    });

    syncArrows();
  }

  /**
   * Breadcrumb links keep the active filters.
   *
   * The section cards carry filters DOWN the tree (server-side, see
   * carryFilters); this is the way back UP. Without it, going a few levels into
   * the sets and stepping back out silently dropped the graded filter a shopper
   * deliberately chose - the trip down preserved it and the trip back threw it
   * away.
   *
   * Everything is carried here, unfiltered: an ancestor is a SUPERSET of where
   * you are, so every facet that was valid below is still valid above. Facets
   * the ancestor's template does not offer are ignored by the module rather
   * than erroring.
   */
  function carryFiltersOnBreadcrumb() {
    var query = new URLSearchParams(location.search).get('q');
    if (!query) return;

    document.querySelectorAll('.breadcrumb a, nav[aria-label="breadcrumb"] a').forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href || href.indexOf('q=') !== -1 || link.hasAttribute('data-cc-carried')) return;
      // Home is the shop, not a filtered view of the catalogue.
      if (/\/(index\.php)?$/.test(href.replace(location.origin, '')) ) return;
      link.setAttribute('href', href + (href.indexOf('?') !== -1 ? '&' : '?') + 'q=' + encodeURIComponent(query));
      link.setAttribute('data-cc-carried', '');
    });
  }

  /**
   * The filter rail becomes a drawer behind a Filters button.
   *
   * The button sits with Sort by, both right-aligned; the whole left column is
   * hidden (its BROWSE tree said nothing the breadcrumb does not), which hands
   * its 330px to the product grid. The drawer keeps the ORIGINAL #search_filters
   * element - the faceted module re-renders that node in place after every
   * filter change, so moving or cloning it would break filtering.
   */
  /**
   * One-time page furniture, kept out of the per-render path.
   *
   * Reparenting the column, the backdrop and the Escape handler must happen
   * exactly once. The button must be rebuilt on every render. Doing both in one
   * pass meant the drawer could not be re-wired after a sort without stacking a
   * duplicate backdrop and another Escape listener each time.
   */
  var filtersDrawerReady = false;

  function wireFiltersDrawer() {
    var left = document.querySelector('.left-column');
    var selection = document.querySelector('.products__selection');
    if (!left || !selection) return;
    if (!left.querySelector('#search_filters_wrapper, #search_filters')) return;

    document.body.classList.add('cc-filters-page');

    /**
     * PrestaShop ships the facets TWICE, and which copy is filled depends on
     * the width.
     *
     * The wide block carries `#search_filters_wrapper` and Bootstrap's
     * `d-none d-md-block`; the narrow one is a phone-only Bootstrap offcanvas
     * with its own button, parked off-screen until that button opens it. Below
     * md the wide block renders EMPTY and the offcanvas holds the real sections
     * - so the drawer opened onto a hidden shell and a decoy at x=-331, and read
     * as an empty black panel.
     *
     * Our drawer is already the off-canvas, so the phone copy just needs to stop
     * being one: strip the classes that park it and it lays out inline where it
     * sits. The classes come OFF the element rather than being fought in the
     * cascade, because a Bootstrap utility carrying `!important` is not an
     * argument worth having.
     */
    var phoneFacets = left.querySelector('.ps-facetedsearch--mobile');
    if (phoneFacets) {
      phoneFacets.classList.remove('offcanvas', 'offcanvas-start', 'offcanvas-end');
    }

    /**
     * And its button with it. Re-checked on every pass because it lives in the
     * block PrestaShop swaps out on each sort and facet change, exactly like
     * ours does.
     */
    var nativeButton = document.querySelector('.products__filter-button');
    if (nativeButton) nativeButton.remove();

    if (!filtersDrawerReady) {
      filtersDrawerReady = true;

      /**
       * Reparented to <body>. The drawer is position:fixed with a z-index above
       * the header, but .columns-container is its own stacking context at
       * z-index 1, and a fixed child cannot out-stack its context - the header
       * and search bar painted straight over the open drawer. The faceted module
       * re-renders by element id, so the node keeps working from anywhere.
       */
      document.body.appendChild(left);

      var backdrop = document.createElement('div');
      backdrop.className = 'cc-filters-backdrop';
      backdrop.addEventListener('click', function () {
        document.body.classList.remove('cc-filters-open');
      });
      document.body.appendChild(backdrop);
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.body.classList.remove('cc-filters-open');
      });
    }

    /**
     * A collapsed facet still says whether it is doing anything.
     *
     * The module expands a section that has a checked value, which covers the
     * open case; collapsed sections gave no signal at all, so a filter you set
     * and then scrolled past was invisible. Marked here rather than styled
     * blindly, because only the DOM knows what is checked.
     */
    document.querySelectorAll('.left-column section.accordion-item').forEach(function (section) {
      var applied = section.querySelectorAll('input:checked').length;
      section.classList.toggle('cc-facet--applied', applied > 0);
      var head = section.querySelector('.accordion-button');
      if (head) head.setAttribute('data-cc-applied', applied > 0 ? String(applied) : '');
    });

    /**
     * The button is rebuilt per render, because it lives in
     * .products__selection - part of the block PrestaShop swaps out wholesale on
     * every sort and every facet change. Leaving it to the initial boot meant
     * the Filters button simply disappeared the first time anything was sorted,
     * taking the only way into the drawer with it.
     */
    if (document.querySelector('.cc-filters-btn')) return;

    /**
     * The applied filters live ON the button, not in a panel above the grid.
     *
     * PrestaShop renders an "Active filters" block that spans the full width and
     * pushes the products down - a whole band of chrome to say "one filter is
     * on". The count rides the button that opens the drawer instead, which is
     * where you go to change it anyway, and the block is removed.
     *
     * Counted from the DRAWER's checked inputs, not from the chip block:
     * counting chips meant counting markup, and the block wraps its chips in a
     * list that also carries "Clear all", so a single applied filter reported
     * two. A checked input is one applied value, exactly.
     */
    var scope = document.querySelector('.left-column');
    var count = scope
      ? scope.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').length
      : 0;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-tertiary cc-filters-btn';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = (window.cryptocardsI18n && window.cryptocardsI18n.filters) || 'Filters';
    if (count > 0) {
      var badge = document.createElement('span');
      badge.className = 'cc-filters-btn__count';
      badge.textContent = String(count);
      badge.title = count + (count === 1 ? ' filter applied' : ' filters applied');
      btn.appendChild(badge);
    }
    btn.classList.toggle('cc-filters-btn--active', count > 0);
    btn.addEventListener('click', function () {
      var open = document.body.classList.toggle('cc-filters-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    selection.appendChild(btn);
  }

  /**
   * Cart-side copy handling: see what you chose, and stay inside stock.
   *
   * The cart is where a shopper revisits a decision made on the product page, so
   * it has to answer the same question that page did - which physical cards am I
   * buying - and it has to keep the answer honest when they change their mind
   * about how many.
   */
  /**
   * A cart line stops taking clicks while an update is in flight.
   *
   * Each press is an AJAX round trip, so pressing + three times quickly sent
   * three updates all computed from the same starting quantity, and the cart
   * settled on whatever the last response happened to say. Locking on the press
   * and releasing when the cart answers removes the race outright rather than
   * trying to reconcile it afterwards.
   */
  /**
   * Which SKUs are mid-operation, held OUTSIDE the DOM.
   *
   * The cart re-renders its lines on every change, so a flag set on the line
   * element - and the class that dims it - were both destroyed by the very
   * update they were meant to guard. The line came back fresh and clickable
   * while the picker was still opening. Keyed by SKU, the lock outlives the
   * markup, and re-wiring simply re-applies it.
   */
  var busySkus = {};
  var lockTimer = null;

  function lockLine(line, sku) {
    if (!line || !sku) return;
    busySkus[sku] = true;
    line.classList.add('cc-cart-line--busy');

    /**
     * A lock that cannot outlive the thing it is waiting for. Release normally
     * comes from the cart answering, or from the dialog that press opened; if
     * neither ever happens the line would stay dead short of a reload.
     */
    clearTimeout(lockTimer);
    lockTimer = setTimeout(unlockLines, 15000);
  }

  function unlockLines() {
    clearTimeout(lockTimer);
    busySkus = {};
    document.querySelectorAll('.cc-cart-line--busy').forEach(function (line) {
      line.classList.remove('cc-cart-line--busy');
    });
  }

  /**
   * One press at a time, enforced at the earliest event there is.
   *
   * Gating on `click` was too late and too narrow: the control acts on the press,
   * so by the time a click handler ran the update was already away, and disabling
   * the buttons killed the press that was in flight as well as the ones after it.
   * The whole picker is taken out of play on pointerdown instead - the first
   * press proceeds, everything until the cart answers is swallowed here, before
   * any other handler sees it.
   */
  function guardQuantityControl(line, sku) {
    // Re-applied after a re-render: the markup is new, the operation is not.
    if (busySkus[sku]) line.classList.add('cc-cart-line--busy');

    var wrapper = line.querySelector('.js-quantity-button') || line;
    if (wrapper.ccGuarded) return;
    wrapper.ccGuarded = true;

    /**
     * The press that opens the lock has to be let through to the end.
     *
     * One press is several events - pointerdown, mousedown, click - so locking on
     * the first of them and then blocking "while locked" swallowed the rest of
     * that same press. The result was worse than the race it replaced: the first
     * click did nothing, no request was ever sent, and with no response to
     * release it the picker stayed dead. The press in progress is tracked so it
     * can finish; only a NEW press is turned away.
     */
    var pressOpen = false;

    ['pointerdown', 'touchstart'].forEach(function (type) {
      wrapper.addEventListener(type, function (event) {
        if (busySkus[sku]) {
          event.preventDefault();
          event.stopPropagation();

          return;
        }
        lockLine(line, sku);
        pressOpen = true;
      }, true);
    });

    // Also covers presses that never produce a pointerdown - a keyboard
    // activation, or a click dispatched by script.
    wrapper.addEventListener('click', function (event) {
      if (pressOpen) {
        pressOpen = false;

        return;
      }
      if (!busySkus[sku]) {
        lockLine(line, sku);

        return;
      }
      event.preventDefault();
      event.stopPropagation();
    }, true);
  }

  /**
   * What the line's quantity WILL be once everything in flight lands.
   *
   * The input is not a safe thing to count from: it only catches up when the
   * round trip finishes, so clicking faster than the network made every click
   * read the same starting number and answer "this one is free" - four quick
   * presses sailed straight past the chosen-copy count without ever reaching
   * the gate. Counting locally means the arithmetic is right even when several
   * presses are outstanding.
   */
  function pendingQuantity(line, data, quantity) {
    if (typeof line.ccPending !== 'number') {
      line.ccPending = Number(data.quantity) || Number(quantity.value) || 1;
    }

    return line.ccPending;
  }

  /**
   * The line's CURRENT figures, not the ones captured when it was wired.
   *
   * cryptocardsCartLines is replaced wholesale on every refresh, but the
   * quantity handlers are attached once and closed over the entry that existed
   * then - so the gates below were deciding whether to ask about a card from a
   * chosen-count and a stock figure several edits out of date.
   */
  function lineData(sku, captured) {
    return (window.cryptocardsCartLines || {})[String(sku)] || captured;
  }

  function wireCartCopies() {
    var lines = window.cryptocardsCartLines;
    if (!lines) return;

    document.querySelectorAll('.product-line, .cart-summary-product').forEach(function (line) {
      var handle = line.querySelector('[data-id-product-attribute]');
      var sku = handle ? String(handle.getAttribute('data-id-product-attribute')) : null;
      var data = sku && lines[sku];
      if (!data) return;

      var quantity = line.querySelector('.js-cart-line-product-quantity');
      var increment = line.querySelector('.js-increment-button');
      var decrement = line.querySelector('.js-decrement-button');

      /**
       * Listeners are attached once per line and never re-attached.
       *
       * The cart re-renders after every change, but it does NOT always replace
       * the line element - so clearing the guard and re-running stacked a second
       * increment handler on the same button. Both fired, each recording its own
       * idea of the new quantity, and a single click asked for two more cards.
       * The visual parts below are rebuilt every time; the wiring is not.
       */
      var wired = line.hasAttribute('data-cc-cart-wired');
      line.setAttribute('data-cc-cart-wired', '');
      guardQuantityControl(line, sku);

      /**
       * The quantity ceiling is stock for this SKU.
       *
       * PrestaShop lets the control run past availability and only objects after
       * the round trip, which reads as the shop losing the stock between the
       * click and the answer. Stopping at the ceiling states the limit at the
       * moment it is reached.
       */
      /**
       * Raising the quantity ASKS FIRST and adds afterwards.
       *
       * It used to add first and offer the picker when the cart reported back,
       * which meant the count moved on a press that had not been answered yet -
       * and the gap between the two was wide enough to press again, and again,
       * so a fast hand ran the quantity up without ever seeing a card. Opening
       * the dialog as the first thing the press does closes that gap by
       * construction: there is no window in which the count has moved and the
       * question has not been put. It also mirrors what removal already did.
       */
      if (increment && quantity && !wired) {
        increment.addEventListener('click', function (event) {
          // The answer coming back through the same button; it has been asked.
          if (line.ccBypassIncrement) {
            line.ccBypassIncrement = false;
            lockLine(line, sku);

            return;
          }

          var live = lineData(sku, data);
          var after = pendingQuantity(line, live, quantity) + 1;

          /**
           * The quantity ceiling is stock for this SKU.
           *
           * PrestaShop lets the control run past availability and only objects
           * after the round trip, which reads as the shop losing the stock
           * between the click and the answer. Stopping at the ceiling states the
           * limit at the moment it is reached.
           */
          if (after > live.stock) {
            event.preventDefault();
            event.stopPropagation();
            flashCartNote(line, t('cartStockCeiling', live.stock));

            return;
          }

          // Nothing photographed to choose between, or the unit being added is
          // already covered by a chosen card: no question to put.
          if (!live.photographed || after <= (live.chosen || []).length) {
            line.ccPending = after;
            lockLine(line, sku);

            return;
          }

          event.preventDefault();
          event.stopPropagation();
          lockLine(line, sku);
          /**
           * One press adds one card, so it asks about one card - never the
           * backlog of units that were never chosen. A line of eight with three
           * chosen prompts for one, not five.
           */
          openPickerModal(live, sku, 1, function () {
            /**
             * Re-clicking the button PrestaShop already owns, rather than
             * writing the quantity ourselves - the same route removal takes.
             *
             * The lock is dropped first because the guard's job is to turn away
             * a SECOND press, and this is the first one arriving at last. The
             * handler this click reaches takes the lock again before anything
             * can yield, so the gap is not one a shopper can press into.
             */
            unlockLines();
            line.ccBypassIncrement = true;
            increment.click();
          });
        }, true);
      }

      /**
       * The button is RENDERED by the server, with the line; this only gives it
       * behaviour. Building it here meant it arrived a beat after the rest of
       * the line did, so every load and every refresh showed the line without
       * it first. Its label counts chosen copies, which the server knows.
       */
      var show = line.querySelector('.cc-cart-copies__btn');
      if (show && !show.ccWired) {
        show.ccWired = true;
        show.addEventListener('click', function () {
          openChosenModal(lineData(sku, data), line);
        });
      }

      /**
       * Removing a chosen card has to say WHICH card.
       *
       * Only when the decrement would actually cut into the chosen copies: a
       * line of five with three chosen can drop to three freely, because those
       * two units were never anybody's particular card. Below that the shop
       * cannot decide for the shopper which of their photographed cards to give
       * up, so the quantity does not move until they say.
       */
      if (decrement && quantity && !wired) {
        decrement.addEventListener('click', function (event) {
          if (line.ccBypassDecrement) {
            line.ccBypassDecrement = false;
            lockLine(line, sku);

            return;
          }
          var live = lineData(sku, data);
          var chosen = (live.chosen || []).slice();
          var after = pendingQuantity(line, live, quantity) - 1;

          /**
           * Going below one removes the line, and asks nothing.
           *
           * It used to stop dead here, which left the control doing nothing at a
           * quantity of one - a button that does nothing cannot be told apart
           * from one that failed. There is no question to put even when the line
           * has a chosen card: the shopper is removing their only copy, which is
           * a decision they have already expressed, and the bin sitting beside
           * this button does exactly the same thing without asking.
           */
          if (after < 1) {
            event.preventDefault();
            event.stopPropagation();
            unlockLines();
            var bin = line.querySelector('.js-remove-from-cart');
            if (bin) bin.click();

            return;
          }

          if (after >= chosen.length) {
            line.ccPending = after;
            lockLine(line, sku);

            return;
          }

          event.preventDefault();
          event.stopPropagation();
          // Locked while the question is open, so a second press cannot stack
          // another dialog or slip an update past this one.
          lockLine(line, sku);

          /**
           * Reached only with two or more chosen cards. A single chosen copy
           * cannot get here: dropping below it means dropping below one, which
           * is the branch above - so the picker is never opened to ask a
           * question that has one possible answer.
           */
          openRemoveModal(live, chosen.length - after, function (kept) {
            recordCopyChoice(live.idProduct, sku, kept).then(function () {
              /**
               * Re-clicking the button PrestaShop already owns, rather than
               * writing the quantity ourselves. This path is driven by a real
               * control, so letting that control do its job is both simpler and
               * the only version proven to actually move the cart.
               *
               * Unlocked first for the same reason the increase is: the guard
               * refuses a second press, and this is the answer to the first.
               */
              unlockLines();
              line.ccBypassDecrement = true;
              decrement.click();
            });
          });
        }, true);
      }

      /**
       * A typed quantity is gated exactly like the buttons.
       *
       * Typing "1" over a line with three chosen cards used to go straight to
       * PrestaShop, which silently dropped two of the shopper's chosen cards -
       * the buttons were gated and the text field was a hole straight through
       * that gate. Everything that can change a quantity has to answer the same
       * questions.
       */
      if (quantity && !wired) {
        quantity.addEventListener('change', function (event) {
          // Our own writes are already the result of an answered question.
          if (line.ccProgrammatic) return;

          var live = lineData(sku, data);
          var current = Number(live.quantity) || Number(quantity.value) || 1;
          var chosen = (live.chosen || []).length;
          var typed = parseInt(quantity.value, 10);

          if (!isFinite(typed) || typed < 1) {
            event.preventDefault();
            event.stopPropagation();
            quantity.value = String(current);

            return;
          }

          /**
           * Never more than the shop holds, whatever was typed. Clamped rather
           * than rejected, so the shopper still gets the most we can supply, and
           * told why the number changed under them.
           */
          if (typed > live.stock) {
            typed = live.stock;
            flashCartNote(line, t('cartStockCeiling', live.stock));
          }

          if (typed === current) {
            event.preventDefault();
            event.stopPropagation();
            quantity.value = String(current);

            return;
          }

          if (typed < chosen) {
            // Cutting into chosen cards: hold the field and ask which ones go.
            event.preventDefault();
            event.stopPropagation();
            quantity.value = String(current);
            openRemoveModal(live, chosen - typed, function (kept) {
              recordCopyChoice(live.idProduct, sku, kept).then(function () {
                applyQuantity(live.idProduct, sku, current, typed);
              });
            });

            return;
          }

          /**
           * Growing the line by typing asks the same question the + does, and
           * asks it FIRST - about the units this edit adds, not every unit that
           * happens to be unchosen.
           */
          if (typed > current && live.photographed > 0) {
            event.preventDefault();
            event.stopPropagation();
            quantity.value = String(current);
            lockLine(line, sku);
            openPickerModal(live, sku, typed - current, function () {
              applyQuantity(live.idProduct, sku, current, typed);
            });

            return;
          }

          if (Number(quantity.value) !== typed) {
            // Clamped to stock: the shopper's number was not the one we can meet,
            // so it is applied deliberately rather than left to the typed value.
            event.preventDefault();
            event.stopPropagation();
            quantity.value = String(typed);
            applyQuantity(live.idProduct, sku, current, typed);
          }
        }, true);
      }
    });
  }

  /**
   * Write a quantity the shopper has already been asked about.
   *
   * Flagged as ours so the gate above lets it past - it is the ANSWER to a
   * question, not a new one - and unflagged after the round trip so the next
   * edit is questioned again.
   */
  function applyQuantity(productId, skuId, from, to) {
    var delta = to - from;
    if (!delta || !window.prestashop || !prestashop.urls || !prestashop.static_token) return;

    /**
     * PrestaShop's own cart-update endpoint, not a synthetic change event.
     *
     * Writing the input's value and dispatching `change` looked like it worked -
     * the field showed the new number - and the cart never moved: the theme's
     * handler does not act on a programmatic event. The gate above would then
     * have asked which card to give up and quietly dropped neither it nor the
     * quantity. Going through the documented endpoint is the only way to be sure
     * the answer is actually applied.
     */
    var url = prestashop.urls.pages.cart +
      '?update=1&id_product=' + encodeURIComponent(productId) +
      '&id_product_attribute=' + encodeURIComponent(skuId) +
      '&op=' + (delta > 0 ? 'up' : 'down') +
      '&qty=' + Math.abs(delta) +
      '&token=' + encodeURIComponent(prestashop.static_token) +
      '&ajax=1&action=update';

    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function () { window.location.reload(); });
  }

  /** A short-lived note on a cart line, for a limit the shopper just hit. */
  function flashCartNote(line, message) {
    var note = line.querySelector('.cc-cart-note');
    if (!note) {
      note = document.createElement('p');
      note.className = 'cc-cart-note';
      line.appendChild(note);
    }
    note.textContent = message;
    clearTimeout(note.ccTimer);
    note.ccTimer = setTimeout(function () { note.remove(); }, 4000);
  }

  /** The modal shell, shared by both cart dialogs. */
  /**
   * Hold the page still for as long as any dialog is up.
   *
   * Derived from what is actually on screen rather than counted by hand,
   * because the confirmation step opens a SECOND modal over the first and a
   * plain toggle would hand the page back the moment the inner one closed.
   */
  function syncScrollLock() {
    var open = !!document.querySelector('.cc-modal');
    var root = document.documentElement;
    if (open === root.classList.contains('cc-modal-open')) return;

    if (open) {
      /**
       * The scrollbar goes away with the scrolling, so its width is handed back
       * as padding - without it the whole page jumps sideways as a dialog opens.
       */
      var gap = window.innerWidth - root.clientWidth;
      document.body.style.paddingRight = gap > 0 ? gap + 'px' : '';
      root.classList.add('cc-modal-open');

      return;
    }
    root.classList.remove('cc-modal-open');
    document.body.style.paddingRight = '';
  }

  function openModal(title, onClose) {
    var existing = document.querySelector('.cc-modal');
    if (existing) existing.remove();

    var wrap = document.createElement('div');
    wrap.className = 'cc-modal';
    wrap.innerHTML =
      '<div class="cc-modal__backdrop"></div>' +
      '<div class="cc-modal__panel" role="dialog" aria-modal="true">' +
      '<header class="cc-modal__head"><h3>' + escapeHtml(title) + '</h3>' +
      '<button type="button" class="cc-modal__close" aria-label="Close">&times;</button></header>' +
      '<div class="cc-modal__body"></div></div>';

    /**
     * Dismissing and finishing are different outcomes.
     *
     * `close()` is what a dialog calls when it has done its job; the X, the
     * backdrop and Escape are the shopper walking away, and a dialog that
     * changed something on the way in has to undo it.
     */
    var closed = false;
    var close = function () { closed = true; wrap.remove(); syncScrollLock(); };
    var dismiss = function () {
      if (closed) return;
      closed = true;
      wrap.remove();
      syncScrollLock();
      if (typeof onClose === 'function') onClose();
    };

    wrap.querySelector('.cc-modal__backdrop').addEventListener('click', dismiss);
    wrap.querySelector('.cc-modal__close').addEventListener('click', dismiss);
    document.addEventListener('keydown', function esc(e) {
      if (e.key === 'Escape') { dismiss(); document.removeEventListener('keydown', esc); }
    });
    document.body.appendChild(wrap);
    syncScrollLock();

    return { root: wrap, body: wrap.querySelector('.cc-modal__body'), close: close };
  }

  /**
   * A gallery pane inside a modal: one big view, its own thumbnail row.
   *
   * Both cart dialogs are about looking at a specific card, and a grid of 90px
   * tiles cannot answer "what am I actually buying" - the whole point of the
   * copy photos is that they are big enough to inspect.
   */
  function modalGallery(container) {
    container.insertAdjacentHTML('beforeend',
      '<div class="cc-modal__viewer">' +
      '<div class="cc-modal__stage"><img alt=""></div>' +
      '<div class="cc-modal__shots"></div>' +
      '<p class="cc-modal__serial"></p></div>');

    var stage = container.querySelector('.cc-modal__stage img');
    var shots = container.querySelector('.cc-modal__shots');
    var serial = container.querySelector('.cc-modal__serial');

    /**
     * Hold the picture that is up until the next one can be painted.
     *
     * Assigning to `src` blanks the element the moment it is set and leaves it
     * empty until the new file decodes, so every thumbnail click flashed the
     * empty stage - even on an image the browser already had cached, because the
     * gap is in the paint, not the network. Decoding first means the swap is a
     * single frame with a picture on both sides of it.
     */
    var wanted = null;
    function swapStage(url) {
      if (wanted === url) return;
      wanted = url;

      var next = new Image();
      var apply = function () {
        // A click landed while this one was decoding: that one wins.
        if (wanted === url) stage.src = url;
      };
      next.src = url;
      if (next.decode) {
        next.decode().then(apply).catch(apply);

        return;
      }
      if (next.complete) apply();
      else { next.onload = apply; next.onerror = apply; }
    }

    shots.addEventListener('click', function (event) {
      var thumb = event.target.closest('[data-shot]');
      if (!thumb) return;
      swapStage(thumb.getAttribute('data-shot'));
      shots.querySelectorAll('[data-shot]').forEach(function (el) {
        el.classList.toggle('is-active', el === thumb);
      });
    });

    return function show(copy) {
      var photos = (copy && copy.photos && copy.photos.length)
        ? copy.photos
        : (copy && copy.image ? [{ url: copy.image, side: '' }] : []);
      if (!photos.length) return;

      swapStage(photos[0].url);
      serial.textContent = copy.uid ? t('serial') + ' ' + copy.uid : '';

      /**
       * The thumbnail row is only rebuilt when it is a different row.
       *
       * Re-rendering it for a copy showing the same shots tore down images the
       * browser was already painting and made them flash back in - a second
       * flicker underneath the first, for no change at all.
       */
      var key = photos.map(function (photo) { return photo.url; }).join('|');
      if (shots.ccKey !== key) {
        shots.ccKey = key;
        shots.innerHTML = photos.map(function (photo, index) {
          return '<button type="button" class="cc-modal__shot' + (index === 0 ? ' is-active' : '') +
            '" data-shot="' + escapeHtml(photo.url) + '">' +
            '<img src="' + escapeHtml(photo.url) + '" alt="' + escapeHtml(photo.side || '') + '"></button>';
        }).join('');

        return;
      }
      shots.querySelectorAll('[data-shot]').forEach(function (el, index) {
        el.classList.toggle('is-active', index === 0);
      });
    };
  }

  /**
   * A confirm/cancel step for a choice with a consequence worth stating.
   *
   * Rendered as its own layer rather than replacing the dialog underneath, so
   * cancelling returns the shopper exactly where they were with their picks
   * intact.
   */
  function confirmDialog(message, onConfirm) {
    var wrap = document.createElement('div');
    wrap.className = 'cc-modal cc-modal--confirm';
    wrap.innerHTML =
      '<div class="cc-modal__backdrop"></div>' +
      '<div class="cc-modal__panel cc-modal__panel--narrow" role="alertdialog" aria-modal="true">' +
      '<div class="cc-modal__body"><p class="cc-modal__ask">' + escapeHtml(message) + '</p>' +
      '<div class="cc-copies__actions cc-copies__actions--end">' +
      '<button type="button" class="cc-copies__skip cc-confirm__cancel">' + escapeHtml(t('cancel')) + '</button>' +
      '<button type="button" class="cc-copies__confirm cc-confirm__ok">' + escapeHtml(t('confirm')) + '</button>' +
      '</div></div></div>';

    var close = function () { wrap.remove(); syncScrollLock(); };
    wrap.querySelector('.cc-modal__backdrop').addEventListener('click', close);
    wrap.querySelector('.cc-confirm__cancel').addEventListener('click', close);
    wrap.querySelector('.cc-confirm__ok').addEventListener('click', function () {
      close();
      onConfirm();
    });
    document.body.appendChild(wrap);
    syncScrollLock();
  }

  /** Which exact cards this line is buying. */
  function openChosenModal(data) {
    var modal = openModal(t('showSelectedCards'));
    /**
     * Every card here is already bought, so ticking them all says nothing.
     *
     * The highlight marks which one is on the stage instead - the only thing
     * that varies in this dialog, and the only question the row answers.
     */
    /**
     * One card needs no row to choose between - the gallery IS the answer. The
     * add and remove dialogs always keep their row, because there the row is
     * what the shopper is acting on rather than a way of switching views.
     */
    var many = (data.chosen || []).length > 1;
    modal.body.innerHTML = many
      ? '<div class="cc-copies__viewport"><div class="cc-copies__track cc-modal__grid">' +
        (data.chosen || []).map(function (copy, index) {
          return '<button type="button" class="cc-copies__item" data-index="' + index + '">' +
            (copy.image ? '<img src="' + escapeHtml(copy.image) + '" alt="' + escapeHtml(copy.uid) + '">' : '') +
            '<span>' + escapeHtml(copy.uid) + '</span></button>';
        }).join('') +
        '</div></div>'
      : '';

    var show = modalGallery(modal.body);
    var chosen = data.chosen || [];
    var grid = modal.body.querySelector('.cc-modal__grid');

    if (!grid) {
      if (chosen.length) show(chosen[0]);

      return;
    }

    var view = function (index) {
      show(chosen[index]);
      grid.querySelectorAll('[data-index]').forEach(function (tile) {
        tile.classList.toggle('cc-copies__item--viewing', Number(tile.getAttribute('data-index')) === index);
      });
    };

    wireDragScroll(modal.body.querySelector('.cc-copies__viewport'));
    view(0);

    grid.addEventListener('click', function (event) {
      var tile = event.target.closest('[data-index]');
      if (!tile) return;
      view(Number(tile.getAttribute('data-index')));
    });
  }

  /**
   * Choose which chosen copies to give up.
   *
   * Shown as the cards themselves, because "remove one" is meaningless when the
   * shopper picked three specific cards - they are choosing which of their cards
   * to let go, and need to see them to do it.
   */
  function openRemoveModal(data, dropCount, done) {
    // Walking away from the question leaves the line usable again.
    var modal = openModal(t('removeWhichCard'), unlockLines);
    var chosen = (data.chosen || []).slice();

    /**
     * Laid out exactly like the add dialog: choosing on the left with its running
     * count, finishing on the right. The two dialogs ask opposite questions and
     * there is no reason for them to be operated differently.
     */
    /**
     * No lead paragraph: the title asks the question and the counter states the
     * number, so a sentence repeating both only pushed the cards down the panel.
     */
    modal.body.innerHTML =
      '<div class="cc-copies__viewport"><div class="cc-copies__track cc-modal__grid">' +
      chosen.map(function (copy, index) {
        return '<button type="button" class="cc-copies__item" data-index="' + index + '">' +
          (copy.image ? '<img src="' + escapeHtml(copy.image) + '" alt="' + escapeHtml(copy.uid) + '">' : '') +
          '<span class="cc-copies__tick" aria-hidden="true"></span>' +
          '<span>' + escapeHtml(copy.uid) + '</span></button>';
      }).join('') +
      '</div></div>' +
      '<div class="cc-copies__actions">' +
      '<button type="button" class="cc-copies__select" disabled>' + escapeHtml(t('selectCard')) + '</button>' +
      '<span class="cc-copies__count" role="status">0 / ' + dropCount + '</span>' +
      '<button type="button" class="cc-copies__confirm cc-copies__confirm--danger ' +
      'cc-copies__actions--push" disabled>' + escapeHtml(t('confirmRemoval')) + '</button>' +
      '</div>';

    var show = modalGallery(modal.body);
    var grid = modal.body.querySelector('.cc-modal__grid');
    wireDragScroll(modal.body.querySelector('.cc-copies__viewport'));

    var select = modal.body.querySelector('.cc-copies__select');
    var confirm = modal.body.querySelector('.cc-copies__confirm');
    var counter = modal.body.querySelector('.cc-copies__count');
    var dropping = [];
    var viewing = null;

    var paint = function () {
      grid.querySelectorAll('[data-index]').forEach(function (tile) {
        var index = Number(tile.getAttribute('data-index'));
        tile.classList.toggle('cc-copies__item--chosen', dropping.indexOf(index) !== -1);
        tile.classList.toggle('cc-copies__item--viewing', index === viewing);
      });
      counter.textContent = dropping.length + ' / ' + dropCount;
      confirm.disabled = dropping.length !== dropCount;

      var held = viewing !== null && dropping.indexOf(viewing) !== -1;
      select.disabled = viewing === null || (!held && dropping.length >= dropCount);
      select.textContent = held ? t('unselectCard') : t('selectCard');
      select.classList.toggle('cc-copies__select--held', held);
    };

    if (chosen.length) {
      viewing = 0;
      show(chosen[0]);
    }
    paint();

    // Clicking a card only LOOKS at it; marking it for removal is deliberate.
    grid.addEventListener('click', function (event) {
      var tile = event.target.closest('[data-index]');
      if (!tile) return;
      viewing = Number(tile.getAttribute('data-index'));
      show(chosen[viewing]);
      paint();
    });

    select.addEventListener('click', function () {
      if (viewing === null) return;
      var at = dropping.indexOf(viewing);
      if (at === -1) {
        if (dropping.length >= dropCount) return;
        dropping.push(viewing);
      } else {
        dropping.splice(at, 1);
      }
      paint();
    });

    confirm.addEventListener('click', function () {
      var kept = chosen
        .filter(function (copy, index) { return dropping.indexOf(index) === -1; })
        .map(function (copy) { return copy.uid; });
      modal.close();
      // Stays locked through the update that follows; the cart response releases it.
      done(kept);
    });
  }

  /**
   * Pick the ADDITIONAL copies for a quantity increase.
   *
   * Fed by the same paginated endpoint the product page uses, so a line with
   * fifty photographed copies behaves here exactly as it does there. Choosing
   * fewer than the increase is allowed - the remainder falls back to FIFO, which
   * is what happens for a shopper who never opens the picker at all.
   */
  function openPickerModal(data, sku, needed, done) {
    /**
     * Abandoning the dialog leaves the cart exactly as it was.
     *
     * The increase has not happened yet - this dialog is what stands between the
     * press and the quantity - so there is nothing to undo. It used to add first
     * and unwind on close, which meant a moment where the count had moved and
     * the question had not been answered; closing is now simply a no.
     */
    var modal = openModal(t('chooseExactCard'), unlockLines);

    // Title and counter carry the question; a lead sentence only repeated them.
    modal.body.innerHTML =
      '<div class="cc-copies__viewport"><div class="cc-copies__track"></div></div>' +
      '<div class="cc-copies__actions">' +
      '<button type="button" class="cc-copies__select" disabled>' + escapeHtml(t('selectCard')) + '</button>' +
      '<span class="cc-copies__count" role="status">0 / ' + needed + '</span>' +
      '<button type="button" class="cc-copies__confirm cc-copies__actions--push" disabled>' +
      escapeHtml(t('confirm')) + '</button>' +
      '<button type="button" class="cc-copies__skip">' + escapeHtml(t('skipSelection')) + '</button>' +
      '</div>';

    var show = modalGallery(modal.body);
    wireDragScroll(modal.body.querySelector('.cc-copies__viewport'));

    var track = modal.body.querySelector('.cc-copies__track');
    var select = modal.body.querySelector('.cc-copies__select');
    var confirm = modal.body.querySelector('.cc-copies__confirm');
    var skip = modal.body.querySelector('.cc-copies__skip');
    var counter = modal.body.querySelector('.cc-copies__count');

    var picked = [];
    var viewing = null;
    var pool = {};
    // Copies already on this line must not be offered a second time.
    var taken = {};
    (data.chosen || []).forEach(function (copy) { taken[copy.uid] = true; });

    var paint = function () {
      track.querySelectorAll('.cc-copies__item').forEach(function (tile) {
        var uid = tile.getAttribute('data-copy');
        tile.classList.toggle('cc-copies__item--chosen', picked.indexOf(uid) !== -1);
        tile.classList.toggle('cc-copies__item--viewing', uid === viewing);
      });
      counter.textContent = picked.length + ' / ' + needed;
      /**
       * Confirm means "these are my cards", so it is dead until they all are.
       * Adding fewer than the line gained is still allowed - that is what Skip
       * is for, and it says out loud what happens to the remainder.
       */
      confirm.disabled = picked.length !== needed;

      var held = viewing !== null && picked.indexOf(viewing) !== -1;
      select.disabled = viewing === null || (!held && picked.length >= needed);
      select.textContent = held ? t('unselectCard') : t('selectCard');
      select.classList.toggle('cc-copies__select--held', held);
    };

    if (!window.cryptocardsCopiesUrl) return;
    fetch(window.cryptocardsCopiesUrl +
        (window.cryptocardsCopiesUrl.indexOf('?') === -1 ? '?' : '&') +
        'id_product=' + encodeURIComponent(data.idProduct) +
        '&id_product_attribute=' + encodeURIComponent(sku) + '&offset=0&limit=24',
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        var offered = (payload.copies || []).filter(function (copy) { return !taken[copy.uid]; });
        offered.forEach(function (copy) { pool[copy.uid] = copy; });
        appendCopyTiles(track, offered);
        if (offered.length) {
          viewing = offered[0].uid;
          show(offered[0]);
        }
        paint();
      })
      .catch(function () { /* the line still works without a picker */ });

    /**
     * A tile click only LOOKS at a card.
     *
     * Choosing is a separate, deliberate press, so a shopper can work along the
     * row comparing twenty cards without any of them landing in the order.
     */
    track.addEventListener('click', function (event) {
      var tile = event.target.closest('.cc-copies__item');
      if (!tile) return;
      viewing = tile.getAttribute('data-copy');
      if (pool[viewing]) show(pool[viewing]);
      paint();
    });

    select.addEventListener('click', function () {
      if (viewing === null) return;
      var at = picked.indexOf(viewing);
      if (at === -1) {
        if (picked.length >= needed) return;
        picked.push(viewing);
      } else {
        picked.splice(at, 1);
      }
      paint();
    });

    var store = function () {
      /**
       * Sent with the serials already on the line, because the endpoint REPLACES
       * a line's choices rather than merging them - posting only the new ones
       * would drop the originals.
       */
      var all = (data.chosen || []).map(function (copy) { return copy.uid; }).concat(picked);
      /**
       * Recorded before the quantity moves, so the line never exists in a state
       * where the units are there and nobody has said which cards they are.
       */
      recordCopyChoice(data.idProduct, sku, all).then(function () {
        modal.close();
        done();
      });
    };

    confirm.addEventListener('click', store);

    skip.addEventListener('click', function () {
      confirmDialog(t(needed === 1 ? 'skipWarning' : 'skipWarningPlural', needed), function () {
        // Skipping is still a yes to the quantity - it records nothing, so the
        // added units ship FIFO, exactly as for a shopper who never opened this.
        modal.close();
        done();
      });
    });
  }

  function boot() {
    run();
    expandPrintings();
    enhanceProduct(pageContext(), document);
    renderStats();
    renderNavImages();
    wireNavRegions();
    wireMobileNav();
    wireSectionStrips();
    buildCategoryCards();
    wireFiltersDrawer();
    injectGradedFacetOption();
    carryFiltersOnBreadcrumb();
    wireSetDirectory();
    wireQuickView();
    wireCartCopies();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // The variant selector swaps the SKU via AJAX; stock AND the copy panel both
  // describe the selected SKU, so both must re-render.
  function refreshSkuPanels() {
    // The gallery has just been rebuilt for a different variant, so anything
    // stashed from the previous one is stale.
    stockGallery = null;
    // The badge line names the selected printing, so it is a SKU panel too.
    enhanceProduct(pageContext(), document);
  }

  /**
   * Everything a sort or a facet change destroys, rebuilt in one place.
   *
   * PrestaShop's updateProductList replaces the whole product-list block, and
   * that block is bigger than the grid: it takes the category header and the
   * sort bar with it. So the section cards vanished and the era pill wall they
   * cover resurfaced, and the Filters button - which lives in the sort bar -
   * disappeared entirely, leaving no way into the drawer.
   *
   * This used to be three separate updateProductList listeners registered in
   * three places, two of which only re-ran tile decoration. One handler, in a
   * fixed order, is the only way to know what happens after a sort.
   */
  function refreshListing() {
    document.querySelectorAll('[data-cc-expanded]').forEach(function (el) {
      el.removeAttribute('data-cc-expanded');
    });
    run();
    expandPrintings();
    injectGradedFacetOption();
    buildCategoryCards();
    wireSectionStrips();
    wireFiltersDrawer();
    carryFiltersOnBreadcrumb();
  }

  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updatedProduct', function () { setTimeout(refreshSkuPanels, 40); });
    window.prestashop.on('updateProductList', function () { setTimeout(refreshListing, 60); });
  }
  document.addEventListener('change', function (event) {
    if (event.target.closest('.product-variants')) setTimeout(refreshSkuPanels, 350);
  });

  // Faceted search can also swap the list without firing the event (pagination
  // links, chip removal), so a late catch-all still re-decorates.
  document.addEventListener('click', function () { setTimeout(run, 400); });
  window.addEventListener('popstate', function () { setTimeout(run, 400); });
})();
