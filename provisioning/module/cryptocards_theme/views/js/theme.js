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
    var parts = chipsFor(link && link.getAttribute('href'));
    if (!parts.length) return;

    var chips = [];
    // Group slugs are localised ("etat"/"impression" in French), and so are the
    // VALUE slugs, so both are resolved from maps the module emits rather than
    // from English literals in this file.
    var slugs = window.cryptocardsAttrSlugs || { condition: 'condition', printing: 'printing' };
    var conditions = window.cryptocardsConditions || {};
    var printings = window.cryptocardsPrintings || {};

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

    if (!chips.length) return;

    var host = card.querySelector('.product-miniature__infos') || card.querySelector('.product-miniature__inner');
    if (!host) return;

    var wrap = document.createElement('div');
    wrap.className = 'cc-chips';
    wrap.innerHTML = chips
      .map(function (chip) {
        return '<span class="cc-chip ' + chip.cls + '"' +
          (chip.edition ? ' data-edition="' + escapeHtml(chip.edition) + '"' : '') +
          '>' + chip.label + '</span>';
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
        return String(handle.getAttribute('data-id-product-attribute'));
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
      var anchor = rows.length
        ? rows[0]
        : line.querySelector('.product-line__title, .cart-summary-product__link');
      if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(wrap, anchor);
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

  function run() {
    hideEmptyFilterBar();
    ensureLanguageFacet();
    decorateCartLines();
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
   * Expands a listing so every printing gets its own tile.
   *
   * A card that exists as 1st Edition Holofoil AND Unlimited Holofoil is two
   * different purchases at two very different prices, but PrestaShop renders one
   * tile per product and hides the rest. Each clone deep-links to its own printing
   * so the product page opens on what was actually clicked.
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

    fetch(window.cryptocardsPrintingsUrl + (window.cryptocardsPrintingsUrl.indexOf('?') === -1 ? '?' : '&') + query,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        cards.forEach(function (card) {
          var printings = data[card.getAttribute('data-id-product')];
          if (!printings || printings.length < 2) return;
          if (card.getAttribute('data-cc-expanded') === '1') return;
          card.setAttribute('data-cc-expanded', '1');

          printings.forEach(function (printing, index) {
            var tile = index === 0 ? card : card.cloneNode(true);
            applyPrinting(tile, printing);
            if (index > 0) card.parentNode.insertBefore(tile, card.nextSibling);
          });
        });

        run();
        resortByPrice();
      })
      .catch(function () { /* listing still works unexpanded */ });
  }

  function applyPrinting(tile, printing) {
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

    var price = tile.querySelector('.product-miniature__price');
    if (price && typeof printing.price === 'number') {
      price.textContent = '$' + printing.price.toFixed(2);
    }

    // The chips are rebuilt from the (now rewritten) href by run().
    var chips = tile.querySelector('.cc-chips');
    if (chips) chips.remove();

    tile.setAttribute('data-cc-printing', printing.printing);
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
        '<small>' + t('thisPrintingCondition') + '</small></span>';

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
        escapeHtml(t('shadowedBadge')) + '</span>';
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
    if (sku.count === 1) {
      var only = sku.copies[0];
      // One in stock: this listing IS that card. Show its photo if we have one.
      if (only.captured && only.image) {
        swapGallery(only.photos, root);
        box.innerHTML =
          '<p class="cc-copies__note cc-copies__note--exact">' +
          '<span>' + t('exactCardAbove') + '</span>' +
          '<small>' + escapeHtml(t('serial')) + ' <span class="cc-copies__uid">' +
          escapeHtml(only.uid) + '</span> &middot; ' + escapeHtml(t('frontAndBack')) +
          '</small></p>';
      } else if (sku.policy === 'stock_only') {
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
      box.innerHTML =
        '<details class="cc-copies__picker"><summary>' + escapeHtml(t('chooseExactCard')) + ' ' +
        '<span class="cc-copies__badge">' + sku.photographed + ' ' +
        escapeHtml(t('photographed')) + '</span></summary>' +
        '<div class="cc-copies__grid">' +
        sku.copies.map(function (copy) {
          if (!copy.captured) return '';
          return '<button type="button" class="cc-copies__item" data-copy="' + escapeHtml(copy.uid) + '">' +
            '<img src="' + escapeHtml(copy.image) + '" alt="Copy ' + escapeHtml(copy.uid) + '" loading="lazy">' +
            '<span>' + escapeHtml(copy.uid) + '</span></button>';
        }).join('') +
        '</div><p class="cc-copies__hint">' + escapeHtml(t('copyPickerHint')) + '</p></details>';
      host.parentNode.insertBefore(box, host.nextSibling);
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

  /**
   * Clicking a copy tile pins that serial onto the add-to-cart form, so the
   * cart hook reserves that exact physical card rather than any of the SKU.
   */
  function wireCopyPicker(box, sku, root) {
    box.addEventListener('click', function (event) {
      var tile = event.target.closest('.cc-copies__item');
      if (!tile) return;
      event.preventDefault();

      var uid = tile.getAttribute('data-copy');
      var already = tile.getAttribute('aria-pressed') === 'true';

      // Open the chosen card in the main carousel so it can be inspected in
      // detail - every shot of it, not just the thumbnail.
      if (!already) {
        var picked = (sku.copies || []).filter(function (c) { return c.uid === uid; })[0];
        if (picked && picked.photos && picked.photos.length) swapGallery(picked.photos, root);
      }

      box.querySelectorAll('.cc-copies__item').forEach(function (other) {
        other.setAttribute('aria-pressed', 'false');
      });

      // Clicking the selected tile again clears the choice and returns to FIFO.
      setChosenCopy(already ? '' : uid, root);
      if (!already) tile.setAttribute('aria-pressed', 'true');
    });
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
    note.textContent = uid
      ? t('reservedForYou') + ' ' + uid
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
  function swapGallery(photos, root) {
    if (!photos || !photos.length) return;

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
      copies: window.cryptocardsCopies
    };
  }

  /** Everything the product page adds, applied to one product root. */
  function enhanceProduct(ctx, root) {
    renderStock(ctx, root);
    renderSetBadges(ctx, root);
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
  function wireNavRegions() {
    document.querySelectorAll('.submenu__right-items').forEach(function (panel) {
      if (panel.hasAttribute('data-cc-regions')) return;

      var groups = [].filter.call(panel.children, function (node) {
        return node.tagName === 'UL' && node.classList.contains('menu-item__group--child');
      });
      if (!groups.length) return;

      var strip = document.createElement('div');
      strip.className = 'cc-region-tabs';

      groups.forEach(function (group, index) {
        var head = group.querySelector('.menu-item__group-main-item');
        if (!head) return;

        var tab = document.createElement('a');
        tab.className = 'cc-region-tab';
        tab.href = head.getAttribute('href') || '#';
        tab.textContent = head.textContent.trim();

        var activate = function () {
          groups.forEach(function (other) { other.removeAttribute('data-cc-active'); });
          [].forEach.call(strip.children, function (other) { other.removeAttribute('data-cc-active'); });
          group.setAttribute('data-cc-active', '');
          tab.setAttribute('data-cc-active', '');
        };
        tab.addEventListener('mouseenter', activate);
        tab.addEventListener('focus', activate);
        if (index === 0) activate();

        strip.appendChild(tab);
      });

      panel.insertBefore(strip, panel.firstChild);
      panel.setAttribute('data-cc-regions', '');
    });
  }

  function boot() {
    run();
    expandPrintings();
    enhanceProduct(pageContext(), document);
    renderStats();
    renderNavImages();
    wireNavRegions();
    wireSetDirectory();
    wireQuickView();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // The variant selector swaps the SKU via AJAX; stock AND the copy panel both
  // describe the selected SKU, so both must re-render.
  function refreshSkuPanels() {
    // The badge line names the selected printing, so it is a SKU panel too.
    enhanceProduct(pageContext(), document);
  }

  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updatedProduct', function () { setTimeout(refreshSkuPanels, 40); });
    // Sorting and faceted filtering swap the whole product list out. Without this
    // the listing silently collapses back to one tile per product.
    window.prestashop.on('updateProductList', function () {
      setTimeout(function () {
        document.querySelectorAll('[data-cc-expanded]').forEach(function (el) {
          el.removeAttribute('data-cc-expanded');
        });
        run();
        expandPrintings();
      }, 60);
    });
  }
  document.addEventListener('change', function (event) {
    if (event.target.closest('.product-variants')) setTimeout(refreshSkuPanels, 350);
  });

  // Faceted search swaps the product list out via AJAX; re-decorate afterwards.
  document.addEventListener('click', function () { setTimeout(run, 400); });
  window.addEventListener('popstate', function () { setTimeout(run, 400); });
  if (window.prestashop && typeof window.prestashop.on === 'function') {
    window.prestashop.on('updateProductList', function () { setTimeout(run, 60); });
  }
})();
