/* Team member page: share this phone's location and follow the team (read only). */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const esc = FST.esc;
  const TEAM = APP.teamId;
  const m = FST.createMap($('map'), { scrollWheelZoom: false });
  let state = null;
  m.onFit = () => state && FST.fit(m, state, TEAM);

  FST.shareBar({
    key: 'fst_member_share_' + APP.deviceId,
    offText: 'Tap Start so the organisers can see your car.',
    getToken: async () => APP.deviceToken,
    forgetToken: () => { FST.toast('Please log in again.', 'error'); setTimeout(() => { location.href = APP.base + 'logout.php'; }, 1500); },
  });

  function render() {
    const team = FST.teamById(state)[TEAM];
    if (!team) return;
    const c = team.counts;
    $('team-status').className = 'pill t-' + team.status;
    $('team-status').textContent = FST.TEAM_STATUS[team.status];
    $('hero-bar').innerHTML = FST.bar(c).replace(/^<div class="bar">|<\/div>$/g, '');
    $('n-done').textContent = c.done + '/' + c.total;
    $('n-left').textContent = c.total - c.done;
    if (team.status === 'returned') { $('n-eta').textContent = FST.time(team.returned_at); $('eta-label').textContent = 'Back at'; }
    else if (team.expected_return_at) { $('n-eta').textContent = FST.time(team.expected_return_at); $('eta-label').textContent = 'Leader says'; }
    else if (team.eta) { $('n-eta').textContent = FST.time(team.eta); $('eta-label').textContent = 'Estimated'; }
    else { $('n-eta').textContent = '–'; $('eta-label').textContent = 'Back by'; }

    // which car am I in (when the team has more than one)
    const me = (state.my_devices || []).find((d) => d.id === APP.deviceId);
    const cars = state.my_cars || [];
    const sel = $('share-car');
    if (cars.length < 1 || !me) sel.classList.add('hidden');
    else {
      sel.classList.remove('hidden');
      if (document.activeElement !== sel) {
        const html = '<option value="">Which car are you in?</option>' + cars.map((x) => '<option value="' + x.id + '"' + (me.car_id === x.id ? ' selected' : '') + '>' + esc(x.label + (x.plate ? ' · ' + x.plate : '')) + '</option>').join('');
        if (sel._opts !== html) { sel.innerHTML = html; sel._opts = html; FST.syncSelect(sel); }
      }
    }
    // is the Traccar app connected for this phone?
    const app = $('app-state');
    if (me && me.app_seen_at) {
      const f = FST.freshness(me.app_seen_at, state.now);
      app.className = 'pill plain ' + (f === 'live' ? 'live' : f === 'stale' ? 'stale' : 'offline');
      app.textContent = 'App ' + (f === 'live' ? 'connected' : 'last seen ' + FST.ago(me.app_seen_at, state.now));
    } else {
      app.className = 'pill plain pending';
      app.textContent = 'Not set up';
    }

    FST.renderStations($('my-stations'), state, { teamId: TEAM });
    FST.renderFeed($('feed'), state, 8);
    FST.drawState(m, state, { filterTeam: TEAM });
  }

  $('share-car').addEventListener('change', async (e) => {
    try {
      await FST.api('my_device', { token: APP.deviceToken, car_id: e.target.value ? +e.target.value : null });
      FST.toast('Saved', 'ok');
      state = await FST.getJSON(APP.api + 'state.php');
      render();
    } catch (err) { FST.toast(err.message, 'error'); }
  });

  FST.poll((s) => { state = s; render(); }, (ok) => { if (!ok) FST.toast('Connection lost. Retrying…', 'error'); }, 10000);
})();
