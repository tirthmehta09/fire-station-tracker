/* Live dashboard: admin (with override buttons, phones, alerts) and public read-only view. */
(function () {
  'use strict';
  const isAdmin = APP.role === 'admin';
  const esc = FST.esc;
  const m = FST.createMap(document.getElementById('map'));
  const $ = (id) => document.getElementById(id);
  let state = null;
  let filter = 'all';
  let selectedTeam = null;

  // s is a station, or a shared station as one team sees it (s.for_team): then the buttons change only that team's visit.
  const statusButtons = (s) => {
    if (!isAdmin || !s.active || (s.shared && !s.for_team)) return '';
    const b = [];
    const st = 'data-st="' + s.id + '"' + (s.for_team ? ' data-team="' + s.for_team + '"' : '');
    if (s.status !== 'reached') b.push('<button class="btn btn-sm btn-warn" ' + st + ' data-to="reached">Reached</button>');
    if (s.status !== 'done') b.push('<button class="btn btn-sm btn-ok" ' + st + ' data-to="done">Delivered</button>');
    if (s.status !== 'pending') b.push('<button class="btn btn-sm btn-ghost" ' + st + ' data-to="' + (s.status === 'done' ? 'reached' : 'pending') + '" data-undo="1"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>');
    return b.join('');
  };
  const actions = (s) => {
    if (!isAdmin || !s.active) return '';
    if (s.shared && !s.for_team) return '<div class="st-act small muted">Tap a team above, or this stop on the map, to mark each team.</div>';
    return '<div class="st-act">' + statusButtons(s) + '</div>';
  };

  function renderAlerts() {
    const el = $('alerts');
    if (!el || !isAdmin) return;
    const a = [];
    const newPhones = (state.devices || []).filter((d) => !d.approved);
    if (newPhones.length) a.push('<b>' + newPhones.length + ' new phone(s)</b> are sending location but have no team. <a href="teams.php">Assign them</a>.');
    const noLoc = state.stations.filter((s) => s.active && s.lat == null);
    if (noLoc.length) a.push('<b>' + noLoc.length + ' station(s)</b> have no location: ' + noLoc.map((s) => esc(s.name)).join(', ') + '. <a href="stations.php">Fix</a>');
    const unassigned = state.stations.filter((s) => s.active && !s.team_id);
    if (unassigned.length) a.push('<b>' + unassigned.length + ' station(s)</b> have no team yet. <a href="plan.php">Plan</a>');
    const lost = state.teams.filter((t) => t.status === 'on_route' && (!t.last_fix || state.now - t.last_fix > 300));
    if (lost.length) a.push('No location for 5+ min from: <b>' + lost.map((t) => esc(t.name)).join(', ') + '</b>. Call the team leader.');
    const lowBat = (state.devices || []).filter((d) => d.approved && d.team_id && d.battery != null && d.battery < 20 && !d.charging && d.fix_at && state.now - d.fix_at < 900);
    if (lowBat.length) a.push('Low battery: ' + lowBat.map((d) => esc(d.name) + ' ' + Math.round(d.battery) + '%').join(', '));
    el.innerHTML = a.map((x) => '<div class="notice" style="margin-top:8px">' + x + '</div>').join('');
  }

  function renderPhones() {
    const el = $('phones');
    if (!el || !isAdmin) return;
    const teams = FST.teamById(state);
    const cars = {};
    (state.cars || []).forEach((c) => (cars[c.id] = c));
    const list = (state.devices || []).filter((d) => d.approved);
    if (!list.length) {
      el.innerHTML = '<div class="empty small">No phones yet. Add them on the <a href="teams.php">Teams page</a>.</div>';
      return;
    }
    el.innerHTML = list.map((d) => {
      const t = d.team_id ? teams[d.team_id] : null;
      const f = FST.freshness(d.fix_at, state.now);
      const label = !d.fix_at ? 'Never' : f === 'live' ? 'Live' : f === 'stale' ? 'Delayed' : 'Offline';
      const roleTag = d.role ? ' <span class="pill plain ' + (d.role === 'leader' ? 'reached' : 'pending') + '">' + (d.role === 'leader' ? 'Leader' : 'Member') + '</span>' : '';
      const tel = d.person_phone ? ' · <a href="tel:' + esc(d.person_phone) + '">' + esc(d.person_phone) + '</a>' : '';
      return '<div class="st-row" style="align-items:center" data-device-row><span class="dot" style="--c:' + (t ? t.color : '#94a3b8') + '"></span>' +
        '<div class="st-main"><div class="st-name">' + esc(d.name) + roleTag + '</div><div class="st-sub">' + (t ? esc(t.name) : 'No team') + (d.car_id && cars[d.car_id] ? ' · ' + esc(cars[d.car_id].label) : '') + tel +
        ' · ' + FST.phoneSource(d) + (d.fix_at ? ' · ' + FST.ago(d.fix_at, state.now) : '') + (d.accuracy ? ' · ±' + Math.round(d.accuracy) + ' m' : '') + '</div></div>' +
        '<span class="pill ' + (d.fix_at ? f : 'pending') + '">' + label + (d.battery != null ? ' · ' + Math.round(d.battery) + '%' + (d.charging ? '⚡' : '') : '') + '</span>' +
        FST.deletePhoneButton(d) + '</div>';
    }).join('');
  }
  document.addEventListener('fst:device-deleted', async () => {
    try { state = await FST.getJSON(APP.api + 'state.php'); render(); } catch (e) { /* next poll catches up */ }
  });

  /* ---- phones: the panel is a pull-up sheet (peek / half / full) like Google Maps ---- */
  const layout = $('live-layout'), panel = $('panel'), handle = $('sheet-handle');
  const phone = () => window.matchMedia('(max-width: 991.98px)').matches;
  let level = 0, sheetH = 0;
  const snaps = () => { const H = layout.clientHeight; return [Math.min(304, Math.round(H * 0.46)), Math.round(H * 0.6), H - 56]; };
  const setH = (h) => { sheetH = h; layout.style.setProperty('--sheet-h', h + 'px'); };
  const snapTo = (i) => { level = Math.max(0, Math.min(2, i)); setH(snaps()[level]); panel.scrollTop = level === 0 ? 0 : panel.scrollTop; };
  if (phone()) snapTo(0);
  window.addEventListener('resize', () => { if (phone()) snapTo(level); else layout.style.removeProperty('--sheet-h'); });
  let drag = null;
  const startDrag = (e) => {
    if (!phone() || (level === 2 && panel.scrollTop > 0 && e.currentTarget !== handle)) return;
    drag = { y: e.clientY, h: sheetH, moved: false };
    panel.classList.add('dragging');
    e.currentTarget.setPointerCapture && e.currentTarget.setPointerCapture(e.pointerId);
  };
  const moveDrag = (e) => {
    if (!drag) return;
    const dy = drag.y - e.clientY;
    if (Math.abs(dy) > 6) drag.moved = true;
    if (drag.moved) setH(Math.max(120, Math.min(layout.clientHeight - 40, drag.h + dy)));
  };
  const endDrag = () => {
    if (!drag) return;
    panel.classList.remove('dragging');
    if (!drag.moved) snapTo(level === 2 ? 0 : level + 1);
    else { const s = snaps(); let best = 0; s.forEach((v, i) => { if (Math.abs(v - sheetH) < Math.abs(s[best] - sheetH)) best = i; }); snapTo(best); }
    drag = null;
  };
  [handle, $('hero-live')].forEach((el) => {
    el.addEventListener('pointerdown', startDrag);
    el.addEventListener('pointermove', moveDrag);
    el.addEventListener('pointerup', endDrag);
    el.addEventListener('pointercancel', endDrag);
  });
  handle.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); snapTo(level === 2 ? 0 : level + 1); } });

  // Keep markers clear of the panel when zooming to fit.
  m.fitPad = () => phone()
    ? { paddingTopLeft: [40, 40], paddingBottomRight: [40, (sheetH || 304) + 24] }
    : { paddingTopLeft: [60, 40], paddingBottomRight: [460, 40] };
  m.onFit = () => FST.fit(m, state, selectedTeam);

  /* ---- live pop-ups for new updates ---- */
  let lastEvent = null;
  function announce() {
    const newest = state.events.length ? state.events[0].id : 0;
    if (lastEvent !== null && newest > lastEvent) {
      const teams = FST.teamById(state);
      state.events.filter((e) => e.id > lastEvent && e.type !== 'device').slice(0, 3).reverse()
        .forEach((e) => FST.mapToast($('map-toast'), e, e.team_id ? teams[e.team_id] : null));
    }
    lastEvent = newest;
  }

  function render() {
    FST.drawState(m, state, { stationExtra: isAdmin ? statusButtons : null, stopExtra: isAdmin ? statusButtons : null, filterTeam: selectedTeam });
    FST.renderRing($('summary'), state);
    announce();
    FST.renderTeams($('teams'), state, { private: isAdmin, selected: selectedTeam });
    FST.renderStations($('stations'), state, { filter, actions: isAdmin ? actions : null, teamId: selectedTeam });
    FST.renderFeed($('feed'), state, isAdmin ? 25 : 15);
    $('st-count').textContent = selectedTeam ? 'for ' + (FST.teamById(state)[selectedTeam] || {}).name : '';
    renderAlerts();
    renderPhones();
  }

  FST.poll((s) => { state = s; render(); }, (ok) => FST.setStatusBadge($('live-status'), ok, state && state.now), isAdmin ? 8000 : 12000);

  document.addEventListener('click', async (e) => {
    const chip = e.target.closest('.chip[data-f]');
    if (chip) {
      filter = chip.dataset.f;
      document.querySelectorAll('.chip[data-f]').forEach((c) => c.classList.toggle('on', c === chip));
      render();
      return;
    }
    const card = e.target.closest('.team-card');
    if (card && !e.target.closest('a')) {
      const id = +card.dataset.team;
      selectedTeam = selectedTeam === id ? null : id;
      m.focusTeam = selectedTeam;
      render();
      if (phone()) snapTo(0);
      FST.fit(m, state, selectedTeam);
      return;
    }
    const btn = e.target.closest('[data-st]');
    if (btn) {
      if (FST._inflight > 0 || btn.disabled) return;
      btn.disabled = true;
      btn.classList.add('is-busy');
      try {
        await FST.api('station_status', { id: +btn.dataset.st, status: btn.dataset.to, team_id: btn.dataset.team ? +btn.dataset.team : null });
        FST.toast(btn.dataset.undo ? 'Undone' : 'Updated', 'ok');
        state = await FST.getJSON(APP.api + 'state.php');
        render();
      } catch (err) { FST.toast(err.message, 'error'); btn.disabled = false; }
      return;
    }
    const row = e.target.closest('.st-row[data-station]');
    if (row && !e.target.closest('a,button')) {
      const mk = m.stations[row.dataset.station];
      if (mk) {
        if (phone()) snapTo(0);
        m.map.setView(mk.getLatLng(), 16);
        mk.openPopup();
      }
    }
  });
})();
