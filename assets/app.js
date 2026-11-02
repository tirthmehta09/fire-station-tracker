/* Shared helpers + live map rendering used by admin, team and public screens. */
(function () {
  'use strict';
  const APP = window.APP || {};
  const FST = (window.FST = window.FST || {});
  const TZ = 'Asia/Kolkata';
  const STATUS = { pending: 'Pending', reached: 'Reached', done: 'Delivered' };
  const TEAM_STATUS = { not_started: 'Not started', on_route: 'On the way', returned: 'Back at base' };
  FST.STATUS = STATUS;
  FST.TEAM_STATUS = TEAM_STATUS;

  /* ---------------------------------------------------------------- utils */
  const esc = (FST.esc = (s) =>
    String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])));

  FST.time = (ts) => {
    if (!ts) return '';
    return new Date(ts * 1000)
      .toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: TZ })
      .replace(/\s?am$/i, ' AM').replace(/\s?pm$/i, ' PM');
  };
  FST.ago = (ts, now) => {
    if (!ts) return 'never';
    const s = Math.max(0, (now || Date.now() / 1000) - ts);
    if (s < 20) return 'just now';
    if (s < 60) return Math.round(s) + ' s ago';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    const h = Math.floor(s / 3600);
    return h + ' h ' + Math.round((s % 3600) / 60) + ' min ago';
  };
  FST.dur = (secs) => {
    const m = Math.round(secs / 60);
    if (m < 60) return m + ' min';
    return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
  };
  FST.freshness = (ts, now) => {
    const s = (now || Date.now() / 1000) - (ts || 0);
    if (!ts || s > 900) return 'offline';
    if (s > 180) return 'stale';
    return 'live';
  };
  FST.dist = (a, b) => {
    const R = 6371000, r = Math.PI / 180;
    const dLat = (b[0] - a[0]) * r, dLng = (b[1] - a[1]) * r;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
  };
  FST.km = (m) => (m < 1000 ? Math.round(m) + ' m' : (m / 1000).toFixed(1) + ' km');
  /** "browser", "Traccar app" or "browser + app" for a phone. */
  FST.phoneSource = (d) => {
    const app = d.app_seen_at || d.source === 'traccar';
    const web = d.source === 'web';
    return app && web ? 'browser + app' : app ? 'Traccar app' : 'browser';
  };
  FST.navUrl = (lat, lng) => 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' + lat + ',' + lng;

  /* ---------------------------------------------------------------- toast */
  FST.toast = (msg, type) => {
    let box = document.getElementById('toasts');
    if (!box) {
      box = document.createElement('div');
      box.id = 'toasts';
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    const t = document.createElement('div');
    t.className = 'toast ' + (type || '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(() => t.remove(), type === 'error' ? 6000 : 3200);
  };

  /* ------------------------------------------------------------------ api */
  FST._inflight = 0;
  FST._down = false;
  /** True while a button request is running or a finger/mouse is pressed: live screens wait before redrawing. */
  FST.busy = () => FST._inflight > 0 || FST._down;
  document.addEventListener('pointerdown', () => { FST._down = true; }, true);
  ['pointerup', 'pointercancel'].forEach((t) => document.addEventListener(t, () => { setTimeout(() => { FST._down = false; }, 0); }, true));

  /** Replace an element's HTML only when it actually changed (no flicker, no lost taps). */
  FST.setHTML = (el, html) => {
    if (el && el._fstHtml !== html) { el.innerHTML = html; el._fstHtml = html; }
  };

  FST.api = async (action, data) => {
    FST._inflight++;
    try { return await FST._api(action, data); } finally { FST._inflight--; }
  };
  FST._api = async (action, data) => {
    const res = await fetch(APP.api + 'action.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': APP.csrf || '' },
      body: JSON.stringify(Object.assign({ action }, data || {})),
    });
    let j = {};
    try { j = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || j.error) throw new Error(j.error || 'Something went wrong (' + res.status + ')');
    return j;
  };
  /* Delete a phone (admin): any button with data-del-device, on the Teams page and the live map. */
  FST.deletePhoneButton = (d) => '<button type="button" class="btn btn-del" data-del-device="' + d.id + '" data-approved="' + (d.approved === false ? 0 : 1) + '" data-name="' + FST.esc(d.name) +
    '" title="Delete this phone" aria-label="Delete ' + FST.esc(d.name) + '"><i class="bi bi-trash3"></i></button>';
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-del-device]');
    if (!btn) return;
    e.preventDefault();   // inside a phone's header this would also open/close it
    if (btn.disabled || FST._inflight > 0) return;
    if (!confirm('Delete "' + (btn.dataset.name || 'this phone') + '"?\n\nIts location history is deleted too, so it drops out of the report. ' +
      'If this phone is still sharing, it will show up again as a new phone.\n\nTo stop tracking it but keep its history, set it to Off on the Teams page instead.')) return;
    btn.disabled = true;
    btn.classList.add('is-busy');
    const id = +btn.dataset.delDevice;
    const done = () => document.dispatchEvent(new CustomEvent('fst:device-deleted', { detail: { id, approved: btn.dataset.approved !== '0' } }));
    try {
      await FST.api('device_delete', { id });
      FST.toast('Phone deleted', 'ok');
      const row = btn.closest('[data-device-row]');
      if (!row) { done(); return; }
      row.classList.add('leaving');
      setTimeout(() => {
        const card = row.closest('[data-device-card]');
        row.remove();
        if (card && !card.querySelector('[data-device-row]')) card.remove();
        done();
      }, 300);
    } catch (err) {
      FST.toast(err.message, 'error');
      btn.disabled = false;
      btn.classList.remove('is-busy');
    }
  });
  FST.getJSON = async (url) => {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 15000);
    try {
      const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: ctrl.signal });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || 'HTTP ' + res.status);
      return j;
    } finally {
      clearTimeout(timer);
    }
  };

  /** Poll the live state; calls onState(state) and onStatus(ok). Refreshes right away when the tab comes back. */
  FST.poll = (onState, onStatus, everyMs) => {
    let timer = null, busy = false, held = null;
    const deliver = (s) => {
      if (FST.busy()) { held = s; return; }
      held = null;
      onState(s);
    };
    setInterval(() => { if (held && !FST.busy()) deliver(held); }, 400);
    const tick = async () => {
      clearTimeout(timer);
      if (busy) return;
      busy = true;
      try {
        const s = await FST.getJSON(APP.api + 'state.php');
        deliver(s);
        onStatus && onStatus(true);
      } catch (e) {
        onStatus && onStatus(false, e);
      } finally {
        busy = false;
        timer = setTimeout(tick, document.hidden ? everyMs * 3 : everyMs);
      }
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    window.addEventListener('online', tick);
    tick();
    return { refresh: tick };
  };

  /* ------------------------------------------------------------------ map */
  const CAR_SVG = '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11a2 2 0 0 1 1 1.7V17a1 1 0 0 1-1 1h-1a2 2 0 0 1-4 0h-4a2 2 0 0 1-4 0H5a1 1 0 0 1-1-1v-4.3A2 2 0 0 1 5 11zm2.1 0h9.8l-1.2-3.6a.6.6 0 0 0-.6-.4H8.9a.6.6 0 0 0-.6.4zM7 15.5a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4zm10 0a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4z"/></svg>';
  const HOME_SVG = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z"/></svg>';

  const OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
  const webgl = () => {
    try { const c = document.createElement('canvas'); return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl'))); } catch (e) { return false; }
  };
  /** Base maps (free, no key, OpenFreeMap): a colourful Google-style "Map" (default) and a calm grey "Simple". */
  FST.baseLayers = () => {
    const raster = () => L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: OSM_ATTR });
    if (typeof L.maplibreGL !== 'function' || !webgl()) return { Map: raster() };
    const gl = (style) => L.maplibreGL({ style: 'https://tiles.openfreemap.org/styles/' + style, attribution: '<a href="https://openfreemap.org" target="_blank">OpenFreeMap</a> ' + OSM_ATTR });
    return { Map: gl('liberty'), Simple: gl('positron') };
  };
  FST.defaultBase = (bases) => bases[Object.keys(bases)[0]];

  /** Ask the vector base map to redraw (after jumps and resizes some phones skip a frame). */
  FST.repaint = (map) => {
    Object.values(map._layers || {}).forEach((l) => {
      if (l.getMaplibreMap) { try { const g = l.getMaplibreMap(); g.resize(); g.triggerRepaint(); } catch (e) { /* not ready yet */ } }
    });
  };

  FST.createMap = (el, opts) => {
    const map = L.map(el, Object.assign({ zoomControl: true, preferCanvas: false, tap: true }, opts || {})).setView([19.06, 72.84], 12);
    const bases = FST.baseLayers();
    FST.defaultBase(bases).addTo(map);
    if (map.attributionControl) map.attributionControl.setPosition('bottomleft');
    if (Object.keys(bases).length > 1) L.control.layers(bases, null, { position: 'topright' }).addTo(map);
    const Fit = L.Control.extend({
      options: { position: 'topleft' },
      onAdd: () => {
        const box = L.DomUtil.create('div', 'leaflet-bar');
        const a = L.DomUtil.create('a', 'fit-btn', box);
        a.href = '#'; a.title = 'Show everything'; a.setAttribute('role', 'button'); a.innerHTML = '<i class="bi bi-arrows-fullscreen"></i>';
        L.DomEvent.on(a, 'click', (e) => { L.DomEvent.preventDefault(e); L.DomEvent.stopPropagation(e); if (m.onFit) m.onFit(); });
        return box;
      },
    });
    new Fit().addTo(map);
    // Keep tiles filling the box when the layout settles, the phone rotates or the window resizes.
    if (window.ResizeObserver) new ResizeObserver(() => { map.invalidateSize(); FST.repaint(map); }).observe(el);
    setTimeout(() => { map.invalidateSize(); FST.repaint(map); }, 300);
    const m = { map, stations: {}, vehicles: {}, trails: {}, base: null, fitted: false, focusTeam: null, layers: { trails: L.layerGroup().addTo(map) } };
    (FST.maps = FST.maps || []).push(m);
    return m;
  };

  const teamById = (state) => {
    const o = {};
    (state.teams || []).forEach((t) => (o[t.id] = t));
    return o;
  };
  FST.teamById = teamById;

  /**
   * A station as one team sees it. Most stations belong to one team; a shared station (every team stops there,
   * s.shared = 'first' or 'last') carries each team's visit in s.visits. Returns null if it isn't on that team's route.
   */
  FST.teamStop = (s, teamId) => {
    if (!s.shared) return s.team_id === teamId ? s : null;
    const v = (s.visits || []).find((x) => x.team_id === teamId);
    if (!v || v.skip) return null;
    return Object.assign({}, s, { team_id: teamId, for_team: teamId, status: v.status, reached_at: v.reached_at, done_at: v.done_at, order: v.order });
  };
  /** A team's stops in route order (first stop for every team, its own stations, last stop for every team). */
  FST.teamStops = (state, teamId) => state.stations.filter((s) => s.active).map((s) => FST.teamStop(s, teamId)).filter(Boolean)
    .sort((a, b) => (a.order || 999) - (b.order || 999));
  FST.SHARED = { first: 'First stop for every team', last: 'Last stop for every team' };
  /** "2 of 3 teams delivered" for a shared station. */
  FST.sharedProgress = (s) => {
    const going = (s.visits || []).filter((v) => !v.skip);
    const done = going.filter((v) => v.status === 'done').length;
    return done + ' of ' + going.length + (going.length === 1 ? ' team' : ' teams') + ' delivered';
  };

  function stationIcon(s, team, dim) {
    const cls = ['st-mk', s.active ? s.status : 'off', dim ? 'dim' : '', s.shared && !s.for_team ? 'shared' : ''].join(' ');
    const label = s.status === 'done' ? '✓' : s.order != null && s.team_id ? s.order : s.shared ? '<i class="bi bi-people-fill"></i>' : '•';
    return L.divIcon({ className: '', iconSize: null, html: '<div class="' + cls + '" style="--c:' + (team ? team.color : '#64748b') + '">' + label + '</div>' });
  }
  /** Marker text: the team name (plus the car name only when the team has more than one car on the map). */
  FST.vehicleName = (v, team, state) => {
    const name = team ? team.name : v.label;
    const many = state.vehicles.filter((x) => x.team_id === v.team_id).length > 1;
    return many && v.car_id ? name + ' · ' + v.label : name;
  };
  function vehicleIcon(v, team, now, dim, name) {
    const fresh = FST.freshness(v.fix_at, now);
    const cls = ['veh-mk', fresh !== 'live' ? 'stale' : '', dim ? 'dim' : ''].join(' ');
    const ago = fresh !== 'live' ? ' <span class="ago">' + FST.ago(v.fix_at, now).replace(' ago', '') + '</span>' : '';
    return L.divIcon({
      className: '', iconSize: null,
      html: '<div class="' + cls + '" style="--c:' + (team ? team.color : '#333') + '"><span class="ico">' + CAR_SVG + '</span><span class="nm">' + esc(name) + '</span>' + ago + '</div>',
    });
  }

  FST.stationPopup = (s, team, state, extra, stopExtra) => {
    let h = '<b>' + esc(s.name) + '</b>';
    if (s.area) h += '<br><span class="muted">' + esc(s.area) + '</span>';
    if (s.shared && !s.for_team) {
      // Every team stops here: one line per team.
      h += '<div class="small" style="margin-top:6px"><i class="bi bi-people-fill"></i> <b>' + FST.SHARED[s.shared] + '</b>' + (s.active ? ' · ' + FST.sharedProgress(s) : ' · Inactive') + '</div>';
      const teams = teamById(state);
      h += '<div class="pop-teams">' + (s.visits || []).map((v) => {
        const t = teams[v.team_id];
        if (!t) return '';
        const stop = FST.teamStop(s, v.team_id);
        const when = v.done_at ? 'Delivered ' + FST.time(v.done_at) : v.reached_at ? 'Reached ' + FST.time(v.reached_at) : '';
        return '<div class="pop-team"><span class="nowrap"><span class="dot" style="--c:' + t.color + '"></span> ' + esc(t.name) + '</span> ' +
          (v.skip ? '<span class="muted small">not going</span>' : '<span class="pill plain ' + v.status + '">' + STATUS[v.status] + '</span>' + (when ? ' <span class="small muted">' + when + '</span>' : '') +
          (stopExtra && stop && s.active ? '<div class="pop-team-act">' + stopExtra(stop) + '</div>' : '')) + '</div>';
      }).join('') + '</div>';
    } else {
      h += '<div style="margin-top:6px"><span class="pill ' + (s.active ? s.status : 'inactive') + '">' + (s.active ? STATUS[s.status] : 'Inactive') + '</span>';
      if (team) h += ' <span class="nowrap"><span class="dot" style="--c:' + team.color + '"></span> ' + esc(team.name) + (s.order ? ' · stop ' + s.order : '') + '</span>';
      h += '</div>';
      if (s.shared) h += '<div class="small" style="margin-top:4px"><i class="bi bi-people-fill"></i> ' + FST.SHARED[s.shared] + '</div>';
      if (s.reached_at) h += '<div class="small" style="margin-top:4px">Reached ' + FST.time(s.reached_at) + (s.done_at ? ' · Delivered ' + FST.time(s.done_at) : '') + '</div>';
    }
    if (s.boxes) h += '<div class="small">Sweet boxes: <b>' + s.boxes + '</b></div>';
    if (s.lat != null) h += '<div class="pop-actions"><a class="btn btn-sm" target="_blank" rel="noopener" href="' + FST.navUrl(s.lat, s.lng) + '">Directions</a>' + (extra || '') + '</div>';
    return h;
  };

  FST.vehiclePopup = (v, team, now) => {
    const f = FST.freshness(v.fix_at, now);
    let h = '<b><span class="dot" style="--c:' + (team ? team.color : '#333') + '"></span> ' + esc(team ? team.name : v.label) + '</b>';
    if (team && team.description) h += '<br><span class="muted">' + esc(team.description) + '</span>';
    h += '<br>' + esc(v.label) + (v.plate ? ' · ' + esc(v.plate) : '');
    h += '<div style="margin-top:6px"><span class="pill ' + f + '">' + (f === 'live' ? 'Live' : f === 'stale' ? 'Delayed' : 'Offline') + '</span> <span class="small">' + FST.ago(v.fix_at, now) + '</span></div>';
    const bits = [];
    if (v.speed != null) bits.push(Math.round(v.speed) + ' km/h');
    if (v.battery != null) bits.push('Battery ' + Math.round(v.battery) + '%');
    if (v.phones > 1) bits.push(v.phones + ' phones');
    if (bits.length) h += '<div class="small" style="margin-top:4px">' + bits.join(' · ') + '</div>';
    if (v.driver) h += '<div class="small">Driver: ' + esc(v.driver) + '</div>';
    return h;
  };

  /**
   * Draw or update everything on the map. opts:
   *   stationExtra(s) -> extra popup HTML, stopExtra(stop) -> extra HTML per team on a shared station's popup,
   *   onStation(s), filterTeam (id) to dim others and show that team's own progress at shared stations, showInactive
   */
  FST.drawState = (m, state, opts) => {
    opts = opts || {};
    const teams = teamById(state);
    const now = state.now;
    const seen = {};
    const focus = opts.filterTeam || m.focusTeam || null;

    // base
    const b = state.event.base;
    if (!m.base) {
      m.base = L.marker([b.lat, b.lng], {
        icon: L.divIcon({ className: '', iconSize: null, html: '<div class="base-mk" title="Start / end point">' + HOME_SVG + '</div>' }),
        zIndexOffset: 500,
      }).addTo(m.map).bindPopup('<b>Start &amp; end point</b><br>' + esc(b.name) + '<div class="pop-actions"><a class="btn btn-sm" target="_blank" rel="noopener" href="' + FST.navUrl(b.lat, b.lng) + '">Directions</a></div>');
    } else {
      m.base.setLatLng([b.lat, b.lng]);
    }

    // stations
    state.stations.forEach((s0) => {
      if (s0.lat == null || (!s0.active && !opts.showInactive)) return;
      const key = 's' + s0.id;
      seen[key] = 1;
      // With one team in focus, a shared station shows that team's own visit.
      const s = focus && s0.shared ? FST.teamStop(s0, focus) || s0 : s0;
      const team = s.team_id ? teams[s.team_id] : null;
      const dim = focus && s.team_id !== focus;
      const sig = [s.status, s.order, s.team_id, s.active, team && team.color, dim, s.lat, s.lng, s.shared].join('|');
      const popup = FST.stationPopup(s, team, state, opts.stationExtra ? opts.stationExtra(s) : '', opts.stopExtra);
      let mk = m.stations[s.id];
      if (!mk) {
        mk = L.marker([s.lat, s.lng], { icon: stationIcon(s, team, dim), zIndexOffset: 100 }).addTo(m.map).bindPopup(popup, { maxWidth: 280 });
        if (opts.onStation) mk.on('click', () => opts.onStation(s));
        mk._fstSig = sig;
        mk._fstHtml = popup;
        m.stations[s.id] = mk;
      } else {
        if (mk._fstSig !== sig) {
          mk.setLatLng([s.lat, s.lng]);
          mk.setIcon(stationIcon(s, team, dim));
          mk._fstSig = sig;
        }
        if (mk._fstHtml !== popup) {
          mk.setPopupContent(popup);
          mk._fstHtml = popup;
        }
      }
    });
    Object.keys(m.stations).forEach((id) => {
      if (!seen['s' + id]) { m.map.removeLayer(m.stations[id]); delete m.stations[id]; }
    });

    // vehicles
    const vseen = {};
    state.vehicles.forEach((v) => {
      vseen[v.key] = 1;
      const team = teams[v.team_id];
      const dim = focus && v.team_id !== focus;
      const name = FST.vehicleName(v, team, state);
      const fresh = FST.freshness(v.fix_at, now);
      const sig = [name, team && team.color, fresh, fresh !== 'live' ? FST.ago(v.fix_at, now) : '', dim].join('|');
      const popup = FST.vehiclePopup(v, team, now);
      let mk = m.vehicles[v.key];
      if (!mk) {
        mk = L.marker([v.lat, v.lng], { icon: vehicleIcon(v, team, now, dim, name), zIndexOffset: 1000 }).addTo(m.map).bindPopup(popup);
        mk.on('click', () => FST.showTrail(m, v, team, state));
        mk._fstSig = sig;
        m.vehicles[v.key] = mk;
      } else {
        mk.setLatLng([v.lat, v.lng]);
        if (mk._fstSig !== sig) { mk.setIcon(vehicleIcon(v, team, now, dim, name)); mk._fstSig = sig; }
        mk.setPopupContent(popup);
      }
      mk._v = v;
    });
    Object.keys(m.vehicles).forEach((k) => {
      if (!vseen[k]) { m.map.removeLayer(m.vehicles[k]); delete m.vehicles[k]; }
    });

    if (!m.fitted) {
      m.fitted = true;
      FST.fit(m, state, opts.filterTeam, false);
    }
  };

  FST.fit = (m, state, teamId, animate) => {
    // The map must know its real size first, or it zooms all the way in.
    m.map.invalidateSize(false);
    const size = m.map.getSize();
    if (size.x < 60 || size.y < 60) { setTimeout(() => FST.fit(m, state, teamId, animate), 400); return; }
    const pts = [];
    const b = state.event.base;
    pts.push([b.lat, b.lng]);
    state.stations.forEach((s) => { if (s.lat != null && s.active && (!teamId || FST.teamStop(s, teamId))) pts.push([s.lat, s.lng]); });
    state.vehicles.forEach((v) => { if (!teamId || v.team_id === teamId) pts.push([v.lat, v.lng]); });
    const anim = { animate: animate !== false };
    const pad = m.fitPad ? m.fitPad() : { padding: [36, 36] };
    if (pts.length > 1) m.map.fitBounds(L.latLngBounds(pts), Object.assign({ maxZoom: 15 }, pad, anim));
    else m.map.setView(pts[0], 13, anim);
    setTimeout(() => FST.repaint(m.map), 250);
  };

  FST.focusTeam = (m, state, teamId) => {
    m.focusTeam = m.focusTeam === teamId ? null : teamId;
    FST.drawState(m, state);
    FST.fit(m, state, m.focusTeam);
    return m.focusTeam;
  };

  FST.showTrail = async (m, v, team, state) => {
    if (!state.event.trails) return;
    try {
      const j = await FST.getJSON(APP.api + 'trail.php?v=' + encodeURIComponent(v.key));
      m.layers.trails.clearLayers();
      if (j.points && j.points.length > 1) {
        L.polyline(j.points, { color: team ? team.color : '#333', weight: 4, opacity: 0.75 }).addTo(m.layers.trails);
      }
    } catch (e) { /* trail is optional */ }
  };

  /* --------------------------------------------------------------- panels */
  FST.counts = (state) => {
    const c = { total: 0, done: 0, reached: 0, pending: 0, unassigned: 0 };
    state.stations.forEach((s) => {
      if (!s.active) return;
      c.total++;
      c[s.status]++;
      if (!s.team_id) c.unassigned++;
    });
    return c;
  };

  FST.bar = (c) => {
    const t = Math.max(1, c.total);
    return '<div class="bar"><i class="b-done" style="width:' + (100 * c.done) / t + '%"></i><i class="b-reached" style="width:' + (100 * c.reached) / t + '%"></i></div>';
  };

  /** Animate a number from its last value to the new one. */
  FST.countUp = (el, to) => {
    if (!el) return;
    const from = +(el.dataset.v || 0);
    el.dataset.v = to;
    if (from === to) { el.textContent = to; return; }
    const t0 = performance.now(), dur = 800;
    const step = (t) => {
      const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(from + (to - from) * e);
      if (k < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  /** Hero summary: a big progress ring, the % delivered, and three stat tiles. */
  FST.renderRing = (el, state) => {
    const c = FST.counts(state);
    const onRoad = state.teams.filter((t) => t.status === 'on_route').length;
    const back = state.teams.filter((t) => t.status === 'returned').length;
    const C = 2 * Math.PI * 50;
    if (!el.querySelector('.ring')) {
      el.innerHTML = '<div class="sum-top"><div class="ring"><svg viewBox="0 0 120 120" aria-hidden="true"><defs><linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">' +
        '<stop offset="0" stop-color="#f7a531"/><stop offset=".55" stop-color="#ea580c"/><stop offset="1" stop-color="#d6322b"/></linearGradient></defs>' +
        '<circle class="ring-bg" cx="60" cy="60" r="50"/><circle class="ring-fg" cx="60" cy="60" r="50" stroke="url(#ringGrad)" stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '"/></svg>' +
        '<div class="ring-txt"><div><b><span data-n="done">0</span><small>/<span data-n="total">0</span></small></b><span class="lbl">delivered</span></div></div></div>' +
        '<div class="sum-pct"><div class="pct grad-text"><span data-n="pct">0</span>%</div><div class="pct-lbl" data-n="headline"></div><div class="pct-sub" data-n="teams"></div></div></div>' +
        '<div class="sum-tiles">' +
        '<div class="sum-tile warn"><i class="bi bi-geo-alt-fill"></i><b data-n="reached">0</b><span>Reached</span></div>' +
        '<div class="sum-tile pend"><i class="bi bi-hourglass-split"></i><b data-n="pending">0</b><span>Pending</span></div>' +
        '<div class="sum-tile info"><i class="bi bi-truck"></i><b data-n="road">0</b><span>On the way</span></div></div>';
    }
    const q = (k) => el.querySelector('[data-n="' + k + '"]');
    const pct = c.total ? Math.round((100 * c.done) / c.total) : 0;
    FST.countUp(q('done'), c.done);
    FST.countUp(q('total'), c.total);
    FST.countUp(q('pct'), pct);
    FST.countUp(q('reached'), c.reached);
    FST.countUp(q('pending'), c.pending);
    FST.countUp(q('road'), onRoad);
    q('headline').textContent = c.total && c.done === c.total ? 'All stations delivered 🎉' : 'of stations delivered';
    q('teams').textContent = state.teams.length + ' teams' + (back ? ' · ' + back + ' back at base' : '');
    requestAnimationFrame(() => { el.querySelector('.ring-fg').style.strokeDashoffset = C * (1 - (c.total ? c.done / c.total : 0)); });
  };

  FST.renderSummary = (el, state) => {
    const c = FST.counts(state);
    const onRoad = state.teams.filter((t) => t.status === 'on_route').length;
    const back = state.teams.filter((t) => t.status === 'returned').length;
    el.innerHTML =
      '<div class="row-between"><div><b style="font-size:17px">' + c.done + ' of ' + c.total + ' stations delivered</b>' +
      '<div class="small muted">' + state.teams.length + ' teams · ' + onRoad + ' on the way · ' + back + ' back at base</div></div></div>' +
      '<div style="margin-top:10px">' + FST.bar(c) + '</div>' +
      '<div class="tiles">' +
      '<div class="tile"><span class="ti ok"><i class="bi bi-check2-circle"></i></span><div><b>' + c.done + '</b><span>Delivered</span></div></div>' +
      '<div class="tile"><span class="ti warn"><i class="bi bi-geo-alt"></i></span><div><b>' + c.reached + '</b><span>Reached</span></div></div>' +
      '<div class="tile"><span class="ti pend"><i class="bi bi-hourglass-split"></i></span><div><b>' + c.pending + '</b><span>Pending</span></div></div></div>';
  };

  FST.teamEtaText = (t, now) => {
    if (t.status === 'returned') return 'Back at ' + FST.time(t.returned_at);
    const parts = [];
    if (t.expected_return_at) parts.push('Team says back by <b>' + FST.time(t.expected_return_at) + '</b>');
    if (t.eta) parts.push('Estimated back <b>' + FST.time(t.eta) + '</b>');
    else if (t.status === 'not_started' && t.route_seconds) parts.push('Route takes about ' + FST.dur(t.route_seconds));
    return parts.join(' · ');
  };

  FST.initials = (name) => {
    const words = String(name).split(/\s+/).filter((w) => w && !/^team$/i.test(w));
    if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
    return (words[0] || name).slice(0, 2).toUpperCase();
  };

  FST.renderTeams = (el, state, opts) => {
    opts = opts || {};
    const stById = {};
    state.stations.forEach((s) => (stById[s.id] = s));
    if (!state.teams.length) {
      FST.setHTML(el, '<div class="empty">No teams yet.</div>');
      return;
    }
    FST.setHTML(el, state.teams.map((t) => {
      const c = t.counts;
      const next = t.next_station_id ? stById[t.next_station_id] : null;
      const cars = state.vehicles.filter((v) => v.team_id === t.id);
      const live = cars.filter((v) => FST.freshness(v.fix_at, state.now) === 'live').length;
      const meta = [];
      if (t.status === 'on_route' && next) meta.push((t.next_status === 'reached' ? 'At ' : 'Next: ') + '<b>' + esc(next.name) + '</b>');
      const eta = FST.teamEtaText(t, state.now);
      if (eta) meta.push(eta);
      if (t.started_at) meta.push('Started ' + FST.time(t.started_at));
      meta.push(cars.length ? live + ' of ' + cars.length + (cars.length > 1 ? ' cars' : ' car') + ' live' + (cars.length - live ? ' · last seen ' + FST.ago(Math.max.apply(null, cars.map((v) => v.fix_at)), state.now) : '') : '<span class="muted">No car location yet</span>');
      if (opts.private && t.leader_name) meta.push('Leader: ' + esc(t.leader_name) + (t.leader_phone ? ' · <a href="tel:' + esc(t.leader_phone) + '">' + esc(t.leader_phone) + '</a>' : ''));
      return '<div class="team-card' + (opts.selected === t.id ? ' sel' : '') + '" data-team="' + t.id + '" style="--c:' + t.color + '">' +
        '<div class="tc-head"><span class="tc-av">' + esc(FST.initials(t.name)) + '</span><div class="st-main">' +
        '<div class="tc-top"><span class="tc-name">' + esc(t.name) + '</span><span class="pill t-' + t.status + '">' + TEAM_STATUS[t.status] + '</span></div>' +
        (t.description ? '<div class="tc-desc">' + esc(t.description) + '</div>' : '') + '</div></div>' +
        FST.bar(c) +
        '<div class="tc-meta"><div><b>' + c.done + '/' + c.total + '</b> delivered' + (c.reached ? ' · ' + c.reached + ' reached' : '') + '</div>' + meta.map((x) => '<div>' + x + '</div>').join('') + '</div></div>';
    }).join(''));
  };

  FST.renderStations = (el, state, opts) => {
    opts = opts || {};
    const teams = teamById(state);
    const filter = opts.filter || 'all';
    let list = (opts.teamId ? FST.teamStops(state, opts.teamId) : state.stations).filter((s) => (opts.showInactive || s.active) && (filter === 'all' || s.status === filter));
    if (!list.length) {
      FST.setHTML(el, '<div class="empty">Nothing here.</div>');
      return;
    }
    FST.setHTML(el, list.map((s) => {
      const t = s.team_id ? teams[s.team_id] : null;
      const sub = [];
      if (s.area) sub.push(esc(s.area));
      const everyone = s.shared && !s.for_team;
      if (s.shared) sub.push('<span class="nowrap"><i class="bi bi-people-fill"></i> ' + (s.shared === 'first' ? 'Every team · first stop' : 'Every team · last stop') + '</span>');
      if (everyone) sub.push(FST.sharedProgress(s));
      else sub.push(t ? '<span class="nowrap"><span class="dot" style="--c:' + t.color + ';width:8px;height:8px"></span> ' + esc(t.name) + '</span>' : 'No team yet');
      if (s.reached_at && !everyone) sub.push('Reached ' + FST.time(s.reached_at));
      if (s.done_at) sub.push((everyone ? 'All delivered ' : 'Delivered ') + FST.time(s.done_at));
      const badge = '<span class="badge-num ' + (s.status !== 'pending' ? s.status : '') + (s.team_id || everyone ? '' : ' none') + (everyone ? ' shared' : '') + '" style="--c:' + (t ? t.color : everyone ? '#475569' : '#94a3b8') + '">' +
        (s.status === 'done' ? '✓' : everyone ? '<i class="bi bi-people-fill"></i>' : s.order || '–') + '</span>';
      const dir = s.lat != null ? '<a class="dir-btn" target="_blank" rel="noopener" href="' + FST.navUrl(s.lat, s.lng) + '" title="Directions in Google Maps" aria-label="Directions to ' + esc(s.name) + '"><i class="bi bi-sign-turn-right-fill"></i></a>' : '';
      return '<div class="st-row clickable' + (s.active ? '' : ' is-off') + '" data-station="' + s.id + '">' + badge +
        '<div class="st-main"><div class="row-between"><span class="st-name">' + esc(s.name) + '</span>' +
        '<span class="pill ' + (s.active ? s.status : 'inactive') + '">' + (s.active ? STATUS[s.status] : 'Inactive') + '</span></div>' +
        '<div class="st-sub">' + sub.join(' · ') + '</div>' + (opts.actions ? opts.actions(s) : '') + '</div>' + dir + '</div>';
    }).join(''));
  };

  FST.renderFeed = (el, state, limit) => {
    const teams = teamById(state);
    const items = state.events.slice(0, limit || 15);
    if (!items.length) {
      FST.setHTML(el, '<div class="empty small">Updates will appear here as teams move.</div>');
      return;
    }
    FST.setHTML(el, items.map((e) => {
      const t = e.team_id ? teams[e.team_id] : null;
      return '<div class="feed-item"><time>' + FST.time(e.at) + '</time><span class="dot" style="--c:' + (t ? t.color : '#94a3b8') + '"></span><span>' + esc(e.message) + '</span></div>';
    }).join(''));
  };

  const EVENT_ICON = { reached: 'geo-alt-fill', done: 'check2-circle', start: 'play-fill', returned: 'house-check-fill', eta: 'clock-fill', device: 'phone', undo: 'arrow-counterclockwise', reset: 'arrow-repeat' };
  /** Slide a small card onto the map for each new update (e.g. "Team South reached Gowalia Tank"). */
  FST.mapToast = (box, e, team) => {
    if (!box) return;
    const d = document.createElement('div');
    d.className = 'mt glass';
    d.innerHTML = '<span class="mt-ico" style="--c:' + (team ? team.color : '#ea580c') + '"><i class="bi bi-' + (EVENT_ICON[e.type] || 'bell-fill') + '"></i></span><div>' + esc(e.message) + '<small>' + FST.time(e.at) + '</small></div>';
    box.prepend(d);
    while (box.children.length > 3) box.lastChild.remove();
    setTimeout(() => { d.classList.add('out'); setTimeout(() => d.remove(), 400); }, 6000);
  };

  FST.setStatusBadge = (el, ok, now) => {
    if (!el) return;
    el.classList.toggle('err', !ok);
    (el.querySelector('.status-text') || el.querySelector('span')).textContent = ok ? 'Live · updated ' + FST.time(now || Date.now() / 1000) : 'Connection lost · retrying…';
  };

  /* ------------------------------------------------- location picker widget */
  /**
   * Wires a form with: [data-link] input, [data-find] button, [data-lat]/[data-lng] inputs, [data-pick-map] div,
   * optional [data-name]/[data-address] inputs to prefill, [data-resolve-msg] for messages.
   */
  FST.locationPicker = (root) => {
    const $ = (sel) => root.querySelector(sel);
    const link = $('[data-link]'), latI = $('[data-lat]'), lngI = $('[data-lng]'), msg = $('[data-resolve-msg]');
    const nameI = $('[data-name]'), addrI = $('[data-address]'), mapEl = $('[data-pick-map]');
    let map = null, marker = null;

    const setMsg = (text, type) => { if (msg) { msg.textContent = text; msg.className = 'hint ' + (type === 'error' ? 'text-err' : ''); msg.style.color = type === 'error' ? '#b91c1c' : type === 'ok' ? '#15803d' : ''; } };
    const place = (lat, lng, zoom) => {
      if (!mapEl || typeof L === 'undefined') return;
      if (!map) {
        map = L.map(mapEl).setView([lat || 19.07, lng || 72.84], lat ? 16 : 11);
        FST.defaultBase(FST.baseLayers()).addTo(map);
        map.on('click', (e) => { setPin(e.latlng.lat, e.latlng.lng); setMsg('Pin placed on the map.', 'ok'); });
      }
      if (lat != null && lng != null && lat !== '' && lng !== '') {
        setPin(+lat, +lng, true);
        map.setView([+lat, +lng], zoom || 16);
      }
    };
    const setPin = (lat, lng, noWrite) => {
      if (!marker) {
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', () => { const p = marker.getLatLng(); write(p.lat, p.lng); setMsg('Pin moved.', 'ok'); });
      } else marker.setLatLng([lat, lng]);
      if (!noWrite) write(lat, lng);
    };
    const write = (lat, lng) => { latI.value = (+lat).toFixed(7); lngI.value = (+lng).toFixed(7); };

    const find = async () => {
      const url = link.value.trim();
      if (!url) { setMsg('Paste a Google Maps link first.', 'error'); return; }
      setMsg('Finding the location…');
      try {
        const j = await FST.getJSON(APP.api + 'resolve.php?url=' + encodeURIComponent(url));
        if (!j.ok) { setMsg(j.error || 'Could not read that link.', 'error'); place(null, null); return; }
        write(j.lat, j.lng);
        place(j.lat, j.lng);
        if (nameI && !nameI.value && j.name) nameI.value = j.name;
        if (addrI && !addrI.value && j.address) addrI.value = j.address;
        setMsg('Location found (' + j.method + '). Drag the pin if it needs fixing.', 'ok');
      } catch (e) { setMsg(e.message, 'error'); }
    };
    const findBtn = $('[data-find]');
    if (findBtn) findBtn.addEventListener('click', find);
    link && link.addEventListener('change', () => { if (link.value.trim()) find(); });
    [latI, lngI].forEach((i) => i.addEventListener('change', () => { if (latI.value && lngI.value) place(latI.value, lngI.value); }));
    // A picker inside a pop-up (data-lazy) draws its map only when the pop-up opens.
    root._picker = {
      show: (lat, lng) => {
        if (map) map.invalidateSize();
        if (marker && (lat == null || lng == null)) { map.removeLayer(marker); marker = null; }
        if (lat == null || lng == null) { place(null, null); if (map) map.setView([19.07, 72.84], 11); }
        else place(lat, lng);
        setTimeout(() => { if (map) { map.invalidateSize(); FST.repaint(map); } }, 120);
      },
    };
    if (!root.hasAttribute('data-lazy')) place(latI.value || null, lngI.value || null);
  };

  /* ------------------------------------------------ share my location bar */
  FST.store = {
    get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };
  /**
   * The "Share my location" bar used on the team leader and team member pages
   * (elements #share-bar, #share-title, #share-sub, #share-toggle).
   * opts: key (remembers on/off for this phone), offText, getToken() -> Promise<token>, forgetToken() when the server no longer knows it.
   */
  FST.shareBar = (opts) => {
    const $ = (id) => document.getElementById(id);
    const KEY_ON = opts.key;
    const sh = { watch: null, wake: null, lastSent: 0, pending: null, battery: null, lastOk: 0, acc: null, error: '' };
    if (navigator.getBattery) navigator.getBattery().then((b) => { sh.battery = b; }).catch(() => {});

    const paint = () => {
      const bar = $('share-bar'), on = sh.watch !== null;
      bar.classList.toggle('on', on && !sh.error);
      bar.classList.toggle('err', !!sh.error);
      $('share-toggle').textContent = on ? 'Stop' : 'Start';
      $('share-toggle').className = 'btn ' + (on ? '' : 'btn-primary');
      if (!on) {
        $('share-title').textContent = 'Location sharing is off';
        $('share-sub').textContent = sh.error || opts.offText;
      } else if (sh.error) {
        $('share-title').textContent = 'Location problem';
        $('share-sub').textContent = sh.error;
      } else if (sh.lastOk) {
        $('share-title').textContent = 'Sharing your location';
        $('share-sub').textContent = 'Last sent ' + FST.time(Math.floor(sh.lastOk / 1000)) + (sh.acc ? ' · accurate to ' + Math.round(sh.acc) + ' m' : '') + ' · keep this page open';
      } else {
        $('share-title').textContent = 'Starting…';
        $('share-sub').textContent = 'Waiting for GPS. Allow location if your phone asks.';
      }
    };
    const send = async (pos) => {
      const now = Date.now();
      if (now - sh.lastSent < 10000) { sh.pending = pos; return; }
      sh.lastSent = now;
      sh.pending = null;
      const c = pos.coords;
      try {
        const token = await opts.getToken();
        const res = await fetch(APP.api + 'location.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ t: token, lat: c.latitude, lng: c.longitude, acc: c.accuracy, speed: c.speed, heading: c.heading, ts: pos.timestamp,
            battery: sh.battery ? Math.round(sh.battery.level * 100) : null, charging: sh.battery ? sh.battery.charging : null }),
        });
        if (res.status === 404) { opts.forgetToken(); sh.lastSent = 0; throw new Error('Reconnecting…'); }
        const j = await res.json();
        if (!res.ok) throw new Error(j.error || 'Could not send');
        sh.lastOk = now;
        sh.acc = c.accuracy;
        sh.error = '';
      } catch (e) {
        sh.error = e.message + ' (will retry)';
        sh.lastSent = 0;
      }
      paint();
    };
    setInterval(() => { if (sh.pending) send(sh.pending); }, 3000);
    // A phone standing still gets no new positions from watchPosition, so ask for one every 30 s:
    // the car stays "Live" while parked at a station and the battery level stays current.
    setInterval(() => {
      if (sh.watch === null || document.hidden) return;
      navigator.geolocation.getCurrentPosition(send, () => {}, { enableHighAccuracy: true, maximumAge: 0, timeout: 25000 });
    }, 30000);
    const pingBattery = async () => {
      if (sh.watch === null || !sh.battery) return;
      try {
        const token = await opts.getToken();
        await fetch(APP.api + 'location.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ t: token, ping: 1, battery: Math.round(sh.battery.level * 100), charging: sh.battery.charging }) });
      } catch (e) { /* next update will carry it */ }
    };
    if (navigator.getBattery) navigator.getBattery().then((b) => {
      b.addEventListener('levelchange', pingBattery);
      b.addEventListener('chargingchange', pingBattery);
    }).catch(() => {});
    const keepAwake = async () => {
      try { if ('wakeLock' in navigator && !sh.wake) { sh.wake = await navigator.wakeLock.request('screen'); sh.wake.addEventListener('release', () => { sh.wake = null; }); } } catch (e) { /* not supported */ }
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden && sh.watch !== null) keepAwake(); });
    const start = async () => {
      if (!('geolocation' in navigator)) { sh.error = 'This browser cannot share location.'; paint(); return; }
      if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        sh.error = 'Location needs the https:// address of this site.'; paint(); return;
      }
      sh.error = '';
      try { await opts.getToken(); } catch (e) { sh.error = e.message; paint(); return; }
      sh.watch = navigator.geolocation.watchPosition(send, (err) => {
        sh.error = err.code === 1 ? 'Location permission is blocked. Allow location for this site in your browser settings.' : 'GPS: ' + err.message;
        paint();
      }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
      FST.store.set(KEY_ON, '1');
      keepAwake();
      paint();
    };
    const stop = () => {
      if (sh.watch !== null) navigator.geolocation.clearWatch(sh.watch);
      sh.watch = null;
      if (sh.wake) { sh.wake.release().catch(() => {}); sh.wake = null; }
      FST.store.set(KEY_ON, null);
      paint();
    };
    let toggling = false;
    $('share-toggle').addEventListener('click', async () => {
      if (toggling) return;
      toggling = true;
      try { await (sh.watch === null ? start() : stop()); } finally { toggling = false; }
    });
    // Resume after a reload if sharing was on and permission is already given.
    if (FST.store.get(KEY_ON) === '1') {
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then((p) => { if (p.state === 'granted') start(); else paint(); }).catch(() => paint());
      } else start();
    }
    paint();
    return { start, stop, isOn: () => sh.watch !== null };
  };

  /* ------------------------------------------------ same-looking dropdowns */
  /**
   * The browser draws a <select>'s open list in its own style (blue highlight on Windows), which CSS can't
   * change. Like the attendance system, each select is hidden (it still submits with the form) and a styled
   * button + list is shown instead. Options marked hidden are left out of the list.
   */
  let openSel = null;
  const closeSel = () => {
    if (!openSel) return;
    openSel.wrap.classList.remove('open');
    openSel.btn.setAttribute('aria-expanded', 'false');
    openSel.menu.remove();
    openSel = null;
  };
  FST.syncSelect = (sel) => {
    const w = sel && sel._fstSel;
    if (!w) return;
    const o = sel.options[sel.selectedIndex];
    w.text.textContent = o ? o.textContent : '';
    w.btn.classList.toggle('is-placeholder', !sel.value);
    w.btn.disabled = sel.disabled;
  };
  FST.enhanceSelect = (sel) => {
    if (sel._fstSel || sel.multiple || sel.dataset.native !== undefined) return;
    const wrap = document.createElement('span');
    wrap.className = 'sel-wrap';
    const small = sel.closest('.plan-item, .leaflet-popup, .share-bar') || sel.classList.contains('sm');
    if (sel.style.width) wrap.style.width = sel.style.width;
    if (sel.style.minWidth) wrap.style.minWidth = sel.style.minWidth;
    if (sel.style.maxWidth) wrap.style.maxWidth = sel.style.maxWidth;
    if (sel.style.flex) wrap.style.flex = sel.style.flex;
    if (sel.classList.contains('hidden')) wrap.classList.add('hidden');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sel-btn' + (small ? ' sm' : '');
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (sel.getAttribute('aria-label')) btn.setAttribute('aria-label', sel.getAttribute('aria-label'));
    if (sel.id) { const lbl = document.querySelector('label[for="' + sel.id + '"]'); if (lbl) lbl.addEventListener('click', (e) => { e.preventDefault(); btn.focus(); }); }
    const text = document.createElement('span');
    text.className = 'sel-text';
    btn.appendChild(text);
    btn.insertAdjacentHTML('beforeend', '<i class="bi bi-chevron-down sel-chev"></i>');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    wrap.appendChild(btn);
    sel.classList.add('sel-native');
    sel.tabIndex = -1;
    sel._fstSel = { wrap, btn, text };
    sel.addEventListener('change', () => FST.syncSelect(sel));
    sel.addEventListener('fst:sync', () => FST.syncSelect(sel));
    // keep the "hidden" class of the select and the box in step (team page shows/hides the car picker)
    new MutationObserver(() => wrap.classList.toggle('hidden', sel.classList.contains('hidden'))).observe(sel, { attributes: true, attributeFilter: ['class'] });
    FST.syncSelect(sel);

    const choose = (opt) => {
      if (sel.value !== opt.value) {
        sel.value = opt.value;
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      closeSel();
      btn.focus();
    };
    const open = () => {
      if (openSel && openSel.sel === sel) { closeSel(); return; }
      closeSel();
      const menu = document.createElement('ul');
      menu.className = 'sel-menu';
      menu.setAttribute('role', 'listbox');
      [...sel.options].forEach((o) => {
        if (o.hidden) return;
        const li = document.createElement('li');
        li.className = 'sel-item' + (o.value === sel.value ? ' active' : '') + (o.disabled ? ' disabled' : '') + (!o.value ? ' empty' : '');
        li.setAttribute('role', 'option');
        li.textContent = o.textContent;
        li.addEventListener('click', (e) => { e.stopPropagation(); choose(o); });
        li._opt = o;
        menu.appendChild(li);
      });
      (sel.closest('dialog') || document.body).appendChild(menu);   // inside a pop-up the list must live in the pop-up to show above it
      // fixed position under (or above) the box, so scrolling panels and map popups can't clip it
      const r = btn.getBoundingClientRect();
      const below = window.innerHeight - r.bottom, h = Math.min(menu.scrollHeight, 280);
      menu.style.minWidth = r.width + 'px';
      menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - menu.offsetWidth - 8)) + 'px';
      menu.style.top = (below < h + 12 && r.top > below ? r.top - h - 6 : r.bottom + 6) + 'px';
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      openSel = { sel, wrap, btn, menu };
      const act = menu.querySelector('.active') || menu.querySelector('.sel-item:not(.disabled)');
      if (act) { act.classList.add('focus'); act.scrollIntoView({ block: 'nearest' }); }
    };
    btn.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); open(); });
    btn.addEventListener('keydown', (e) => {
      const m = openSel && openSel.sel === sel ? openSel.menu : null;
      if (!m) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); open(); }
        return;
      }
      const items = [...m.querySelectorAll('.sel-item:not(.disabled)')];
      let i = items.findIndex((x) => x.classList.contains('focus'));
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (i >= 0) items[i].classList.remove('focus');
        i = Math.max(0, Math.min(items.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
        items[i].classList.add('focus');
        items[i].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (items[i]) choose(items[i]._opt);
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        closeSel();
      }
    });
  };
  document.addEventListener('click', (e) => { if (openSel && !e.target.closest('.sel-menu')) closeSel(); });
  window.addEventListener('resize', closeSel);
  document.addEventListener('scroll', (e) => { if (openSel && !(e.target.closest && e.target.closest('.sel-menu'))) closeSel(); }, true);
  FST.enhanceSelects = (root) => (root || document).querySelectorAll('select').forEach(FST.enhanceSelect);

  document.addEventListener('DOMContentLoaded', () => {
    FST.enhanceSelects();
    // Dropdowns added later (plan page, map popups, team page) get the same look.
    new MutationObserver((muts) => {
      muts.forEach((mu) => mu.addedNodes.forEach((n) => {
        if (n.nodeType !== 1) return;
        if (n.tagName === 'SELECT') FST.enhanceSelect(n);
        else if (n.querySelectorAll) n.querySelectorAll('select').forEach(FST.enhanceSelect);
      }));
    }).observe(document.body, { childList: true, subtree: true });
    document.querySelectorAll('[data-location-picker]').forEach((el) => FST.locationPicker(el));
    document.querySelectorAll('[data-confirm]').forEach((el) =>
      el.addEventListener(el.tagName === 'FORM' ? 'submit' : 'click', (e) => { if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault(); }));
    document.querySelectorAll('[data-copy]').forEach((el) =>
      el.addEventListener('click', () => {
        const text = el.getAttribute('data-copy');
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(
          () => FST.toast('Copied', 'ok'),
          () => window.prompt('Copy this:', text));
      }));

    // Every form submits once: a second tap while the page is saving is ignored.
    document.addEventListener('submit', (e) => {
      const f = e.target;
      if (e.defaultPrevented) return;
      if (f.dataset.busy) { e.preventDefault(); return; }
      f.dataset.busy = '1';
      const sb = e.submitter;
      setTimeout(() => {
        if (sb) sb.classList.add('is-busy');
        f.querySelectorAll('button, input[type=submit]').forEach((b) => { b.disabled = true; });
      }, 0);
    });
    window.addEventListener('pageshow', () => document.querySelectorAll('form[data-busy]').forEach((f) => {
      delete f.dataset.busy;
      f.querySelectorAll('button, input[type=submit]').forEach((b) => { b.disabled = false; b.classList.remove('is-busy'); });
    }));

    // Dark / light mode (remembered on this phone), same as the attendance system.
    const themeBtn = document.getElementById('themeToggle');
    const paintThemeIcon = () => {
      if (themeBtn) themeBtn.innerHTML = '<i class="bi bi-' + (document.documentElement.getAttribute('data-theme') === 'dark' ? 'sun' : 'moon-stars') + '"></i>';
    };
    paintThemeIcon();
    themeBtn && themeBtn.addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('fst_theme', next); } catch (e) { /* private mode */ }
      paintThemeIcon();
    });

    // Admin sidebar on phones.
    const sb = document.getElementById('appSidebar'), bd = document.getElementById('sidebarBackdrop'), tg = document.getElementById('sidebarToggle');
    const closeSb = () => { sb && sb.classList.remove('open'); bd && bd.classList.remove('show'); };
    tg && tg.addEventListener('click', () => { sb.classList.toggle('open'); bd && bd.classList.toggle('show'); });
    bd && bd.addEventListener('click', closeSb);
  });

  /* ------------------------------------------------- installable app (PWA) */
  if ('serviceWorker' in navigator && APP.sw && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', () => navigator.serviceWorker.register(APP.sw).catch(() => {}));
  }
  let installEvent = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    installEvent = e;
    const b = document.getElementById('installBtn');
    if (b) b.classList.add('show');
  });
  document.addEventListener('click', async (e) => {
    if (!e.target.closest('#installBtn') || !installEvent) return;
    installEvent.prompt();
    await installEvent.userChoice.catch(() => {});
    installEvent = null;
    document.getElementById('installBtn').classList.remove('show');
  });
})();
