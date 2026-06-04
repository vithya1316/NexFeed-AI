
// ── Main function called by PHP pages ────────────────────────
function captureGPS(opts) {
    var btn     = document.getElementById(opts.btnId);
    var status  = document.getElementById(opts.statusId);
    var addrBox = document.getElementById(opts.addrId);
    var latBox  = document.getElementById(opts.latId);
    var lngBox  = document.getElementById(opts.lngId);

    if (!navigator.geolocation) {
        setStatus(status, '❌ GPS not supported. Please type your address.', 'red');
        return;
    }

    setBtn(btn, '⏳ Getting location…', true);
    setStatus(status, '', '');

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            var lat      = pos.coords.latitude;
            var lon      = pos.coords.longitude;
            var accuracy = pos.coords.accuracy; // metres

            // Save coordinates to hidden fields immediately
            if (latBox) latBox.value = lat;
            if (lngBox) lngBox.value = lon;

            setStatus(status, '📡 Getting address…', 'grey');

            // ── NOMINATIM with zoom=18 for street-level detail ──────
            // zoom levels: 3=country, 10=city, 14=suburb, 18=building/street
            var url = 'https://nominatim.openstreetmap.org/reverse'
                    + '?format=json'
                    + '&lat=' + lat
                    + '&lon=' + lon
                    + '&zoom=18'            // street level detail
                    + '&addressdetails=1'
                    + '&accept-language=en';

            fetch(url, {
                headers: { 'User-Agent': 'NEXFEEDAI/1.0' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var address = buildAddress(data);

                if (address) {
                    if (addrBox) {
                        addrBox.value = address;
                        addrBox.style.borderColor = '#2E7D32';
                        addrBox.removeAttribute('readonly'); // always allow manual edit
                    }

                    var accMsg = accuracy < 50
                        ? '✅ Address filled — edit if needed'
                        : '✅ Address filled (GPS accuracy: ' + Math.round(accuracy) + 'm — you may want to edit)';

                    setStatus(status, accMsg, '#2E7D32');
                    setBtn(btn, '✅ Location Set', false);
                } else {
                    if (addrBox) addrBox.placeholder = 'GPS got your location — please type the address';
                    setStatus(status, '⚠️ Address not found. Please type it manually.', 'orange');
                    setBtn(btn, '📍 Retry GPS', false);
                }
            })
            .catch(function() {
                // Nominatim failed — coordinates saved, user types address
                if (addrBox) addrBox.placeholder = 'GPS captured — type your street address';
                setStatus(status, '⚠️ Address lookup failed. Please type it.', 'orange');
                setBtn(btn, '📍 Retry GPS', false);
            });
        },
        function(err) {
            var msgs = {
                1: '❌ Location denied. Allow access in browser settings.',
                2: '❌ GPS unavailable. Try moving outside.',
                3: '⏳ GPS timed out. Retrying...',
            };

            setStatus(status, msgs[err.code] || '❌ GPS error.', 'red');

            if (err.code === 3) {
                setTimeout(function() {
                    captureGPS(opts);   // retry automatically
                }, 2000);
            } else {
                setBtn(btn, '📍 Get My Live Address', false);
            }
        },
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 }
    );
}

// ── Build clean address from Nominatim response ───────────────
function buildAddress(data) {
    if (!data || !data.address) return '';
    var a = data.address;

    var parts = [];

    // 1. House number + Road/Street (most specific)
    var street = '';
    if (a.house_number) street += a.house_number + ', ';
    var road = a.road || a.pedestrian || a.footway || a.path || a.service || '';
    if (road) street += road;
    if (street.trim()) parts.push(street.trim().replace(/,\s*$/, ''));

    // 2. Building / Amenity (e.g. "Hotel Saravana Bhavan")
    var building = a.building || a.amenity || '';
    if (building && building !== road) parts.push(building);

    // 3. Neighbourhood / Locality
    var area = a.neighbourhood || a.suburb || a.quarter || a.residential || '';
    if (area) parts.push(area);

    // 4. City / Town / Village
    var city = a.city || a.town || a.village || a.municipality || '';
    if (city) parts.push(city);

    // 5. District (only if different from city)
    var district = a.county || a.state_district || '';
    if (district && district !== city) parts.push(district);

    // 6. State
    if (a.state) parts.push(a.state);

    // 7. Postcode
    if (a.postcode) parts.push(a.postcode);

    // Deduplicate: remove any part that is a substring of another
    var seen = [];
    var clean = parts.filter(function(p) {
        p = (p || '').trim();
        if (!p) return false;
        var key = p.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (!key) return false;
        for (var i = 0; i < seen.length; i++) {
            if (seen[i] === key || seen[i].indexOf(key) !== -1 || key.indexOf(seen[i]) !== -1) {
                return false;
            }
        }
        seen.push(key);
        return true;
    });

    return clean.join(', ');
}

// ── Helpers ───────────────────────────────────────────────────
function setBtn(btn, text, disabled) {
    if (!btn) return;
    btn.textContent = text;
    btn.disabled    = disabled;
}
function setStatus(el, text, color) {
    if (!el) return;
    el.textContent  = text;
    el.style.color  = color === 'red' ? '#E53935' : (color === 'grey' ? '#8896AB' : color);
}

// ── Haversine in JS (for client-side distance display) ────────
function haversineJS(lat1, lon1, lat2, lon2) {
    var R    = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLon = (lon2 - lon1) * Math.PI / 180;
    var a    = Math.sin(dLat / 2) * Math.sin(dLat / 2)
             + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
             * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    var km   = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return Math.round(km * 10) / 10; // 1 decimal place
}
