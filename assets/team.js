/* Team leader console: progress, stations, and sharing this phone's location for the team. */
(function () {
  'use strict';
  const esc = FST.esc;
  const $ = (id) => document.getElementById(id);
  const TEAM = APP.teamId;
  const m = FST.createMap($('map'), { scrollWheelZoom: false });
  let state = null;
  m.onFit = () => state && FST.fit(m, state, TEAM);

  const hhmm = (ts) => new Date(ts * 1000).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Kolkata' });
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  // The team's stops in route order, including the stops every team makes (e.g. first at Borivali, last at Byculla).
  function myStations() {
    return FST.teamStops(state, TEAM);
  }
  const everyTeam = (s) => s.shared ? '<span class="pill plain every"><i class="bi bi-people-fill"></i> Every team</span>' : '';
  function myCarPos() {
    const cars = state.vehicles.filter((v) => v.team_id === TEAM).sort((a, b) => b.fix_at - a.fix_at);
    return cars.length ? [cars[0].lat, cars[0].lng] : null;
  }

  function buttons(s, big) {
    const cls = big ? 'btn btn-lg' : 'btn btn-sm';
    const b = [];
    const nav = s.lat != null && s.status !== 'done' ? '<a class="' + cls + ' btn-primary" target="_blank" rel="noopener" href="' + FST.navUrl(s.lat, s.lng) + '"><i class="bi bi-sign-turn-right"></i> Navigate</a>' : '';
    const delivered = '<button class="' + cls + ' btn-ok" data-st="' + s.id + '" data-to="done"><i class="bi bi-check2-circle"></i> Delivered</button>';
    // At the station, "Delivered" is the main action; otherwise "Navigate" is.
    if (s.status === 'reached') b.push(delivered, nav);
    else b.push(nav);
    if (s.status === 'pending') b.push('<button class="' + cls + ' btn-warn" data-st="' + s.id + '" data-to="reached"><i class="bi bi-geo-alt"></i> Reached</button>', delivered);
    if (s.status !== 'pending') b.push('<button class="' + cls + ' btn-ghost" data-st="' + s.id + '" data-to="' + (s.status === 'done' ? 'reached' : 'pending') + '" data-undo="1"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>');
    if (s.phone && !big) b.push('<a class="' + cls + ' btn-ghost" href="tel:' + esc(s.phone) + '"><i class="bi bi-telephone"></i> Call</a>');
    return b.join('');
  }

  function render() {
    const team = FST.teamById(state)[TEAM];
    if (!team) return;
    const list = myStations();
    const c = team.counts;
    const pos = myCarPos();

    $('team-status').className = 'pill t-' + team.status;
    $('team-status').textContent = FST.TEAM_STATUS[team.status];
    $('hero-bar').innerHTML = FST.bar(c).replace(/^<div class="bar">|<\/div>$/g, '');
    $('n-done').textContent = c.done + '/' + c.total;
    $('n-left').textContent = c.total - c.done;
    if (team.status === 'returned') { $('n-eta').textContent = FST.time(team.returned_at); $('eta-label').textContent = 'Back at'; }
    else if (team.expected_return_at) { $('n-eta').textContent = FST.time(team.expected_return_at); $('eta-label').textContent = 'You said'; }
    else if (team.eta) { $('n-eta').textContent = FST.time(team.eta); $('eta-label').textContent = 'Estimated'; }
    else if (team.route_seconds) { $('n-eta').textContent = FST.dur(team.route_seconds); $('eta-label').textContent = 'Route time'; }
    else { $('n-eta').textContent = '–'; $('eta-label').textContent = 'Back by'; }

    let p = '';
    if (team.status === 'not_started') {
      p = '<button class="btn btn-primary btn-lg btn-block" data-team="on_route"><i class="bi bi-play-fill"></i> Start journey</button><div class="hint" style="text-align:center">This also happens on its own when your car leaves the start point.</div>';
    } else if (team.status === 'on_route' && c.total && c.done === c.total) {
      p = '<div class="notice ok" style="margin-bottom:8px">All stations delivered. Well done! 🎉</div><button class="btn btn-ok btn-lg btn-block" data-team="returned"><i class="bi bi-house-check"></i> We are back at base</button>';
    } else if (team.status === 'returned') {
      p = '<div class="notice ok">Back at base at <b>' + FST.time(team.returned_at) + '</b>. Thank you!</div><button class="btn btn-ghost btn-sm" data-team="on_route" style="margin-top:6px">Undo – we are still out</button>';
    }
    $('primary').innerHTML = p;

    const here = list.find((s) => s.status === 'reached');
    const next = here || list.find((s) => s.status === 'pending');
    const nx = $('next');
    if (!list.length) {
      nx.innerHTML = '<div class="empty">No stations assigned to your team yet. The admin will add them.</div>';
    } else if (!next) {
      nx.innerHTML = '<div class="eyebrow">All done</div><h2>Every station delivered ✓</h2><p class="muted" style="margin:0">Head back to ' + esc(state.event.base.name) + '.</p>' +
        '<div class="btn-grid" style="grid-template-columns:1fr"><a class="btn btn-lg btn-primary" target="_blank" rel="noopener" href="' + FST.navUrl(state.event.base.lat, state.event.base.lng) + '"><i class="bi bi-house"></i> Navigate to base</a></div>';
    } else {
      const d = pos && next.lat != null ? FST.dist(pos, [next.lat, next.lng]) : null;
      nx.innerHTML = '<div class="eyebrow">' + (here ? 'You have reached' : 'Next suggested stop') + (next.order ? ' · #' + next.order : '') + '</div>' +
        '<h2>' + esc(next.name) + '</h2>' + (next.shared ? '<div class="small" style="margin-bottom:4px">' + everyTeam(next) + ' ' + (next.shared === 'first' ? 'All teams start here together' : 'All teams end here before going back') + '</div>' : '') +
        '<div class="muted small">' + esc([next.area, next.address].filter(Boolean).join(' · ')) + '</div>' +
        '<div class="small" style="margin-top:6px">' + [d != null ? FST.km(d * 1.3) + ' away' : '', next.boxes ? '<b>' + next.boxes + '</b> sweet boxes' : '', next.notes ? esc(next.notes) : ''].filter(Boolean).join(' · ') + '</div>' +
        '<div class="btn-grid">' + buttons(next, true) + '</div>';
    }

    $('my-stations').innerHTML = list.length ? list.map((s) => {
      const d = pos && s.lat != null ? FST.dist(pos, [s.lat, s.lng]) : null;
      const sub = [s.area, d != null && s.status !== 'done' ? FST.km(d * 1.3) : '', s.reached_at ? 'Reached ' + FST.time(s.reached_at) : '', s.done_at ? 'Delivered ' + FST.time(s.done_at) : '', s.boxes ? s.boxes + ' boxes' : ''].filter(Boolean);
      return '<div class="st-row"><span class="badge-num ' + (s.status !== 'pending' ? s.status : '') + '" style="--c:' + team.color + '">' + (s.status === 'done' ? '✓' : s.order || '–') + '</span>' +
        '<div class="st-main"><div class="row-between"><span class="st-name">' + esc(s.name) + '</span><span class="pill ' + s.status + '">' + FST.STATUS[s.status] + '</span></div>' +
        '<div class="st-sub">' + (s.shared ? everyTeam(s) + ' ' : '') + esc(sub.join(' · ')) + '</div><div class="st-act">' + buttons(s, false) + '</div></div></div>';
    }).join('') : '<div class="empty">Nothing yet.</div>';

    const left = list.filter((s) => s.status !== 'done' && s.lat != null).slice(0, 9);
    const base = state.event.base;
    let url = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' + base.lat + ',' + base.lng;
    if (left.length) url += '&waypoints=' + encodeURIComponent(left.map((s) => s.lat + ',' + s.lng).join('|'));
    $('route-btn').href = url;

    const inp = $('eta-input');
    if (document.activeElement !== inp) inp.value = team.expected_return_at ? hhmm(team.expected_return_at) : '';
    $('eta-hint').innerHTML = team.eta ? 'Our estimate from your position: <b>' + FST.time(team.eta) + '</b>. Everyone on the live map sees your time.' : 'Everyone on the live map sees this time.';

    const phones = state.my_devices || [];
    $('my-phones').innerHTML = phones.length ? phones.map((d) => {
      const f = FST.freshness(d.fix_at, state.now);
      const mine = share.deviceId && d.id === share.deviceId;
      return '<div class="st-row" style="align-items:center"><div class="st-main"><div class="st-name">' + esc(d.name) + (mine ? ' <span class="pill plain live">This phone</span>' : '') + (d.role ? ' <span class="pill plain ' + (d.role === 'leader' ? 'reached' : 'pending') + '">' + (d.role === 'leader' ? 'Leader' : 'Member') + '</span>' : '') + '</div><div class="st-sub">' + esc([d.person_phone, d.car, FST.phoneSource(d), d.fix_at ? FST.ago(d.fix_at, state.now) : 'not connected yet'].filter(Boolean).join(' · ')) + '</div></div>' +
        '<span class="pill ' + (d.fix_at ? f : 'pending') + '">' + (!d.fix_at ? 'Waiting' : f === 'live' ? 'Live' : f === 'stale' ? 'Delayed' : 'Offline') + (d.battery != null ? ' · ' + Math.round(d.battery) + '%' : '') + '</span></div>';
    }).join('') : '<div class="notice">No phone is sharing your team\'s location yet. Tap <b>Start</b> above.</div>';

    renderShareCar();
    FST.renderFeed($('feed'), state, 8);
    FST.drawState(m, state, { filterTeam: TEAM });
  }

  /* ------------------------------------------ share this phone's location */
  // The leader's phone is registered once (with the leader's name and mobile) and remembered on this phone.
  const KEY_TOKEN = 'fst_dev_' + TEAM, KEY_ID = 'fst_devid_' + TEAM;
  const share = { deviceId: +(store.get(KEY_ID) || 0) || null };
  FST.shareBar({
    key: 'fst_share_' + TEAM,
    offText: 'Tap Start so everyone can see where your team is.',
    getToken: async () => {
      const token = store.get(KEY_TOKEN);
      if (token) return token;
      const j = await FST.api('web_device', { name: (APP.leaderName ? APP.leaderName + "'s phone" : 'Leader phone') });
      store.set(KEY_TOKEN, j.token);
      store.set(KEY_ID, String(j.id));
      share.deviceId = j.id;
      return j.token;
    },
    forgetToken: () => { store.set(KEY_TOKEN, null); store.set(KEY_ID, null); share.deviceId = null; },
  });

  function renderShareCar() {
    const sel = $('share-car');
    const cars = (state && state.my_cars) || [];
    const token = store.get(KEY_TOKEN);
    const mine = token && state && (state.my_devices || []).find((d) => d.id === share.deviceId);
    if (cars.length < 2 || !token || !mine) { sel.classList.add('hidden'); return; }
    sel.classList.remove('hidden');
    if (document.activeElement === sel) return;
    const html = '<option value="">Which car is this phone in?</option>' + cars.map((c) => '<option value="' + c.id + '"' + (mine.car_id === c.id ? ' selected' : '') + '>' + esc(c.label + (c.plate ? ' · ' + c.plate : '')) + '</option>').join('');
    if (sel._opts !== html) { sel.innerHTML = html; sel._opts = html; FST.syncSelect(sel); }
  }
  $('share-car').addEventListener('change', async (e) => {
    try {
      await FST.api('my_device', { token: store.get(KEY_TOKEN), car_id: e.target.value ? +e.target.value : null });
      FST.toast('Saved', 'ok');
      await refresh();
    } catch (err) { FST.toast(err.message, 'error'); }
  });

  /* ---------------------------------------------------------------- data */
  function onState(s) {
    state = s;
    render();
  }
  FST.poll(onState, (ok) => { if (!ok) FST.toast('Connection lost. Retrying…', 'error'); }, 8000);
  async function refresh() { onState(await FST.getJSON(APP.api + 'state.php')); }

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('button[data-st], button[data-team]');
    if (!b || b.disabled || FST._inflight > 0) return;
    if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
    b.disabled = true;
    b.classList.add('is-busy');
    try {
      if (b.dataset.st) {
        await FST.api('station_status', { id: +b.dataset.st, status: b.dataset.to });
        FST.toast(b.dataset.undo ? (b.dataset.to === 'reached' ? 'Undone: back to reached' : 'Undone: back to pending') : b.dataset.to === 'done' ? 'Marked delivered ✓' : 'Marked reached', 'ok');
      } else {
        await FST.api('team_status', { team_id: TEAM, status: b.dataset.team });
        FST.toast('Saved', 'ok');
      }
      await refresh();
    } catch (err) { FST.toast(err.message, 'error'); b.disabled = false; }
  });

  $('eta-save').addEventListener('click', async () => {
    if (FST._inflight > 0) return;
    $('eta-save').disabled = true;
    try {
      await FST.api('team_eta', { team_id: TEAM, time: $('eta-input').value });
      FST.toast($('eta-input').value ? 'Return time saved' : 'Return time cleared', 'ok');
      $('eta-input').blur();
      await refresh();
    } catch (err) { FST.toast(err.message, 'error'); }
    $('eta-save').disabled = false;
  });
})();
