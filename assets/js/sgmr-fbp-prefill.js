(function () {
    if (typeof window === 'undefined') {
        return;
    }

    var localConfig = window.sgmrFbpPrefill || {};
    var bookingConfig = window.SG_BOOKING_PREFILL_CONFIG || {};

    function firstNonEmptyString() {
        for (var i = 0; i < arguments.length; i++) {
            var value = arguments[i];
            if (typeof value === 'string' && value.trim() !== '') {
                return value.trim();
            }
            if (typeof value === 'number' && !isNaN(value)) {
                var numberAsString = String(value);
                if (numberAsString !== '') {
                    return numberAsString;
                }
            }
        }
        return '';
    }

    function ensureArray(value) {
        if (Array.isArray(value)) {
            return value.filter(function (item) {
                return typeof item === 'string' && item !== '';
            });
        }
        if (typeof value === 'string' && value !== '') {
            return [value];
        }
        return [];
    }

    function extractFromQuery(params) {
        if (typeof URLSearchParams === 'undefined') {
            return '';
        }
        var search = new URLSearchParams(window.location.search || '');
        for (var i = 0; i < params.length; i++) {
            var raw = search.get(params[i]);
            if (raw && raw !== '') {
                return raw;
            }
        }
        return '';
    }

    var endpoint = firstNonEmptyString(
        localConfig.endpoint,
        bookingConfig.rest && bookingConfig.rest.prefill
    );

    if (!endpoint) {
        return;
    }

    var orderParamCandidates = ensureArray(localConfig.orderParamCandidates);
    if (!orderParamCandidates.length) {
        orderParamCandidates = ['order', 'order_id', 'orderId', 'orderID'];
    }

    var orderId = firstNonEmptyString(
        localConfig.orderId,
        bookingConfig.order,
        bookingConfig.order_id
    );

    if (!orderId) {
        orderId = extractFromQuery(orderParamCandidates);
    }

    if (!orderId) {
        return;
    }

    function fetchPrefill() {
        var url = endpoint;
        var separator = url.indexOf('?') === -1 ? '?' : '&';
        url += separator + 'order_id=' + encodeURIComponent(orderId);

        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Prefill request failed with status ' + response.status);
            }
            return response.json();
        });
    }

    function assignValue(field, value) {
        if (!field || typeof value !== 'string' || value === '') {
            return false;
        }
        if (typeof field.value === 'string' && field.value === value) {
            return true;
        }
        try {
            field.value = value;
        } catch (error) {
            return false;
        }
        try {
            var event = new Event('input', { bubbles: true });
            field.dispatchEvent(event);
        } catch (error) {
            // ignore dispatch errors
        }
        return true;
    }

    function setFirstMatching(doc, selectors, value) {
        if (!doc || !selectors.length || !value) {
            return false;
        }
        for (var i = 0; i < selectors.length; i++) {
            var element;
            try {
                element = doc.querySelector(selectors[i]);
            } catch (error) {
                element = null;
            }
            if (element) {
                if (assignValue(element, value)) {
                    return true;
                }
            }
        }
        return false;
    }

    var nameSelectors = [
        'input[name="name"]',
        'input[name="full_name"]',
        'input[name="customer_name"]',
        'input[name="your_name"]',
        'input[id="fcalInputIDname"]',
        'input[id$="IDname"]',
        'input[aria-label="Ihr Name"]'
    ];
    var emailSelectors = [
        'input[name="email"]',
        'input[type="email"]',
        'input[name="customer_email"]',
        'input[name="your_email"]',
        'input[id="fcalInputIDemail"]',
        'input[id$="IDemail"]',
        'input[aria-label="Ihre E-Mail"]'
    ];
    var addressSelectors = [
        'textarea[name="address"]',
        'textarea[name="location"]',
        'textarea[name="customer_address"]',
        'input[name="address"]',
        'input[name="customer_address"]',
        'input[id="fcalInputIDaddress"]',
        'input[id$="IDaddress"]',
        'input[aria-label="Ihre Adresse"]'
    ];

    function applyToDocument(doc, values) {
        if (!doc || !values) {
            return false;
        }
        var updated = false;
        if (values.name) {
            updated = setFirstMatching(doc, nameSelectors, values.name) || updated;
        }
        if (values.email) {
            updated = setFirstMatching(doc, emailSelectors, values.email) || updated;
        }
        if (values.address) {
            updated = setFirstMatching(doc, addressSelectors, values.address) || updated;
        }
        return updated;
    }

    function writeContextCookie(eventId, context) {
        if (!eventId || !context) {
            return;
        }
        var payload = {};
        try {
            var existing = document.cookie.split('; ').find(function (entry) {
                return entry.indexOf('sgmr_booking_ctx=') === 0;
            });
            if (existing) {
                payload = JSON.parse(decodeURIComponent(existing.split('=')[1])) || {};
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
            var secure = window.location && window.location.protocol === 'https:' ? ';Secure' : '';
            document.cookie = 'sgmr_booking_ctx=' + serialized + ';path=/' + ';max-age=' + (60 * 60) + ';SameSite=Lax' + secure;
        } catch (error) {
            // ignore cookie write issues (ITP, etc.)
        }
    }

    function publishContext(context) {
        if (!context) {
            return;
        }
        if (typeof window !== 'undefined') {
            window.sgmrBookingContext = context;
            if (typeof window.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
                try {
                    window.dispatchEvent(new CustomEvent('sgmr:context', { detail: context }));
                } catch (error) {
                    // ignore browsers without CustomEvent constructor support
                }
            }
        }
        if (context.event_id) {
            writeContextCookie(context.event_id, context);
        }
    }

    function applyPrefill(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }
        var person = payload.person && typeof payload.person === 'object' ? payload.person : {};
        var address = payload.address && typeof payload.address === 'object' ? payload.address : {};
        var stable = payload.fields && payload.fields.stable && typeof payload.fields.stable === 'object' ? payload.fields.stable : {};

        var fullName = firstNonEmptyString(
            person.full_name,
            stable.sg_full_name,
            [person.first_name || stable.sg_first_name || '', person.last_name || stable.sg_last_name || ''].join(' ').trim()
        );

        var email = firstNonEmptyString(
            person.email,
            stable.sg_email
        );

        var addressText = firstNonEmptyString(
            address.line1,
            stable.sg_delivery_address
        );

        if (!addressText) {
            var parts = [];
            var street = firstNonEmptyString(address.street, stable.sg_delivery_street);
            var line2 = firstNonEmptyString(address.line2, stable.sg_delivery_line2);
            var postcode = firstNonEmptyString(address.postcode, stable.sg_delivery_postcode);
            var city = firstNonEmptyString(address.city, stable.sg_delivery_city);

            if (street) {
                parts.push(street);
            }
            if (line2) {
                parts.push(line2);
            }
            if (postcode || city) {
                parts.push((postcode ? postcode + ' ' : '') + city);
            }
            addressText = parts.join('\n');
        }

        var eventId = 0;
        if (payload.router_meta && payload.router_meta.event_id) {
            eventId = parseInt(payload.router_meta.event_id, 10) || 0;
        } else if (payload.fields && payload.fields.stable && payload.fields.stable.sg_event_id) {
            eventId = parseInt(payload.fields.stable.sg_event_id, 10) || 0;
        }

        var context = {
            order_id: payload.order_id || (payload.order && payload.order.id) || 0,
            signature: payload.token && payload.token.sig ? String(payload.token.sig) : '',
            duration_minutes: payload.duration_minutes || (payload.service && payload.service.onsite_duration_minutes) || 0,
            sgm: payload.counts && payload.counts.m ? parseInt(payload.counts.m, 10) : 0,
            sge: payload.counts && payload.counts.e ? parseInt(payload.counts.e, 10) : 0,
            region: payload.routing && payload.routing.region_key ? String(payload.routing.region_key) : '',
            event_id: eventId
        };

        publishContext(context);

        var values = {
            name: fullName,
            email: email,
            address: addressText
        };

        if (!values.name && !values.email && !values.address) {
            return;
        }

        var maxAttempts = 240;
        var attempt = 0;

        function applyOnce() {
            var updated = applyToDocument(document, values);

            var frames = document.querySelectorAll('iframe');
            Array.prototype.forEach.call(frames, function (iframe) {
                try {
                    var frameDoc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
                    if (!frameDoc) {
                        return;
                    }
                    if (applyToDocument(frameDoc, values)) {
                        updated = true;
                    }
                } catch (error) {
                    // Ignore cross-origin frames
                }
            });

            return updated;
        }

        function schedule() {
            if (attempt >= maxAttempts) {
                return;
            }
            attempt += 1;
            window.setTimeout(function () {
                applyOnce();
                schedule();
            }, 250);
        }

        schedule();
        applyOnce();

        Array.prototype.forEach.call(document.querySelectorAll('iframe'), function (iframe) {
            iframe.addEventListener('load', function () {
                window.setTimeout(applyOnce, 50);
            });
        });
    }

    function init() {
        fetchPrefill()
            .then(applyPrefill)
            .catch(function (error) {
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('SGMR Prefill failed:', error);
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
