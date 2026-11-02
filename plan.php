<?php
/** Admin: which team goes where, and the suggested order (teams may still visit in any order). */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

require_admin();
page_head('Plan', 'plan', ['map' => true]);
?>
<main class="plan">
  <div class="plan-map">
    <div id="map"></div>
  </div>
  <aside class="plan-panel">
    <div class="row-between" style="margin-bottom:6px">
      <h1 style="margin:0">Route plan</h1>
      <a class="btn btn-sm" href="<?= h(app_url('teams.php?add=1#add')) ?>">+ Team</a>
    </div>
    <p class="small muted" style="margin:0 0 10px">Give each station a team, or pick <b>Every team · first stop</b> / <b>last stop</b> for a stop all teams make. The numbers are the <b>suggested</b> order; teams can still go in any order on the day. <b>Best order</b> sorts a team's own stops into the shortest route between its first and last stops.</p>
    <?php if (!setting('preset_1nov_at')): ?>
    <div class="notice info" style="margin-bottom:10px"><b>Your 1 Nov list is ready:</b> 26 stations going, Borivali first and Byculla last for every team. <a href="<?= h(app_url('preset.php')) ?>">Check and apply it</a></div>
    <?php endif; ?>
    <div id="plan"></div>
  </aside>
</main>
<script>
(function () {
  const m = FST.createMap(document.getElementById('map'));
  const routeLayer = L.layerGroup().addTo(m.map);
  let state = null;
  const esc = FST.esc;

  async function load() {
    state = await FST.getJSON(APP.api + 'state.php');
    render();
  }

  function teamOptions(selected) {
    return '<option value="">No team</option>' +
      '<option value="first"' + (selected === 'first' ? ' selected' : '') + '>Every team · first stop</option>' +
      '<option value="last"' + (selected === 'last' ? ' selected' : '') + '>Every team · last stop</option>' +
      state.teams.map((t) => '<option value="' + t.id + '"' + (t.id === selected ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('');
  }
  const baseRow = (label) => '<div class="plan-base"><i class="bi bi-house-door-fill"></i><span>' + label + ' <b>' + esc(state.event.base.name) + '</b></span></div>';

  function render() {
    const teams = FST.teamById(state);
    const active = state.stations.filter((s) => s.active);
    const el = document.getElementById('plan');
    let html = '';
    if (!state.teams.length) html += '<div class="notice">Create teams first on the <a href="teams.php">Teams page</a>.</div>';

    state.teams.forEach((t) => {
      // Route order: start point, first stop for every team, the team's own stops, last stop for every team, back to start.
      const list = FST.teamStops(state, t.id);
      const own = list.filter((s) => !s.shared);
      const skipped = active.filter((s) => s.shared && (s.visits || []).some((v) => v.team_id === t.id && v.skip));
      let dist = 0, prev = [state.event.base.lat, state.event.base.lng];
      list.forEach((s) => { if (s.lat != null) { dist += FST.dist(prev, [s.lat, s.lng]); prev = [s.lat, s.lng]; } });
      if (list.length) dist += FST.dist(prev, [state.event.base.lat, state.event.base.lng]);
      html += '<section class="plan-team" style="--c:' + t.color + '"><header><span class="dot" style="--c:' + t.color + '"></span><b>' + esc(t.name) + '</b>' +
        '<span class="small muted">' + list.length + ' stops' + (list.length ? ' · ~' + FST.km(dist * 1.35) + (t.route_seconds ? ' · ' + FST.dur(t.route_seconds) : '') : '') + '</span>' +
        (own.length > 1 ? '<button class="btn btn-sm" data-opt="' + t.id + '">Best order</button>' : '') + '</header>';
      if (list.length) html += baseRow('Start:');
      if (!own.length) html += '<div class="plan-empty">No stations of its own yet. Pick this team for stations below.</div>';
      list.forEach((s, i) => {
        const j = own.indexOf(s);
        const sub = esc(s.area || '') + (s.lat == null ? ' · <span style="color:#b91c1c">no location</span>' : '') + (s.status !== 'pending' ? ' · ' + FST.STATUS[s.status] : '');
        if (s.shared) {
          html += '<div class="plan-item shared" data-id="' + s.id + '"><span class="badge-num ' + (s.status !== 'pending' ? s.status : '') + '" style="--c:' + t.color + '">' + (i + 1) + '</span>' +
            '<div class="pi-name"><b>' + esc(s.name) + '</b><small><i class="bi bi-people-fill"></i> Every team' + (sub ? ' · ' + sub : '') + '</small></div>' +
            '<button class="btn btn-sm btn-ghost" data-skip="' + s.id + '" data-team="' + t.id + '" title="This team does not go here">Skip</button>' +
            '<select data-assign="' + s.id + '" aria-label="Team">' + teamOptions(s.shared) + '</select></div>';
          return;
        }
        html += '<div class="plan-item" data-id="' + s.id + '"><span class="badge-num ' + (s.status !== 'pending' ? s.status : '') + '" style="--c:' + t.color + '">' + (i + 1) + '</span>' +
          '<div class="pi-name"><b>' + esc(s.name) + '</b><small>' + sub + '</small></div>' +
          '<button class="btn btn-sm btn-ghost btn-icon" data-up="' + s.id + '" title="Move up" aria-label="Move up"' + (j === 0 ? ' disabled' : '') + '>↑</button>' +
          '<button class="btn btn-sm btn-ghost btn-icon" data-down="' + s.id + '" title="Move down" aria-label="Move down"' + (j === own.length - 1 ? ' disabled' : '') + '>↓</button>' +
          '<select data-assign="' + s.id + '" aria-label="Team">' + teamOptions(t.id) + '</select></div>';
      });
      if (list.length) html += baseRow('Back to');
      skipped.forEach((s) => {
        html += '<div class="plan-skip">Not going to <b>' + esc(s.name) + '</b> <button class="btn btn-sm btn-ghost" data-unskip="' + s.id + '" data-team="' + t.id + '">Add back</button></div>';
      });
      html += '</section>';
    });

    const un = active.filter((s) => !s.team_id && !s.shared);
    html += '<div class="section-title">Not assigned (' + un.length + ')</div>';
    html += '<section class="plan-team">' + (un.length ? '' : '<div class="plan-empty">Every active station has a team. 🎉</div>');
    un.forEach((s) => {
      html += '<div class="plan-item" data-id="' + s.id + '"><span class="badge-num none">–</span><div class="pi-name"><b>' + esc(s.name) + '</b><small>' + esc(s.area || '') + (s.lat == null ? ' · <span style="color:#b91c1c">no location</span>' : '') + '</small></div>' +
        '<select data-assign="' + s.id + '" aria-label="Team">' + teamOptions(null) + '</select></div>';
    });
    html += '</section>';
    const off = state.stations.filter((s) => !s.active).length;
    if (off) html += '<p class="small muted" style="margin-top:10px">' + off + ' inactive station(s) hidden. Manage them on the <a href="stations.php">Stations page</a>.</p>';
    el.innerHTML = html;

    FST.drawState(m, state, {
      stationExtra: (s) => '<select data-assign="' + s.id + '" style="min-height:32px;padding:4px 8px;width:auto">' + teamOptions(s.shared || s.team_id) + '</select>',
      onStation: (s) => {
        const item = document.querySelector('.plan-item[data-id="' + s.id + '"]');
        if (item) { item.scrollIntoView({ behavior: 'smooth', block: 'center' }); item.style.background = '#fff7d6'; setTimeout(() => (item.style.background = ''), 1500); }
      },
    });
    drawRoutes(teams, active);
  }

  function drawRoutes(teams, active) {
    routeLayer.clearLayers();
    const base = [state.event.base.lat, state.event.base.lng];
    state.teams.forEach((t) => {
      const pts = FST.teamStops(state, t.id).filter((s) => s.lat != null).map((s) => [s.lat, s.lng]);
      if (!pts.length) return;
      L.polyline([base].concat(pts, [base]), { color: t.color, weight: 3, opacity: 0.7, dashArray: '6 8' }).addTo(routeLayer);
    });
  }

  let acting = false;
  async function act(action, data, msg) {
    if (acting) return;
    acting = true;
    document.body.classList.add('is-saving');
    try {
      await FST.api(action, data);
      if (msg) FST.toast(msg, 'ok');
      await load();
    } catch (e) { FST.toast(e.message, 'error'); }
    acting = false;
    document.body.classList.remove('is-saving');
  }

  function move(id, dir) {
    const s = state.stations.find((x) => x.id === id);
    const list = state.stations.filter((x) => x.active && x.team_id === s.team_id).sort((a, b) => (a.order || 999) - (b.order || 999)).map((x) => x.id);
    const i = list.indexOf(id), j = i + dir;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    act('reorder', { team_id: s.team_id, ids: list });
  }

  document.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-assign]');
    if (sel) act('assign', { station_id: +sel.dataset.assign, team_id: sel.value === 'first' || sel.value === 'last' ? sel.value : sel.value ? +sel.value : null }, 'Saved');
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.up) move(+b.dataset.up, -1);
    if (b.dataset.down) move(+b.dataset.down, 1);
    if (b.dataset.opt) act('optimise', { team_id: +b.dataset.opt }, 'Order updated to the shortest route');
    if (b.dataset.skip) act('shared_skip', { station_id: +b.dataset.skip, team_id: +b.dataset.team, skip: 1 }, 'This team now skips that stop');
    if (b.dataset.unskip) act('shared_skip', { station_id: +b.dataset.unskip, team_id: +b.dataset.team, skip: 0 }, 'Added back');
  });
  m.onFit = () => FST.fit(m, state);
  load().catch((e) => FST.toast(e.message, 'error'));
})();
</script>
<?php page_foot(); ?>
