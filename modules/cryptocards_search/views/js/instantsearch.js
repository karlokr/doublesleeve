/**
 * Instant search dropdown for the storefront search box.
 *
 * Attaches to the theme's existing input rather than replacing the search widget,
 * so the normal form submit still works if JS fails or the index is unavailable.
 */
(function () {
  'use strict';

  var endpoint = window.cryptocardsSearchUrl;
  if (!endpoint) return;

  var input = document.querySelector('#ps_searchbar .js-search-input');
  if (!input) return;

  var wrapper = input.closest('.ps-searchbar') || input.parentNode;
  wrapper.classList.add('cc-search');

  // Hummingbird ships its own AJAX autocomplete on the same input. Left alone it
  // opens a second, competing dropdown next to this one. Dropping the hook class
  // stops it binding (and stops the redundant requests); the CSS also hides its
  // container in case it bound before this script ran.
  wrapper.classList.remove('js-search-widget');
  var themeDropdown = wrapper.querySelector('.js-search-dropdown');
  if (themeDropdown) themeDropdown.remove();

  var panel = document.createElement('div');
  panel.className = 'cc-search__panel';
  panel.setAttribute('role', 'listbox');
  panel.hidden = true;
  wrapper.appendChild(panel);

  var TYPE_LABEL = { set: 'Set', pokemon: 'Pokémon', product: 'Card' };
  var timer = null;
  var lastQuery = '';
  var controller = null;

  function hide() {
    panel.hidden = true;
    panel.innerHTML = '';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function subtitle(hit) {
    if (hit.type === 'set') return escapeHtml(hit.series || '') + ' &middot; ' + escapeHtml(hit.set_code || '');
    if (hit.type === 'pokemon') return 'Browse singles';
    return escapeHtml(hit.set_name || '');
  }

  function render(hits, query) {
    if (!hits.length) {
      panel.innerHTML = '<div class="cc-search__empty">No matches for &ldquo;' + escapeHtml(query) + '&rdquo;</div>';
      panel.hidden = false;
      return;
    }

    panel.innerHTML = hits
      .map(function (hit) {
        var thumb = hit.image
          ? '<img class="cc-search__thumb" src="' + escapeHtml(hit.image) + '" alt="" loading="lazy">'
          : '<span class="cc-search__thumb cc-search__thumb--empty"></span>';

        var price = typeof hit.price === 'number' && hit.price > 0
          ? '<span class="cc-search__price">$' + hit.price.toFixed(2) + '</span>'
          : '';

        return (
          '<a class="cc-search__hit" role="option" href="' + escapeHtml(hit.url || '#') + '">' +
          thumb +
          '<span class="cc-search__body">' +
            '<span class="cc-search__type">' + escapeHtml(TYPE_LABEL[hit.type] || hit.type) + '</span>' +
            '<span class="cc-search__name">' + escapeHtml(hit.name) + '</span>' +
          '</span>' +
          '<span class="cc-search__meta">' + (price || subtitle(hit)) + '</span>' +
          '</a>'
        );
      })
      .join('');
    panel.hidden = false;
  }

  function search(query) {
    if (controller) controller.abort();
    controller = new AbortController();

    fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(query), {
      signal: controller.signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        // A slow request that resolves after the user kept typing must not
        // overwrite results for the newer query.
        if (data.query !== lastQuery) return;
        render(data.hits || [], query);
      })
      .catch(function (error) {
        if (error.name !== 'AbortError') hide();
      });
  }

  input.addEventListener('input', function () {
    var query = input.value.trim();
    lastQuery = query;
    clearTimeout(timer);

    if (query.length < 2) {
      hide();
      return;
    }
    timer = setTimeout(function () { search(query); }, 150);
  });

  input.addEventListener('focus', function () {
    if (panel.innerHTML && input.value.trim().length >= 2) panel.hidden = false;
  });

  document.addEventListener('click', function (event) {
    if (!wrapper.contains(event.target)) hide();
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') hide();
  });
})();
