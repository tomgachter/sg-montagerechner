(function () {
    if (typeof window === 'undefined' || !window.document) {
        return;
    }

    var global = window;
    var config = global.sgmrFbpAugment || {};
    var rangeSeparator = typeof config.rangeSeparator === 'string' ? config.rangeSeparator : ' – ';
    var durationPrefix = typeof config.durationLabelPrefix === 'string' ? config.durationLabelPrefix : 'Dauer';
    var maxAttempts = typeof config.maxAttempts === 'number' ? config.maxAttempts : 25;
    var retryDelay = typeof config.retryDelay === 'number' ? config.retryDelay : 300;
    var slotSelectors = Array.isArray(config.slotSelectors) && config.slotSelectors.length ? config.slotSelectors : [
        '[data-slot-index]',
        '[data-slot]',
        '[data-value]',
        '.fluent-booking-slot',
        '.fb-time-slot',
        '.fluent-slot-button',
        '.fluent-booking__slot',
        '.fluent-booking__slot button',
        'button[data-type="time-slot"]',
        '.fcal_time_slot__btn',
        '.fcal_time_slots button',
        '.fcal_time_slots .time_slot_btn',
        '.fcal_spot_name',
        '.fcal_spot_name div',
        '.fcal_slot_btn',
        '.fluent-booking-step-time button'
    ];
    var summarySelectors = Array.isArray(config.summarySelectors) && config.summarySelectors.length ? config.summarySelectors : [
        '[data-selected-slot]',
        '[data-summary-time]',
        '.fluent-booking__selected-time',
        '.fb-selected-slot',
        '.fb-selected-time',
        '.fcal_selected_time',
        '.fcal_slot_time',
        '.fcal_confirm_section p',
        '.fcal_selected_slot',
        '.fcal_confirmation .fcal_confirm_section_content',
        '.slot_time_range span',
        '.slot_time_range.fcal_icon_item span',
        '.slot_time_range'
    ];
    var hostSelectors = Array.isArray(config.hostSelectors) && config.hostSelectors.length ? config.hostSelectors : [
        '.fluent-booking-app',
        '.fluent__booking__wrapper',
        '.fluent-booking',
        '[data-component="fluent_booking_form"]'
    ];

    var currentContextKey = null;
    var slotLookup = [];
    var durationLabel = '';
    var bootstrapAttempts = 0;
    var observer = null;
    var durationMinutes = 0;
    var MAX_END_MINUTES = 18 * 60;
    var configDuration = parseInt(config.durationMinutes, 10);
    if (!isNaN(configDuration) && configDuration > 0) {
        durationMinutes = configDuration;
    }

    function readCookie(name) {
        if (typeof document === 'undefined' || !document.cookie) {
            return '';
        }
        var prefix = name + '=';
        var parts = document.cookie.split(';');
        for (var i = 0; i < parts.length; i++) {
            var cookie = parts[i].trim();
            if (cookie.indexOf(prefix) === 0) {
                return cookie.substring(prefix.length);
            }
        }
        return '';
    }

    function writeContextCookie(eventId, context) {
        if (!eventId || !context) {
            return;
        }
        var payload = {};
        try {
            var existing = readCookie('sgmr_booking_ctx');
            if (existing) {
                payload = JSON.parse(decodeURIComponent(existing)) || {};
            }
        } catch (error) {
            payload = {};
        }

        payload[eventId] = {
            order_id: context.order_id || 0,
            signature: context.signature || '',
            duration_minutes: context.duration_minutes || 0,
            sgm: context.sgm || 0,
            sge: context.sge || 0,
            region: context.region || '',
            event_id: eventId
        };

        try {
            var serialized = encodeURIComponent(JSON.stringify(payload));
            var maxAge = ';max-age=' + (60 * 60);
            var secure = window.location && window.location.protocol === 'https:' ? ';Secure' : '';
            document.cookie = 'sgmr_booking_ctx=' + serialized + ';path=/' + maxAge + ';SameSite=Lax' + secure;
        } catch (error) {
            // swallow cookie errors (Safari ITP, etc.)
        }
    }

    function cookieContextEntry() {
        var raw = readCookie('sgmr_booking_ctx');
        if (!raw) {
            return null;
        }
        try {
            var decoded = JSON.parse(decodeURIComponent(raw));
            var keys = Object.keys(decoded || {});
            if (!keys.length) {
                return null;
            }
            var firstKey = keys[0];
            var entry = decoded[firstKey];
            if (!entry || typeof entry !== 'object') {
                return null;
            }
            if (!entry.event_id && firstKey) {
                entry.event_id = parseInt(firstKey, 10) || 0;
            }
            return entry;
        } catch (error) {
            return null;
        }
    }

    function contextFromCookie() {
        var entry = cookieContextEntry();
        if (!entry) {
            return null;
        }
        var duration = parseInt(entry.duration_minutes, 10);
        if (isNaN(duration) || duration <= 0) {
            duration = durationMinutes;
        }
        return {
            sgmr: {
                order_id: entry.order_id || 0,
                signature: entry.signature || '',
                duration_minutes: duration,
                duration_label: formatDurationLabel(duration),
                slot_lookup: [],
                timezone: entry.timezone || '',
                sgm: entry.sgm || 0,
                sge: entry.sge || 0,
                region: entry.region || '',
                event_id: entry.event_id || 0
            },
            slot: {
                duration_minutes: duration
            },
            event: {
                id: entry.event_id || 0,
                duration_minutes: duration
            }
        };
    }

    function formatDurationLabel(minutes) {
        var mins = parseInt(minutes, 10);
        if (isNaN(mins) || mins <= 0) {
            return '';
        }
        var hours = Math.floor(mins / 60);
        var remaining = mins % 60;
        var parts = [];
        if (hours > 0) {
            parts.push(hours + ' Std.');
        }
        if (remaining > 0 || parts.length === 0) {
            parts.push(remaining + ' Min.');
        }
        return parts.join(' ');
    }

    function timeToMinutes(label) {
        var match = label && label.match(/^(\d{1,2}):(\d{2})/);
        if (!match) {
            return null;
        }
        var hours = parseInt(match[1], 10);
        var minutes = parseInt(match[2], 10);
        if (isNaN(hours) || isNaN(minutes)) {
            return null;
        }
        return hours * 60 + minutes;
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function computeEndLabel(startLabel) {
        var startMinutes = timeToMinutes(startLabel);
        if (startMinutes === null) {
            return '';
        }
        var duration = durationMinutes > 0 ? durationMinutes : 30;
        var endMinutes = Math.min(startMinutes + duration, MAX_END_MINUTES);
        if (endMinutes <= startMinutes) {
            endMinutes = Math.min(startMinutes + duration, MAX_END_MINUTES);
        }
        var endHours = Math.floor(endMinutes / 60) % 24;
        var endRemainder = endMinutes % 60;
        return pad(endHours) + ':' + pad(endRemainder);
    }

    function applyManualLabels() {
        if (!durationMinutes) {
            return;
        }

        var spotNodes = [];
        try {
            spotNodes = document.querySelectorAll('.fcal_spot');
        } catch (error) {
            spotNodes = [];
        }

        var sorted = [];

        for (var i = 0; i < spotNodes.length; i++) {
            var spot = spotNodes[i];
            if (!spot) {
                continue;
            }

            var labelNode = spot.querySelector('.fcal_spot_name div, .fcal_spot_name, .fcal_spot_time, [data-slot-label]');
            if (!labelNode) {
                continue;
            }

            var rawStart = labelNode.getAttribute('data-sgmr-start');
            if (!rawStart) {
                rawStart = labelNode.textContent ? labelNode.textContent.trim() : '';
            }
            var match = rawStart.match(/(\d{1,2}:\d{2})/);
            var startLabel = match ? match[1] : null;
            if (!startLabel) {
                var details = extractSlotDetails(spot, rawStart);
                if (details && details.time) {
                    startLabel = details.time;
                }
            }
            if (!startLabel) {
                continue;
            }

            var startMinutes = timeToMinutes(startLabel);
            if (startMinutes === null) {
                continue;
            }

            var endLabel = computeEndLabel(startLabel);
            if (!endLabel) {
                continue;
            }

            var finalLabel = startLabel + rangeSeparator + endLabel;
            if (labelNode.textContent.trim() !== finalLabel) {
                labelNode.textContent = finalLabel;
            }
            labelNode.setAttribute('data-sgmr-start', startLabel);
            labelNode.setAttribute('data-sgmr-end', endLabel);
            labelNode.setAttribute('aria-label', finalLabel);

            spot.setAttribute('data-sgmr-start', startLabel);
            spot.setAttribute('data-sgmr-end', endLabel);
            spot.setAttribute('data-sgmr-start-minutes', String(startMinutes));

            var confirmBtn = spot.querySelector('button, .fcal_spot_confirm');
            if (confirmBtn) {
                confirmBtn.setAttribute('data-sgmr-start', startLabel);
                confirmBtn.setAttribute('data-sgmr-end', endLabel);
            }

            sorted.push({
                spot: spot,
                startMinutes: startMinutes
            });
        }

        if (sorted.length > 1) {
            sorted.sort(function (a, b) {
                return a.startMinutes - b.startMinutes;
            });
            var parent = sorted[0].spot.parentNode;
            if (parent) {
                for (var j = 0; j < sorted.length; j++) {
                    parent.appendChild(sorted[j].spot);
                }
            }
        }

        for (var k = 0; k < summarySelectors.length; k++) {
            var summaries;
            try {
                summaries = document.querySelectorAll(summarySelectors[k]);
            } catch (error) {
                summaries = [];
            }
            if (!summaries || !summaries.length) {
                continue;
            }
            for (var m = 0; m < summaries.length; m++) {
                var node = summaries[m];
                if (!node || !node.textContent) {
                    continue;
                }
                var text = node.textContent.trim();
                var start = text.match(/(\d{1,2}:\d{2})/);
                if (!start) {
                    continue;
                }
                var end = computeEndLabel(start[1]);
                if (!end) {
                    continue;
                }
                var datePart = '';
                var commaIndex = text.indexOf(',');
                if (commaIndex !== -1) {
                    datePart = text.substring(commaIndex);
                }
                node.setAttribute('data-sgmr-date', datePart);
                node.setAttribute('data-sgmr-start', start[1]);
                node.setAttribute('data-sgmr-end', end);
                var updated = start[1] + rangeSeparator + end + datePart;
                if (node.textContent.trim() !== updated.trim()) {
                    node.textContent = updated;
                }
            }
        }
    }

    function updateSummaryFromSelection() {
        var selected = document.querySelector('.fcal_spot_selected .fcal_spot_name div, .fcal_spot_selected .fcal_spot_name');
        if (!selected) {
            return;
        }
        var start = selected.getAttribute('data-sgmr-start');
        var end = selected.getAttribute('data-sgmr-end');
        var text = selected.textContent ? selected.textContent.trim() : '';
        if ((!start || !end) && text.indexOf(rangeSeparator) !== -1) {
            var parts = text.split(rangeSeparator);
            start = start || parts[0].trim();
            end = end || parts[1].trim();
        }
        if (!start || !end) {
            return;
        }
        for (var i = 0; i < summarySelectors.length; i++) {
        var summaries;
        try {
            summaries = document.querySelectorAll(summarySelectors[i]);
        } catch (error) {
            summaries = [];
        }
        if (!summaries || !summaries.length) {
            continue;
        }
        for (var j = 0; j < summaries.length; j++) {
            var node = summaries[j];
            if (!node) {
                continue;
            }
            var datePart = node.getAttribute('data-sgmr-date');
            if (!datePart) {
                var comma = node.textContent.indexOf(',');
                datePart = comma !== -1 ? node.textContent.substring(comma) : '';
                node.setAttribute('data-sgmr-date', datePart);
            }
            var updated = start + rangeSeparator + end + datePart;
            if (node.textContent.trim() !== updated.trim()) {
                node.textContent = updated;
            }
            node.setAttribute('data-sgmr-start', start);
            node.setAttribute('data-sgmr-end', end);
        }
    }
    }

    function contextFromGlobal() {
        var ctx = global.sgmrBookingContext;
        if (!ctx || typeof ctx !== 'object') {
            var cookieContext = contextFromCookie();
            if (cookieContext) {
                global.sgmrBookingContext = cookieContext.sgmr;
                ctx = global.sgmrBookingContext;
                return cookieContext;
            }
            return null;
        }
        var duration = parseInt(ctx.duration_minutes, 10);
        if (isNaN(duration) || duration <= 0) {
            duration = durationMinutes;
        }
        return {
            sgmr: {
                order_id: ctx.order_id || 0,
                signature: ctx.signature || '',
                duration_minutes: duration,
                duration_label: formatDurationLabel(duration),
                slot_lookup: [],
                timezone: ctx.timezone || '',
                sgm: ctx.sgm || 0,
                sge: ctx.sge || 0,
                region: ctx.region || '',
                event_id: ctx.event_id || 0
            },
            slot: {
                duration_minutes: duration
            },
            event: {
                id: ctx.event_id || 0,
                duration_minutes: duration
            }
        };
    }

    function resolveVars() {
        var candidates = [
            global.fluent_booking_public_event_vars,
            global.fluent_booking_public_event,
            global.fluent_booking_public,
            global.fluent_booking_event_vars,
            global.FluentBookingPublicVars,
            global.fluentBookingPublicVars,
            global.FluentBookingPublic,
            global.fluentBookingEventVars
        ];
        for (var i = 0; i < candidates.length; i++) {
            var candidate = candidates[i];
            if (!candidate) {
                continue;
            }
            if (candidate.data && typeof candidate.data === 'object') {
                return candidate.data;
            }
            if (candidate.vars && typeof candidate.vars === 'object') {
                return candidate.vars;
            }
            if (typeof candidate === 'object') {
                return candidate;
            }
        }
        if (global.FluentBookingPublic && typeof global.FluentBookingPublic === 'object') {
            var nested = global.FluentBookingPublic.eventVars || global.FluentBookingPublic.vars;
            if (nested && typeof nested === 'object') {
                return nested;
            }
        }
        return contextFromGlobal();
    }

    function normalizeLookup(raw) {
        var normalized = [];
        if (!raw || !raw.length) {
            return normalized;
        }
        for (var i = 0; i < raw.length; i++) {
            var item = raw[i];
            if (!item || typeof item !== 'object') {
                continue;
            }
            var start = '';
            var end = '';
            if (item.start_label) {
                start = String(item.start_label).trim();
            } else if (item.startLabel) {
                start = String(item.startLabel).trim();
            }
            if (item.end_label) {
                end = String(item.end_label).trim();
            } else if (item.endLabel) {
                end = String(item.endLabel).trim();
            }
            if (!start || !end) {
                continue;
            }
            normalized.push({
                index: typeof item.index === 'number' ? item.index : (typeof item.slot_index === 'number' ? item.slot_index : null),
                slotId: item.slot_id || item.id || null,
                start_label: start,
                end_label: end,
                start_time: item.start_time || '',
                end_time: item.end_time || ''
            });
        }
        return normalized;
    }

    function refreshLookup() {
        var vars = resolveVars();
        if (!vars || !vars.sgmr) {
            return false;
        }
        var contextKey = vars.sgmr.signature || vars.sgmr.order_id || 'default';
        var rawLookup = Array.isArray(vars.sgmr.slot_lookup) ? vars.sgmr.slot_lookup : [];
        var normalized = normalizeLookup(rawLookup);
        var updated = contextKey !== currentContextKey;
        if (!updated && normalized.length !== slotLookup.length) {
            updated = true;
        }
        if (updated) {
            slotLookup = normalized;
        }
        durationLabel = typeof vars.sgmr.duration_label === 'string' ? vars.sgmr.duration_label : durationLabel;
        var rawDuration = vars.sgmr.duration_minutes || (vars.slot && vars.slot.duration_minutes) || (vars.event && vars.event.duration_minutes) || durationMinutes;
        if (rawDuration) {
            var parsed = parseInt(rawDuration, 10);
            if (!isNaN(parsed) && parsed > 0) {
                durationMinutes = parsed;
            }
        }
        durationLabel = vars.sgmr.duration_label || durationLabel;
        if (!durationLabel) {
            durationLabel = formatDurationLabel(durationMinutes);
        }
        currentContextKey = contextKey;

        var eventId = parseInt(vars.sgmr.event_id || (vars.event && vars.event.id) || 0, 10);
        if (!isNaN(eventId) && eventId > 0) {
            writeContextCookie(eventId, vars.sgmr);
        }

        return normalized.length > 0 || durationMinutes > 0;
    }

    function findHost() {
        for (var i = 0; i < hostSelectors.length; i++) {
            try {
                var candidate = document.querySelector(hostSelectors[i]);
                if (candidate) {
                    return candidate;
                }
            } catch (error) {
                // ignore malformed selector
            }
        }
        return document.querySelector('main') || document.body;
    }

    function ensureBanner() {
        if (!durationLabel) {
            return;
        }
        var host = findHost();
        if (!host) {
            return;
        }
        var banner = document.getElementById('sgmr-booking-duration');
        var text = durationPrefix + ': ' + durationLabel;
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'sgmr-booking-duration';
            banner.className = 'sgmr-booking-duration';
            banner.setAttribute('data-sgmr-duration', '1');
            if (host.firstChild) {
                host.insertBefore(banner, host.firstChild);
            } else {
                host.appendChild(banner);
            }
        }
        if (banner.textContent !== text) {
            banner.textContent = text;
        }
    }

    function matchSlotByIndex(indexValue) {
        var index = parseInt(indexValue, 10);
        if (isNaN(index)) {
            return null;
        }
        for (var i = 0; i < slotLookup.length; i++) {
            if (slotLookup[i].index === index) {
                return slotLookup[i];
            }
        }
        return null;
    }

    function matchSlotById(idValue) {
        if (!idValue) {
            return null;
        }
        var id = String(idValue).trim();
        for (var i = 0; i < slotLookup.length; i++) {
            if (slotLookup[i].slotId && String(slotLookup[i].slotId) === id) {
                return slotLookup[i];
            }
        }
        return null;
    }

    function matchSlotByLabel(text) {
        if (!text) {
            return null;
        }
        for (var i = 0; i < slotLookup.length; i++) {
            var slot = slotLookup[i];
            if (!slot.start_label) {
                continue;
            }
            if (text.indexOf(slot.start_label) === 0) {
                return slot;
            }
            if (slot.start_time && text.indexOf(slot.start_time) === 0) {
                return slot;
            }
        }
        return null;
    }

    function applyRangeToElement(element) {
        if (!element) {
            return;
        }
        var target = element;
        if (element.children && element.children.length === 1 && element.children[0].textContent) {
            target = element.children[0];
        }
        if (!target || typeof target.textContent !== 'string') {
            return;
        }
        var originalText = target.textContent.trim();
        if (!originalText) {
            return;
        }
        if (originalText.indexOf(rangeSeparator) !== -1) {
            return;
        }
        var dataset = element.dataset || {};
        var slot = null;
        if (dataset.slotIndex) {
            slot = matchSlotByIndex(dataset.slotIndex);
        }
        if (!slot && dataset.index) {
            slot = matchSlotByIndex(dataset.index);
        }
        if (!slot && dataset.slot) {
            slot = matchSlotByIndex(dataset.slot);
        }
        if (!slot && dataset.slotId) {
            slot = matchSlotById(dataset.slotId);
        }
        if (!slot && dataset.value && dataset.value.indexOf(':') !== -1) {
            slot = matchSlotByLabel(dataset.value);
        }
        if (!slot) {
            slot = matchSlotByLabel(originalText);
        }
        if ((!slot || !slot.start_label || !slot.end_label) && durationMinutes) {
            slot = buildFallbackSlot(element, originalText);
        }
        if (!slot || !slot.start_label || !slot.end_label) {
            return;
        }
        var rangeText = slot.start_label + rangeSeparator + slot.end_label;
        var updatedText = originalText;
        if (originalText.indexOf(slot.start_label) !== -1) {
            updatedText = originalText.replace(slot.start_label, rangeText);
        } else {
            updatedText = rangeText;
        }
        if (updatedText === originalText) {
            return;
        }
        target.textContent = updatedText;
        if (element.setAttribute) {
            element.setAttribute('data-sgmr-augmented', '1');
        }
        if (element.hasAttribute && element.hasAttribute('aria-label')) {
            element.setAttribute('aria-label', updatedText);
        }
        if (slot.start_time && slot.end_time) {
            var exists = false;
            for (var i = 0; i < slotLookup.length; i++) {
                if (slotLookup[i].start_time === slot.start_time && slotLookup[i].end_time === slot.end_time) {
                    exists = true;
                    break;
                }
            }
            if (!exists) {
                slotLookup.push({
                    index: null,
                    slotId: null,
                    start_time: slot.start_time,
                    end_time: slot.end_time,
                    start_label: slot.start_label,
                    end_label: slot.end_label
                });
            }
        }
    }

    function applyRange(selectorList) {
        if (!selectorList || !selectorList.length) {
            return;
        }
        for (var i = 0; i < selectorList.length; i++) {
            var selector = selectorList[i];
            var elements;
            try {
                elements = document.querySelectorAll(selector);
            } catch (error) {
                continue;
            }
            if (!elements || !elements.length) {
                continue;
            }
            for (var j = 0; j < elements.length; j++) {
                applyRangeToElement(elements[j]);
            }
        }
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function extractSlotDetails(element, text) {
        var candidates = [];
        if (element && element.dataset) {
            for (var key in element.dataset) {
                if (Object.prototype.hasOwnProperty.call(element.dataset, key)) {
                    candidates.push(String(element.dataset[key]));
                }
            }
        }
        if (element && element.getAttribute) {
            var attributes = ['data-start', 'data-time', 'data-value', 'data-slot'];
            for (var i = 0; i < attributes.length; i++) {
                var attr = element.getAttribute(attributes[i]);
                if (attr) {
                    candidates.push(attr);
                }
            }
        }
        candidates.push(text);

        for (var j = 0; j < candidates.length; j++) {
            var candidate = candidates[j];
            if (!candidate || typeof candidate !== 'string') {
                continue;
            }
            var timeMatch = candidate.match(/(\d{1,2}:\d{2})/);
            if (!timeMatch) {
                continue;
            }
            var parts = timeMatch[1].split(':');
            var hours = parseInt(parts[0], 10);
            var minutes = parseInt(parts[1], 10);
            if (isNaN(hours) || isNaN(minutes)) {
                continue;
            }
            var dateMatch = candidate.match(/(\d{4}-\d{2}-\d{2})/);
            var isoStart = '';
            if (dateMatch) {
                isoStart = dateMatch[1] + ' ' + pad(hours) + ':' + pad(minutes) + ':00';
            }
            return {
                hours: hours,
                minutes: minutes,
                time: pad(hours) + ':' + pad(minutes),
                date: dateMatch ? dateMatch[1] : '',
                isoStart: isoStart
            };
        }
        return null;
    }

    function buildFallbackSlot(element, originalText) {
        if (!durationMinutes) {
            return null;
        }
        var details = extractSlotDetails(element, originalText);
        if (!details) {
            return null;
        }
        var startMinutes = details.hours * 60 + details.minutes;
        var endMinutes = Math.min(startMinutes + durationMinutes, MAX_END_MINUTES);
        var endHours = Math.floor(endMinutes / 60);
        var endRemaining = endMinutes % 60;
        if (endMinutes <= startMinutes) {
            endHours = Math.floor((startMinutes + Math.min(durationMinutes, MAX_END_MINUTES - startMinutes)) / 60);
            endRemaining = (startMinutes + Math.min(durationMinutes, MAX_END_MINUTES - startMinutes)) % 60;
        }
        var startLabel = details.time;
        var endLabel = pad(endHours % 24) + ':' + pad(endRemaining);
        var isoEnd = '';
        if (details.isoStart) {
            var startDate = new Date(details.isoStart.replace(' ', 'T'));
            if (!isNaN(startDate.getTime())) {
                var endDate = new Date(startDate.getTime() + durationMinutes * 60000);
                isoEnd = endDate.toISOString().replace('T', ' ').substring(0, 19);
            }
        }
        return {
            start_label: startLabel,
            end_label: endLabel,
            start_time: details.isoStart,
            end_time: isoEnd
        };
    }

    function startObserver() {
        if (typeof MutationObserver === 'undefined') {
            return;
        }
        var host = findHost();
        if (!host) {
            return;
        }
        if (observer) {
            observer.disconnect();
        }
        observer = new MutationObserver(function () {
            updateAll();
        });
        observer.observe(host, { childList: true, subtree: true });
    }

    function bootstrap() {
        var vars = resolveVars();
        if (!vars || !vars.sgmr) {
            if (bootstrapAttempts < maxAttempts) {
                bootstrapAttempts++;
                setTimeout(bootstrap, retryDelay);
            }
            return;
        }
        refreshLookup();
        updateAll();
        startObserver();
    }


    function updateAll() {
        var vars = resolveVars();
        if (!vars || !vars.sgmr) {
            return;
        }
        var hasLookup = refreshLookup();
        ensureBanner();
        if (hasLookup || durationMinutes) {
            applyRange(slotSelectors);
            applyRange(summarySelectors);
            applyManualLabels();
        }
        updateSummaryFromSelection();
    }


    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('sgmr:context', function () {
            updateAll();
        });
    }


    if (!global.sgmrBookingContext) {
        var cookieSeed = contextFromCookie();
        if (cookieSeed) {
            global.sgmrBookingContext = cookieSeed.sgmr;
        }
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('click', function (event) {
            if (event.target && (event.target.closest && event.target.closest('.fcal_spot'))) {
                setTimeout(updateAll, 50);
            }
        }, true);
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(bootstrap, 0);
    } else {
        document.addEventListener('DOMContentLoaded', bootstrap);
    }

    var enforceCount = 0;
    var enforceTimer = setInterval(function () {
        enforceCount += 1;
        updateAll();
        if (enforceCount >= 20) {
            clearInterval(enforceTimer);
        }
    }, 250);

        global.sgmrForceRefresh = updateAll;
})();
