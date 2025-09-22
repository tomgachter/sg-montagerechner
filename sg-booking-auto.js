(function () {
  if (typeof window === 'undefined') {
    return;
  }

  var config = window.SG_BOOKING_PREFILL_CONFIG || null;
  if (!config || !config.rest || !config.rest.prefill) {
    return;
  }

  var state = {
    config: config,
    prefill: null
  };

  var guardStylesInjected = false;

  fetchPrefill(config)
    .then(function (prefill) {
      state.prefill = prefill || {};
      emitPrefillEvent({ config: config, prefill: state.prefill });
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
          init(state);
        });
      } else {
        init(state);
      }
    })
    .catch(function (error) {
      if (window.console && console.warn) {
        console.warn('SG Booking Prefill fetch failed', error);
      }
    });

  function fetchPrefill(cfg) {
    var url = cfg && cfg.rest ? cfg.rest.prefill : '';
    if (!url || !cfg || !cfg.order || !cfg.sig) {
      return Promise.reject(new Error('Missing prefill parameters'));
    }
    try {
      url = String(url);
    } catch (err) {
      return Promise.reject(err);
    }
    var body = {
      order: cfg.order,
      sig: cfg.sig,
      region: cfg.region || '',
      sgm: cfg.params && typeof cfg.params.sgm !== 'undefined' ? cfg.params.sgm : (cfg.counts ? cfg.counts.m : 0),
      sge: cfg.params && typeof cfg.params.sge !== 'undefined' ? cfg.params.sge : (cfg.counts ? cfg.counts.e : 0),
      router: cfg.router || null,
      selectors: Array.isArray(cfg.selectors)
        ? cfg.selectors.map(function (meta) {
            return meta && (meta.mode || meta.key) ? String(meta.mode || meta.key) : '';
          })
        : []
    };
    return window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body)
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('Prefill request failed (' + response.status + ')');
      }
      return response.json();
    });
  }

  function init(state) {
    var selectors = Array.isArray(state.config.selectors) ? state.config.selectors : [];
    selectors.forEach(function (meta) {
      var mode = meta && (meta.mode || meta.key) ? String(meta.mode || meta.key) : '';
      if (!mode) {
        return;
      }
      var values = buildParamValues(state, mode);
      if (!values) {
        return;
      }
      var container = findSelectorContainer(meta.key || mode);
      if (!container) {
        return;
      }
      applyToContainer(container, values);
      observeContainer(container, values);
      setupTeamTabs(container, meta);
      setupRegionDayGuard(container, state, meta);
    });
  }

  function emitPrefillEvent(detail) {
    try {
      var event = new CustomEvent('sgmr:prefillReady', { detail: detail });
      window.dispatchEvent(event);
    } catch (err) {
      if (window.console && console.warn) {
        console.warn('SG Booking Prefill event dispatch failed', err);
      }
    }
  }

  function buildParamValues(state, mode) {
    var params = fallbackParamValues(state, mode);

    if (state && state.prefill && typeof state.prefill === 'object') {
      var mapping = state.prefill.mapping || {};
      if (mapping && typeof mapping === 'object') {
        var map = mapping[mode];
        if (map && typeof map === 'object') {
          Object.keys(map).forEach(function (sourceKey) {
            var targetParam = map[sourceKey];
            if (!targetParam) {
              return;
            }
            var value = resolveFieldValue(state.prefill.fields, sourceKey);
            if (value === '') {
              return;
            }
            params[targetParam] = value;
          });
        }
      }
    }

    return Object.keys(params).length ? params : null;
  }

  function fallbackParamValues(state, mode) {
    var params = {};
    var config = state && state.config && typeof state.config === 'object' ? state.config : {};
    var prefill = state && state.prefill && typeof state.prefill === 'object' ? state.prefill : {};
    var fields = prefill.fields && typeof prefill.fields === 'object' ? prefill.fields : {};
    var stable = fields.stable && typeof fields.stable === 'object' ? fields.stable : {};
    var routing = prefill.routing && typeof prefill.routing === 'object' ? prefill.routing : {};
    var routerConfig = config.router && typeof config.router === 'object' ? config.router : {};
    var routerMeta = prefill.router_meta && typeof prefill.router_meta === 'object' ? prefill.router_meta : {};
    var token = prefill.token && typeof prefill.token === 'object' ? prefill.token : {};
    var countsConfig = config.counts && typeof config.counts === 'object' ? config.counts : {};
    var paramsConfig = config.params && typeof config.params === 'object' ? config.params : {};
    var countsPrefill = prefill.counts && typeof prefill.counts === 'object' ? prefill.counts : {};
    var serviceInfo = prefill.service && typeof prefill.service === 'object' ? prefill.service : {};

    assignParam(params, ['order_id', 'order'], firstNonEmpty([
      config.order,
      prefill.order_id,
      prefill.order && prefill.order.id,
      stable.sg_order_id
    ]));

    assignParam(params, 'order_number', firstNonEmpty([
      prefill.order_number,
      stable.sg_order_number
    ]));

    var signature = firstNonEmpty([
      config.sig,
      token.sig,
      stable.sg_token_sig
    ]);
    if (signature) {
      assignParam(params, ['sig', 'signature', 'token', 'token_sig', 'sg_token_sig'], signature);
    }

    assignParam(params, 'sg_token_ts', firstNonEmpty([
      token.ts,
      stable.sg_token_ts
    ]));

    assignParam(params, 'sg_token_hash', firstNonEmpty([
      token.hash,
      stable.sg_token_hash
    ]));

    var regionValue = firstNonEmpty([
      config.region,
      routing.region_key,
      stable.sg_region_key
    ]);
    if (regionValue) {
      assignParam(params, ['region', 'region_key'], regionValue);
    }

    assignParam(params, 'region_label', firstNonEmpty([
      routing.region_label,
      stable.sg_region_label
    ]));

    var montageCount = firstNonEmpty([
      paramsConfig.sgm,
      countsConfig.m,
      countsPrefill.m,
      serviceInfo.montage_count,
      stable.sg_service_montage,
      stable.sg_service_m
    ]);
    if (montageCount) {
      assignParam(params, ['sgm', 'm', 'sg_service_m', 'sg_service_montage'], montageCount);
    }

    var etageCount = firstNonEmpty([
      paramsConfig.sge,
      countsConfig.e,
      countsPrefill.e,
      serviceInfo.etage_count,
      stable.sg_service_etage,
      stable.sg_service_e
    ]);
    if (etageCount) {
      assignParam(params, ['sge', 'e', 'sg_service_e', 'sg_service_etage'], etageCount);
    }

    var durationValue = firstNonEmpty([
      prefill.duration_minutes,
      serviceInfo.onsite_duration_minutes,
      stable.sg_service_minutes,
      routerConfig.duration_minutes,
      routerMeta.duration_minutes
    ]);
    if (durationValue) {
      assignParam(params, ['duration_minutes', 'sg_service_minutes'], durationValue);
    }

    var eventId = firstNonEmpty([
      routerMeta.event_id,
      routerConfig.event_id,
      routing.event_id,
      stable.sg_event_id
    ]);
    if (eventId) {
      assignParam(params, ['event_id', 'calendar_event_id'], eventId);
    }

    var calendarId = firstNonEmpty([
      routerMeta.calendar_id,
      routerConfig.calendar_id,
      routing.calendar_id,
      stable.sg_calendar_id
    ]);
    if (calendarId) {
      assignParam(params, 'calendar_id', calendarId);
    }

    var teamId = firstNonEmpty([
      routerMeta.team,
      routerMeta.team_id,
      routerConfig.team,
      routerConfig.team_id,
      routing.team_id,
      stable.sg_team_id
    ]);
    if (teamId) {
      assignParam(params, ['team', 'team_id'], teamId);
    }

    assignParam(params, 'team_label', firstNonEmpty([
      routerMeta.team_label,
      routerConfig.team_label,
      routing.team_label,
      stable.sg_team_label
    ]));

    var strategyValue = firstNonEmpty([
      routerMeta.strategy,
      routerConfig.strategy,
      routing.strategy,
      stable.sg_router_strategy
    ]);
    if (strategyValue) {
      assignParam(params, ['strategy', 'router_strategy', 'sg_router_strategy'], strategyValue);
    }

    var driveMinutes = firstNonEmpty([
      routerMeta.drive_minutes,
      routerConfig.drive_minutes,
      routing.drive_minutes,
      stable.sg_router_drive_minutes
    ]);
    if (driveMinutes) {
      assignParam(params, ['drive_minutes', 'sg_router_drive_minutes'], driveMinutes);
    }

    if (mode) {
      assignParam(params, ['mode', 'service'], mode);
    }

    return params;
  }

  function assignParam(target, keys, value) {
    if (!target) {
      return;
    }
    var normalized = normalize(value);
    if (normalized === '') {
      return;
    }
    if (!Array.isArray(keys)) {
      keys = [keys];
    }
    keys.forEach(function (key) {
      if (!key) {
        return;
      }
      target[key] = normalized;
    });
  }

  function firstNonEmpty(values) {
    if (!Array.isArray(values)) {
      return '';
    }
    for (var i = 0; i < values.length; i++) {
      var normalized = normalize(values[i]);
      if (normalized !== '') {
        return normalized;
      }
    }
    return '';
  }

  var fieldAliases = {
    phone: ['phone_delivery', 'phone_billing', 'phone_shipping', 'sg_phone'],
    phone_delivery: ['phone', 'phone_billing', 'phone_shipping', 'sg_phone'],
    phone_billing: ['phone', 'phone_delivery', 'sg_phone'],
    phone_shipping: ['phone', 'phone_delivery', 'sg_phone'],
    address: ['address_line1', 'sg_delivery_address'],
    address_line1: ['sg_delivery_address'],
    items_multiline: ['items', 'sg_items_text'],
    items: ['items_multiline', 'sg_items_text'],
    postcode: ['region_postcode', 'sg_delivery_postcode'],
    region_label: ['region', 'sg_region_label'],
    region: ['region_label', 'sg_region_key'],
    email: ['sg_email'],
    name: ['sg_full_name', 'first_name'],
    order_number: ['sg_order_number'],
    token_sig: ['sg_token_sig'],
    token_ts: ['sg_token_ts'],
    token_hash: ['sg_token_hash'],
    sg_items_text: ['items', 'items_multiline']
  };

  function resolveFieldValue(fieldsContainer, key) {
    var pools = [];
    if (fieldsContainer && typeof fieldsContainer === 'object') {
      if (fieldsContainer.stable && typeof fieldsContainer.stable === 'object') {
        pools.push(fieldsContainer.stable);
      }
      if (fieldsContainer.legacy && typeof fieldsContainer.legacy === 'object') {
        pools.push(fieldsContainer.legacy);
      }
      pools.push(fieldsContainer);
    }
    var value = pickFromPools(pools, key);
    if (value !== '') {
      return value;
    }
    var aliases = fieldAliases[key] || [];
    for (var i = 0; i < aliases.length; i++) {
      value = pickFromPools(pools, aliases[i]);
      if (value !== '') {
        return value;
      }
    }
    if (key === 'name') {
      var first = pickFromPools(pools, 'first_name');
      var last = pickFromPools(pools, 'last_name');
      var combined = (first + ' ' + last).trim();
      if (combined !== '') {
        return combined;
      }
    }
    if (key === 'items_multiline') {
      var compact = pickFromPools(pools, 'items');
      if (compact !== '') {
        return compact.split(/\s*,\s*/).join('\n');
      }
    }
    if (key === 'items') {
      var multi = pickFromPools(pools, 'items_multiline');
      if (multi !== '') {
        return multi.replace(/\s*\n\s*/g, ', ');
      }
    }
    return '';
  }

  function pickFromPools(pools, key) {
    for (var i = 0; i < pools.length; i++) {
      var pool = pools[i];
      if (!pool || typeof pool !== 'object') {
        continue;
      }
      if (!Object.prototype.hasOwnProperty.call(pool, key)) {
        continue;
      }
      var value = normalize(pool[key]);
      if (value !== '') {
        return value;
      }
    }
    return '';
  }

  function findSelectorContainer(key) {
    if (!key) {
      return null;
    }
    var selector = '.sg-booking-auto-selector[data-selector-key="' + cssEscape(String(key)) + '"]';
    return document.querySelector(selector);
  }

  function cssEscape(value) {
    if (typeof value !== 'string') {
      return '';
    }
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return value.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function normalize(value) {
    if (typeof value === 'undefined' || value === null) {
      return '';
    }
    var str = String(value);
    return str.trim();
  }

  function applyToContainer(container, values) {
    applyToElements(container.querySelectorAll('iframe, a[href]'), values);
    window.setTimeout(function () {
      applyToElements(container.querySelectorAll('iframe, a[href]'), values);
    }, 400);
  }

  function applyToElements(nodeList, values) {
    if (!nodeList) {
      return;
    }
    Array.prototype.forEach.call(nodeList, function (element) {
      applyToElement(element, values);
    });
  }

  function observeContainer(container, values) {
    if (typeof MutationObserver === 'undefined') {
      return;
    }
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
          if (!node || node.nodeType !== 1) {
            return;
          }
          if (node.matches && node.matches('iframe, a[href]')) {
            applyToElement(node, values);
          }
          if (node.querySelectorAll) {
            applyToElements(node.querySelectorAll('iframe, a[href]'), values);
          }
        });
      });
    });
    observer.observe(container, { childList: true, subtree: true });
  }

  function applyToElement(element, values) {
    if (!element) {
      return;
    }
    if (element.tagName === 'IFRAME') {
      applyToIframe(element, values);
      return;
    }
    if (element.tagName === 'A') {
      applyToLink(element, values);
    }
  }

  function applyToIframe(iframe, values) {
    try {
      var src = iframe.getAttribute('src') || '';
      if (!src) {
        return;
      }
      iframe.setAttribute('src', appendParams(src, values));
    } catch (err) {
      if (window.console && console.warn) {
        console.warn('SG Booking Prefill iframe update failed', err);
      }
    }
  }

  function applyToLink(link, values) {
    try {
      var href = link.getAttribute('href') || '';
      if (!href) {
        return;
      }
      link.setAttribute('href', appendParams(href, values));
    } catch (err) {
      if (window.console && console.warn) {
        console.warn('SG Booking Prefill link update failed', err);
      }
    }
  }

  function appendParams(url, params) {
    if (!params || typeof params !== 'object') {
      return url;
    }
    var hash = '';
    var hashIndex = url.indexOf('#');
    if (hashIndex !== -1) {
      hash = url.slice(hashIndex);
      url = url.slice(0, hashIndex);
    }
    var hasQuery = urlHasQuery(url);
    var query = Object.keys(params)
      .map(function (key) {
        return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
      })
      .join('&');
    if (!query) {
      return url + hash;
    }
    return url + (hasQuery ? '&' : '?') + query + hash;
  }

  function urlHasQuery(url) {
    return url.indexOf('?') !== -1;
  }

  function setupRegionDayGuard(container, state, meta) {
    if (!container || !state || !state.config) {
      return;
    }
    var guard = state.config.region_days || null;
    if (!hasRegionDayRestrictions(guard)) {
      return;
    }
    if (container.dataset && container.dataset.sgmrGuardInitialized === '1') {
      return;
    }
    if (container.dataset) {
      container.dataset.sgmrGuardInitialized = '1';
    }

    ensureGuardStyles();

    var allowed = Array.isArray(guard.allowed_days)
      ? guard.allowed_days.map(function (value) {
          return parseInt(value, 10);
        }).filter(function (value) {
          return value >= 1 && value <= 7;
        })
      : [];
    if (!allowed.length) {
      allowed = [1, 2, 3, 4, 5, 6, 7];
    }

    var guardConfig = {
      allowed_days: allowed,
      message: guard.message || '',
      labels: Array.isArray(guard.labels) ? guard.labels : [],
      meta: meta || {}
    };

    markRegionDayNodes(container, guardConfig);
    observeRegionDayNodes(container, guardConfig);
    bindRegionDayInteractions(container, guardConfig);
  }

  function hasRegionDayRestrictions(guard) {
    if (!guard || !Array.isArray(guard.allowed_days)) {
      return false;
    }
    var unique = guard.allowed_days.filter(function (value, index, array) {
      var parsed = parseInt(value, 10);
      return array.indexOf(value) === index && parsed >= 1 && parsed <= 7;
    });
    return unique.length > 0 && unique.length < 7;
  }

  function ensureGuardStyles() {
    if (guardStylesInjected) {
      return;
    }
    guardStylesInjected = true;
    try {
      var style = document.createElement('style');
      style.type = 'text/css';
      style.textContent = '' +
        '.sg-booking-auto .sgmr-day-disabled { opacity: 0.45; cursor: not-allowed; }' +
        '.sg-booking-auto .sgmr-region-day-notice { display: none; margin: 0 0 12px; padding: 10px 12px; border-left: 4px solid #f0ad4e; background: #fff8e5; font-size: 0.9em; line-height: 1.4; }' +
        '.sg-booking-auto .sgmr-region-day-notice.is-visible { display: block; }';
      document.head.appendChild(style);
    } catch (err) {
      if (window.console && console.warn) {
        console.warn('SG Booking guard styles failed', err);
      }
    }
  }

  function markRegionDayNodes(root, guard) {
    if (!root || !guard) {
      return;
    }
    var candidates = [];
    if (root.nodeType === 1) {
      candidates.push(root);
      var query = root.querySelectorAll('[data-date],[data-date-value],[data-day],[data-date_iso],[data-dateiso]');
      candidates = candidates.concat(Array.prototype.slice.call(query));
    }
    candidates.forEach(function (node) {
      decorateDateNode(node, guard);
    });
  }

  function observeRegionDayNodes(container, guard) {
    if (typeof MutationObserver === 'undefined') {
      return;
    }
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
          if (!node || node.nodeType !== 1) {
            return;
          }
          decorateDateNode(node, guard);
          markRegionDayNodes(node, guard);
        });
      });
    });
    observer.observe(container, { childList: true, subtree: true });
  }

  function decorateDateNode(node, guard) {
    if (!node || node.nodeType !== 1) {
      return;
    }
    var info = extractDateInfo(node);
    if (!info) {
      return;
    }
    var allowed = guard.allowed_days.indexOf(info.isoDow) !== -1;
    if (!allowed) {
      node.classList.add('sgmr-day-disabled');
      node.setAttribute('aria-disabled', 'true');
      if (node.dataset) {
        node.dataset.sgmrAllowed = '0';
      }
      if (!node.getAttribute('tabindex')) {
        node.setAttribute('tabindex', '-1');
      }
    } else {
      node.classList.remove('sgmr-day-disabled');
      if (node.dataset) {
        delete node.dataset.sgmrAllowed;
      }
      if (node.getAttribute('aria-disabled') === 'true') {
        node.removeAttribute('aria-disabled');
      }
    }
  }

  function bindRegionDayInteractions(container, guard) {
    var handler = function (event) {
      if (!event || !event.target) {
        return;
      }
      if (event.target.closest && event.target.closest('.sgmr-region-day-notice')) {
        return;
      }
      var info = findDateInfoUp(event.target, container);
      if (!info) {
        return;
      }
      if (guard.allowed_days.indexOf(info.isoDow) !== -1) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      showRegionDayNotice(container, guard, info.date);
    };

    container.addEventListener('click', handler, true);
    container.addEventListener('keydown', function (event) {
      if (!event || !event.target) {
        return;
      }
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      var info = findDateInfoUp(event.target, container);
      if (!info) {
        return;
      }
      if (guard.allowed_days.indexOf(info.isoDow) !== -1) {
        return;
      }
      event.preventDefault();
      showRegionDayNotice(container, guard, info.date);
    });
  }

  function findDateInfoUp(node, limit) {
    var current = node;
    while (current && current !== limit) {
      var info = extractDateInfo(current);
      if (info) {
        return info;
      }
      current = current.parentElement;
    }
    return null;
  }

  function extractDateInfo(node) {
    if (!node || node.nodeType !== 1) {
      return null;
    }
    var raw = getNodeDateString(node);
    if (!raw) {
      return null;
    }
    var date = parseDateString(raw);
    if (!date) {
      return null;
    }
    var day = date.getUTCDay ? date.getUTCDay() : date.getDay();
    var isoDow = day === 0 ? 7 : day;
    return {
      raw: raw,
      date: date,
      isoDow: isoDow
    };
  }

  function getNodeDateString(node) {
    if (!node || node.nodeType !== 1) {
      return '';
    }
    if (node.dataset) {
      if (node.dataset.date) {
        return String(node.dataset.date);
      }
      if (node.dataset.dateValue) {
        return String(node.dataset.dateValue);
      }
      if (node.dataset.day) {
        return String(node.dataset.day);
      }
      if (node.dataset.dateIso) {
        return String(node.dataset.dateIso);
      }
    }
    var attrs = ['data-date', 'data-date-value', 'data-day', 'data-date_iso', 'data-dateiso'];
    for (var i = 0; i < attrs.length; i++) {
      var attr = node.getAttribute(attrs[i]);
      if (attr) {
        return String(attr);
      }
    }
    return '';
  }

  function parseDateString(value) {
    if (!value) {
      return null;
    }
    var trimmed = String(value).trim();
    if (!trimmed) {
      return null;
    }
    var isoMatch = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (isoMatch) {
      var isoDate = isoMatch[1] + '-' + isoMatch[2] + '-' + isoMatch[3] + 'T00:00:00Z';
      var parsedIso = new Date(isoDate);
      if (!isNaN(parsedIso.getTime())) {
        return parsedIso;
      }
    }
    var euMatch = trimmed.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
    if (euMatch) {
      var euIso = euMatch[3] + '-' + euMatch[2] + '-' + euMatch[1] + 'T00:00:00Z';
      var parsedEu = new Date(euIso);
      if (!isNaN(parsedEu.getTime())) {
        return parsedEu;
      }
    }
    var fallback = new Date(trimmed);
    if (!isNaN(fallback.getTime())) {
      return fallback;
    }
    return null;
  }

  function showRegionDayNotice(container, guard, clickedDate) {
    var parts = [];
    if (guard.message) {
      parts.push(String(guard.message));
    } else if (guard.labels && guard.labels.length && guard.labels.length < 7) {
      parts.push('Nur ' + guard.labels.join(', ') + ' buchbar.');
    }
    var suggestion = null;
    if (clickedDate instanceof Date && !isNaN(clickedDate.getTime())) {
      suggestion = findNextAllowed(clickedDate, guard.allowed_days);
    }
    if (suggestion) {
      parts.push(formatSuggestedDate(suggestion));
      jumpToDate(container, suggestion);
    }
    if (!parts.length) {
      return;
    }
    var notice = container.querySelector('.sgmr-region-day-notice');
    if (!notice) {
      notice = createRegionDayNotice();
      container.insertBefore(notice, container.firstChild);
    }
    notice.textContent = parts.join(' ');
    notice.classList.add('is-visible');
    window.clearTimeout(notice._sgmrTimer || 0);
    notice._sgmrTimer = window.setTimeout(function () {
      notice.classList.remove('is-visible');
    }, 8000);
  }

  function createRegionDayNotice() {
    var element = document.createElement('div');
    element.className = 'sgmr-region-day-notice';
    element.setAttribute('role', 'alert');
    return element;
  }

  function findNextAllowed(date, allowedDays) {
    if (!(date instanceof Date) || !Array.isArray(allowedDays)) {
      return null;
    }
    var base = new Date(date.getTime());
    for (var i = 0; i < 28; i++) {
      base.setUTCDate(base.getUTCDate() + 1);
      var day = base.getUTCDay();
      var isoDow = day === 0 ? 7 : day;
      if (allowedDays.indexOf(isoDow) !== -1) {
        return new Date(base.getTime());
      }
    }
    return null;
  }

  function formatSuggestedDate(date) {
    if (!(date instanceof Date)) {
      return '';
    }
    try {
      return 'Nächster verfügbarer Tag: ' + date.toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      });
    } catch (err) {
      var year = date.getUTCFullYear();
      var month = ('0' + (date.getUTCMonth() + 1)).slice(-2);
      var day = ('0' + date.getUTCDate()).slice(-2);
      return 'Nächster verfügbarer Tag: ' + year + '-' + month + '-' + day;
    }
  }

  function jumpToDate(container, date) {
    if (!(date instanceof Date)) {
      return;
    }
    var iso = formatDateIso(date);
    if (!iso) {
      return;
    }
    var selector = '[data-date="' + iso + '"], [data-date-value="' + iso + '"], [data-date_iso="' + iso + '"], [data-dateiso="' + iso + '"]';
    var target = container.querySelector(selector);
    if (!target) {
      return;
    }
    window.setTimeout(function () {
      if (typeof target.click === 'function') {
        target.click();
      }
      if (typeof target.focus === 'function') {
        try {
          target.focus({ preventScroll: false });
        } catch (err) {
          target.focus();
        }
      }
    }, 150);
  }

  function formatDateIso(date) {
    if (!(date instanceof Date) || isNaN(date.getTime())) {
      return '';
    }
    var year = date.getUTCFullYear();
    var month = ('0' + (date.getUTCMonth() + 1)).slice(-2);
    var day = ('0' + date.getUTCDate()).slice(-2);
    return year + '-' + month + '-' + day;
  }

  function setupTeamTabs(container, meta) {
    if (!container || !container.closest) {
      return;
    }
    var root = container.closest('.sg-booking-auto');
    if (!root || root.dataset.renderMode !== 'tabs') {
      return;
    }
    if (root.dataset.sgmrTabsInit === '1') {
      return;
    }

    var nav = root.querySelector('.sg-booking-auto-tabs-nav');
    if (!nav) {
      return;
    }

    root.dataset.sgmrTabsInit = '1';
    var tabs = Array.prototype.slice.call(nav.querySelectorAll('.sg-booking-auto-tab'));
    if (!tabs.length) {
      return;
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateTeamTab(root, tab.getAttribute('data-selector-key'));
      });
      tab.addEventListener('keydown', function (event) {
        if (!event) {
          return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          activateTeamTab(root, tab.getAttribute('data-selector-key'));
        }
      });
    });
  }

  function activateTeamTab(root, key) {
    if (!root || !key) {
      return;
    }
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.sg-booking-auto-tab'));
    var panels = Array.prototype.slice.call(root.querySelectorAll('.sg-booking-auto-selector[data-selector-key]'));

    tabs.forEach(function (tab) {
      var isActive = tab.getAttribute('data-selector-key') === key;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      tab.setAttribute('tabindex', isActive ? '0' : '-1');
    });

    panels.forEach(function (panel) {
      var isActive = panel.getAttribute('data-selector-key') === key;
      panel.classList.toggle('is-active', isActive);
      panel.classList.toggle('is-hidden', !isActive);
      if (isActive) {
        panel.setAttribute('aria-hidden', 'false');
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('aria-hidden', 'true');
        panel.setAttribute('hidden', 'hidden');
      }
    });
  }
})();
