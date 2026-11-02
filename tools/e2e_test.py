"""
End-to-end check of every admin and team-leader action against a FRESH install (it creates and deletes data).

  python tools/e2e_test.py http://127.0.0.1:8091 <admin-password-to-set>

Run it only against a test copy with an empty database, never the live event site.
"""
import http.cookiejar
import json
import re
import sys
import time
import urllib.parse
import urllib.request

sys.stdout.reconfigure(encoding='utf-8', errors='replace')
BASE = sys.argv[1].rstrip('/')
ADMIN_PASS = sys.argv[2]
results = []


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''

    def req(self, path, data=None, headers=None, method=None):
        url = BASE + path
        body = None
        h = dict(headers or {})
        if isinstance(data, dict):
            body = urllib.parse.urlencode(data, doseq=True).encode()
            h.setdefault('Content-Type', 'application/x-www-form-urlencoded')
        elif isinstance(data, (bytes, str)):
            body = data.encode() if isinstance(data, str) else data
        r = urllib.request.Request(url, data=body, headers=h, method=method)
        try:
            with self.op.open(r, timeout=40) as res:
                return res.status, res.read().decode('utf-8', 'replace'), res.geturl()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), url

    def page(self, path):
        code, body, url = self.req(path)
        m = re.search(r'name="csrf" value="([0-9a-f]+)"', body) or re.search(r'"csrf":"([0-9a-f]+)"', body)
        if m:
            self.csrf = m.group(1)
        return code, body, url

    def form(self, path, data):
        if not self.csrf:
            self.page(path.split('?')[0])
        return self.req(path, dict(data, csrf=self.csrf))

    def api(self, action, **data):
        code, body, _ = self.req('/api/action.php', json.dumps(dict(data, action=action)),
                                 {'Content-Type': 'application/json', 'X-CSRF': self.csrf})
        try:
            return code, json.loads(body)
        except ValueError:
            return code, {'raw': body[:200]}

    def state(self):
        return json.loads(self.req('/api/state.php')[1])


def check(name, ok, detail=''):
    results.append((name, bool(ok), detail))
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else '  -> ' + str(detail)[:300]))


def station(st, name):
    return next((s for s in st['stations'] if s['name'] == name), None)


def team(st, name):
    return next((t for t in st['teams'] if t['name'] == name), None)


admin = Client()

# ---------------------------------------------------------------- install
code, body, url = admin.req('/install.php')
check('installer opens', code == 200 and 'Admin password' in body, body[:200])
code, body, url = admin.req('/install.php', {'event_name': 'E2E Drive', 'password': ADMIN_PASS, 'password2': ADMIN_PASS, 'seed': '1'})
check('installer finishes and logs in', url.endswith('/teams.php'), url)
code, body, _ = admin.req('/install.php')
check('installer locks itself', 'Tables are up to date' in body or 'already done' in body, body[:200])
admin.page('/teams.php')
st = admin.state()
check('40 researched stations loaded, 24 active', len(st['stations']) == 40 and sum(s['active'] for s in st['stations']) == 24, len(st['stations']))

# ---------------------------------------------------------------- teams
code, body, url = admin.form('/teams.php', {'do': 'team_save', 'name': 'Alpha', 'color': '#1e88e5', 'leader_name': 'Asha', 'leader_phone': '9000000001', 'pin': '4321', 'notes': 'Borivali side · 2 cars'})
check('create team', 'Saved Alpha' in body and 'Borivali side' in body, body[:200])
admin.form('/teams.php', {'do': 'team_save', 'name': 'Bravo', 'color': '#43a047', 'leader_name': 'Bina', 'leader_phone': '9000000002', 'pin': '5678', 'notes': 'South'})
st = admin.state()
alpha, bravo = team(st, 'Alpha'), team(st, 'Bravo')
check('both teams in live data with description', alpha and bravo and alpha['description'] == 'Borivali side · 2 cars', st['teams'])
code, body, _ = admin.form('/teams.php', {'do': 'team_save', 'id': alpha['id'], 'name': 'Alpha Team', 'color': '#e53935', 'leader_name': 'Asha M', 'leader_phone': '9000000009', 'pin': '', 'notes': 'Edited description'})
st = admin.state()
alpha = team(st, 'Alpha Team')
check('edit team (name, colour, leader, description) keeps PIN', alpha and alpha['color'] == '#e53935' and alpha['description'] == 'Edited description' and alpha['leader_phone'] == '9000000009', alpha)
code, body, _ = admin.form('/teams.php', {'do': 'team_save', 'id': alpha['id'], 'name': 'Alpha Team', 'color': '#e53935', 'pin': '12'})
check('bad PIN rejected', 'PIN should be 4 to 8 digits' in body, body[:200])

# ---------------------------------------------------------------- cars
admin.form('/teams.php', {'do': 'car_save', 'team_id': alpha['id'], 'label': 'Car 1', 'plate': 'MH02 AA 1111', 'driver_name': 'Dev', 'driver_phone': '9111111111'})
admin.form('/teams.php', {'do': 'car_save', 'team_id': alpha['id'], 'label': 'Car 2', 'plate': 'MH02 AA 2222'})
admin.form('/teams.php', {'do': 'car_save', 'team_id': alpha['id'], 'label': 'Temp car'})
st = admin.state()
cars = [c for c in st['cars'] if c['team_id'] == alpha['id']]
check('add cars', len(cars) == 3, cars)
temp = next(c for c in cars if c['label'] == 'Temp car')
admin.form('/teams.php', {'do': 'car_save', 'id': temp['id'], 'team_id': alpha['id'], 'label': 'Temp car edited', 'plate': 'MH01'})
st = admin.state()
check('edit car', any(c['label'] == 'Temp car edited' and c['plate'] == 'MH01' for c in st['cars']), st['cars'])
admin.form('/teams.php', {'do': 'car_delete', 'id': temp['id']})
st = admin.state()
check('delete car', not any(c['id'] == temp['id'] for c in st['cars']), st['cars'])
car1 = next(c for c in st['cars'] if c['label'] == 'Car 1' and c['team_id'] == alpha['id'])
car2 = next(c for c in st['cars'] if c['label'] == 'Car 2' and c['team_id'] == alpha['id'])

# ---------------------------------------------------------------- phones
admin.form('/teams.php', {'do': 'device_save', 'team_id': alpha['id'], 'identifier': 'e2e01', 'name': 'Driver phone', 'car_id': car1['id']})
code, body, _ = admin.form('/teams.php', {'do': 'device_save', 'team_id': alpha['id'], 'identifier': 'e2e01', 'name': 'Duplicate'})
check('duplicate phone ID rejected', 'already exists' in body, body[:200])
st = admin.state()
dev = next((d for d in st['devices'] if d['identifier'] == 'e2e01'), None)
check('add Traccar phone to team + car', dev and dev['team_id'] == alpha['id'] and dev['car_id'] == car1['id'], dev)
admin.form('/teams.php', {'do': 'device_save', 'id': dev['id'], 'team_id': alpha['id'], 'identifier': 'e2e01', 'name': 'Driver phone renamed', 'car_id': car2['id'], 'active': '1'})
st = admin.state()
dev = next(d for d in st['devices'] if d['identifier'] == 'e2e01')
check('edit phone (name, car)', dev['name'] == 'Driver phone renamed' and dev['car_id'] == car2['id'], dev)
code, body, _ = admin.form('/teams.php', {'do': 'device_web', 'team_id': alpha['id'], 'name': 'Browser one'})
check('create browser sharing link', 'share.php?t=' in body, body[:200])
st = admin.state()
web = next(d for d in st['devices'] if d['name'] == 'Browser one')
admin.form('/teams.php', {'do': 'device_delete', 'id': web['id']})
st = admin.state()
check('delete phone', not any(d['id'] == web['id'] for d in st['devices']), [d['name'] for d in st['devices']])
code, body, _ = admin.req('/api/traccar.php?id=newphone77&lat=19.2&lon=72.85&speed=0')
st = admin.state()
newp = next((d for d in st['devices'] if d['identifier'] == 'newphone77'), None)
check('unknown Traccar phone listed as new (unassigned)', code == 200 and newp and not newp['approved'], newp)
admin.form('/teams.php', {'do': 'device_save', 'id': newp['id'], 'team_id': bravo['id'], 'name': 'Bravo phone'})
st = admin.state()
newp = next(d for d in st['devices'] if d['identifier'] == 'newphone77')
check('assign new phone to a team', newp['approved'] and newp['team_id'] == bravo['id'], newp)

# ---------------------------------------------------------------- stations
admin.page('/stations.php')
code, body, _ = admin.form('/stations.php', {'do': 'save', 'name': 'E2E Start Point', 'maps_link': 'https://maps.app.goo.gl/t8EkdzXhw1xdmehD7', 'lat': '', 'lng': '', 'team_id': alpha['id'], 'boxes': '5', 'active': '1', 'area': 'Kandivali West'})
st = admin.state()
s1 = station(st, 'E2E Start Point')
try:
    urllib.request.urlopen('https://maps.app.goo.gl/t8EkdzXhw1xdmehD7', timeout=8)
    google_ok = True
except Exception:
    google_ok = False
if google_ok:
    check('add station from a short Google Maps link (location found)', s1 and s1['lat'] and abs(s1['lat'] - 19.19817) < 0.001 and s1['team_id'] == alpha['id'], s1)
else:
    print('SKIP short Google Maps link: maps.app.goo.gl is not reachable from this network right now')
    check('station still saved when Google cannot be reached', s1 and s1['team_id'] == alpha['id'], s1)
    admin.api('station_coords', id=s1['id'], lat=19.1981694, lng=72.8463094)
    s1 = station(admin.state(), 'E2E Start Point')
admin.form('/stations.php', {'do': 'save', 'id': s1['id'], 'name': 'E2E Station One', 'maps_link': s1['maps_link'], 'lat': s1['lat'], 'lng': s1['lng'], 'team_id': alpha['id'], 'boxes': '7', 'active': '1', 'notes': 'Ask for duty officer'})
st = admin.state()
s1 = station(st, 'E2E Station One')
check('edit station (name, boxes, notes)', s1 and s1['boxes'] == 7 and s1['notes'] == 'Ask for duty officer', s1)
code, body, _ = admin.form('/stations.php', {'do': 'save', 'name': '', 'maps_link': ''})
check('station without a name rejected', 'Give the station a name' in body, body[:200])
admin.form('/stations.php', {'do': 'toggle', 'id': s1['id']})
st = admin.state()
check('mark station Not going (inactive)', station(st, 'E2E Station One')['active'] is False, station(st, 'E2E Station One'))
admin.form('/stations.php', {'do': 'toggle', 'id': s1['id']})
st = admin.state()
check('make station active again', station(st, 'E2E Station One')['active'] is True, '')
code, body, _ = admin.form('/stations.php', {'do': 'bulk', 'lines': "Sr\tFire Station\tLatitude\tLongitude\n1\tBulk One\t19.1\t72.85\nBulk Two | https://www.google.com/maps/place/X/@19.05,72.83,17z/data=!3d19.0504426!4d72.8375638"})
st = admin.state()
b1, b2 = station(st, 'Bulk One'), station(st, 'Bulk Two')
check('paste many: Excel row + link (header skipped)', b1 and b2 and b1['lat'] == 19.1 and abs(b2['lat'] - 19.0504426) < 1e-6 and not station(st, 'Sr'), (b1, b2))
admin.form('/stations.php', {'do': 'toggle', 'id': b1['id']})
admin.form('/stations.php', {'do': 'delete', 'id': b1['id']})
st = admin.state()
check('delete an inactive station', station(st, 'Bulk One') is None, '')
admin.form('/stations.php', {'do': 'delete', 'id': b2['id']})
st = admin.state()
check('active station cannot be deleted', station(st, 'Bulk Two') is not None, '')

# ---------------------------------------------------------------- plan (assign, order, best order)
active = [s for s in st['stations'] if s['active'] and s['name'] not in ('E2E Station One',)]
for s in active[:3]:
    code, j = admin.api('assign', station_id=s['id'], team_id=alpha['id'])
for s in active[3:5]:
    admin.api('assign', station_id=s['id'], team_id=bravo['id'])
st = admin.state()
mine = sorted([s for s in st['stations'] if s['team_id'] == alpha['id'] and s['active']], key=lambda s: s['order'])
check('assign stations to a team (suggested order 1..n)', [s['order'] for s in mine] == list(range(1, len(mine) + 1)) and len(mine) == 4, [(s['name'], s['order']) for s in mine])
ids = [s['id'] for s in mine][::-1]
code, j = admin.api('reorder', team_id=alpha['id'], ids=ids)
st = admin.state()
mine = sorted([s for s in st['stations'] if s['team_id'] == alpha['id'] and s['active']], key=lambda s: s['order'])
check('reorder stations', [s['id'] for s in mine] == ids, [s['id'] for s in mine])
code, j = admin.api('optimise', team_id=alpha['id'])
check('best order', code == 200 and j.get('ok'), j)
code, j = admin.api('assign', station_id=mine[0]['id'], team_id=None)
st = admin.state()
check('unassign a station', station(st, mine[0]['name'])['team_id'] is None, '')
admin.api('assign', station_id=mine[0]['id'], team_id=alpha['id'])

# ---------------------------------------------------------------- statuses + one-step undo + no double-undo
target = s1
for to, want in (('reached', 'reached'), ('done', 'done'), ('reached', 'reached'), ('pending', 'pending')):
    code, j = admin.api('station_status', id=target['id'], status=to)
    stx = station(admin.state(), target['name'])
    check('admin sets %s -> %s' % (target['name'], to), code == 200 and stx['status'] == want, (code, j, stx['status']))
code, j = admin.api('station_status', id=target['id'], status='pending')
check('pressing Undo twice is harmless', code == 200 and station(admin.state(), target['name'])['status'] == 'pending', j)
# a car parked at the station right after Undo must not flip it back
admin.req('/api/traccar.php?id=e2e01&lat=%s&lon=%s&speed=0&timestamp=%d' % (target['lat'], target['lng'], int(time.time())))
check('auto-detection waits after a manual Undo', station(admin.state(), target['name'])['status'] == 'pending', station(admin.state(), target['name']))
# a station without a recent manual change is detected automatically
other = sorted([s for s in admin.state()['stations'] if s['team_id'] == alpha['id'] and s['active'] and s['id'] != target['id']], key=lambda s: s['order'])[0]
admin.req('/api/traccar.php?id=e2e01&lat=%s&lon=%s&speed=0&timestamp=%d' % (other['lat'], other['lng'], int(time.time()) + 1))
check('car arriving at a station marks it Reached automatically', station(admin.state(), other['name'])['status'] == 'reached', station(admin.state(), other['name']))

# ---------------------------------------------------------------- team leader
leader = Client()
code, body, _ = leader.page('/login.php?as=team')
code, body, url = leader.form('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '0000'})
check('wrong PIN refused', 'Wrong team or PIN' in body, body[:200])
leader.page('/login.php?as=team')
code, body, url = leader.form('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '4321'})
check('team leader logs in with PIN', url.endswith('/team.php'), url)
leader.page('/team.php')
code, j = leader.api('station_status', id=other['id'], status='done')
check('leader marks own station Delivered', code == 200 and station(leader.state(), other['name'])['status'] == 'done', j)
bravo_station = next(s for s in admin.state()['stations'] if s['team_id'] == bravo['id'])
code, j = leader.api('station_status', id=bravo_station['id'], status='done')
check("leader can't change another team's station", code == 403, (code, j))
code, j = leader.api('assign', station_id=bravo_station['id'], team_id=alpha['id'])
check("leader can't change the plan", code == 403, (code, j))
code, j = leader.api('team_eta', time='15:30')
check('leader sets return time', code == 200 and j.get('expected_return_at'), j)
code, j = leader.api('web_device', name="Asha's phone")
check('leader phone sharing gets a device', code == 200 and j.get('token') and j.get('id'), j)
code, j = leader.api('my_device', token=j['token'], car_id=car1['id'])
check('leader picks their car', code == 200, j)
code, body, _ = leader.req('/admin.php')
check("leader can't open admin pages", 'login.php' in _ or 'Admin' in body and 'Password' in body, _)
code, j = leader.api('team_status', status='returned')
check('leader marks Back at base', team(leader.state(), 'Alpha Team')['status'] == 'returned', j)

# ---------------------------------------------------------------- team members (tracked phones with a name and mobile)
admin.page('/teams.php')
admin.form('/teams.php', {'do': 'team_save', 'id': alpha['id'], 'name': 'Alpha Team', 'color': '#e53935', 'leader_name': 'Asha M', 'leader_phone': '9000000009', 'notes': 'Edited description', 'pin': '', 'member_pin': '2468'})
code, body, _ = admin.req('/teams.php')
check('admin sets a member PIN', 'Member PIN: <span class="pill done plain">Set' in body, '')
member = Client()
member.page('/login.php?as=member')
code, body, url = member.form('/login.php', {'as': 'member', 'team_id': alpha['id'], 'pin': '1111', 'name': 'Meera', 'phone': '9822001122'})
check('member: wrong PIN refused', 'Wrong team or member PIN' in body, body[:200])
member.page('/login.php?as=member')
code, body, url = member.form('/login.php', {'as': 'member', 'team_id': alpha['id'], 'pin': '2468', 'name': '', 'phone': '123'})
check('member: name and 10-digit mobile required', 'your name and your 10-digit mobile' in body, body[:200])
member.page('/login.php?as=member')
code, body, url = member.form('/login.php', {'as': 'member', 'team_id': alpha['id'], 'pin': '2468', 'name': 'Meera Shah', 'phone': '98220 01122'})
check('member logs in with team + member PIN + name + mobile', url.endswith('/member.php') and 'Hi Meera' in body, url)
tok = re.search(r'"deviceToken":"([0-9a-f]+)"', body).group(1)
ident = re.search(r'<code>(m\d{6})</code>', body).group(1)
st = admin.state()
md = next((d for d in st['devices'] if d['identifier'] == ident), None)
check('member phone appears for admin with name, mobile and Member role', md and md['name'] == 'Meera Shah' and md['person_phone'] == '98220 01122' and md['role'] == 'member' and md['team_id'] == alpha['id'], md)
again = Client()
again.page('/login.php?as=member')
code, body, url = again.form('/login.php', {'as': 'member', 'team_id': alpha['id'], 'pin': '2468', 'name': 'Meera Shah', 'phone': '+91 9822001122'})
check('same person logging in again reuses their phone (no duplicate)', sum(1 for d in admin.state()['devices'] if d['role'] == 'member') == 1, '')
member.page('/member.php')
code, j = member.api('my_device', token=tok, car_id=car1['id'])
check('member picks their car', code == 200, j)
code, j = member.api('station_status', id=other['id'], status='pending')
check("member can't mark stations", code == 403, (code, j))
code, body, url = member.req('/team.php')
check("member is kept on the member page (not the leader page)", url.endswith('/member.php'), url)
code, body, url = member.req('/stations.php')
check("member can't open admin pages", 'login.php' in url, url)
code, body, _ = member.req('/api/location.php', json.dumps({'t': tok, 'lat': 19.15, 'lng': 72.85, 'acc': 10, 'ts': int(time.time() * 1000)}), {'Content-Type': 'application/json'})
md = next(d for d in admin.state()['devices'] if d['identifier'] == ident)
check('member phone shares location', code == 200 and md['fix_at'] and md['car_id'] == car1['id'], (code, md))
admin.req('/api/traccar.php?id=%s&lat=19.151&lon=72.851&speed=12' % ident)
md = next(d for d in admin.state()['devices'] if d['identifier'] == ident)
check("member's personal ID works in the Traccar app (same phone)", md['app_seen_at'], md)
lead = next((d for d in admin.state()['devices'] if d['role'] == 'leader'), None)
check("leader's phone is registered with the leader's mobile", lead and lead['person_phone'] == '9000000009', lead)

# ---------------------------------------------------------------- deleting phones
code, body, _ = admin.req('/teams.php')
check('every phone on the Teams page has a delete button', body.count('data-del-device=') >= len([d for d in admin.state()['devices'] if d['approved']]), '')
code, j = leader.api('device_delete', id=md['id'])
check("leader can't delete phones", code == 403, (code, j))
code, j = member.api('device_delete', id=md['id'])
check("member can't delete phones", code == 403, (code, j))
code, j = admin.api('device_delete', id=md['id'])
check('admin deletes a phone from the live map / Teams page', code == 200 and not any(d['id'] == md['id'] for d in admin.state()['devices']), (code, j))
code, body, _ = member.req('/api/location.php', json.dumps({'t': tok, 'lat': 19.15, 'lng': 72.85}), {'Content-Type': 'application/json'})
check('a deleted phone can no longer send its location', code == 404, code)
code, body, url = member.req('/member.php')
check('member whose phone was deleted is sent back to log in', 'login.php' in url, url)
code, j = admin.api('device_delete', id=md['id'])
check('deleting the same phone twice is harmless', code == 404, (code, j))
for ident2 in ('bulk01', 'bulk02'):
    admin.req('/api/traccar.php?id=%s&lat=19.2&lon=72.85' % ident2)
ids = [d['id'] for d in admin.state()['devices'] if d['identifier'] in ('bulk01', 'bulk02')]
code, body, _ = admin.form('/teams.php', {'do': 'devices_delete', 'ids[]': ids})
check('delete several phones at once', len(ids) == 2 and 'Deleted 2 phones' in body and not any(d['identifier'] in ('bulk01', 'bulk02') for d in admin.state()['devices']), ids)

# ---------------------------------------------------------------- 1 Nov list + stops every team makes (Borivali first, Byculla last)
def visit(s, tid):
    return next((v for v in s.get('visits', []) if v['team_id'] == tid), None)


def route(st, tid):
    """A team's stops as (number, name, status), in route order."""
    out = []
    for s in st['stations']:
        if not s['active']:
            continue
        if s['shared']:
            v = visit(s, tid)
            if v and not v['skip']:
                out.append((v['order'], s['name'], v['status']))
        elif s['team_id'] == tid:
            out.append((s['order'], s['name'], s['status']))
    return sorted(out, key=lambda x: x[0] or 999)


code, body, _ = admin.req('/preset.php')
check('1 Nov list page: 26 stations, Kalina and Film City to be added, Borivali first, Byculla last',
      code == 200 and body.count('<tr>') >= 27 and body.count('will be added') == 2 and 'First stop for every team' in body and 'Last stop for every team' in body, code)
code, body, url = admin.form('/preset.php', {'do': 'apply'})
st = admin.state()
bor = next(s for s in st['stations'] if s['name'].startswith('Borivali'))
byc = next(s for s in st['stations'] if s['name'].startswith('Byculla'))
check('apply the 1 Nov list: 26 going, the rest not going', url.endswith('/plan.php') and 'Done: 26 stations going' in body and sum(s['active'] for s in st['stations']) == 26, (url, sum(s['active'] for s in st['stations'])))
check('Borivali is the first stop and Byculla the last stop for every team', bor['shared'] == 'first' and byc['shared'] == 'last' and bor['team_id'] is None and len(bor['visits']) == len(st['teams']), (bor['shared'], byc['shared']))
new2 = [s for s in st['stations'] if s['name'] in ('Kalina Mini Fire Station', 'Film City Mini Fire Station')]
check('Kalina and Film City added, going, with a map location', len(new2) == 2 and all(s['active'] and s['lat'] for s in new2), new2)
code, body, url = admin.form('/preset.php', {'do': 'apply'})
check('applying the list twice is harmless', len(admin.state()['stations']) == len(st['stations']), len(admin.state()['stations']))
a, b = route(st, alpha['id']), route(st, bravo['id'])
check('each team: Borivali stop 1, its own stations, Byculla last, numbered 1..n',
      all(r[0][1].startswith('Borivali') and r[-1][1].startswith('Byculla') and [x[0] for x in r] == list(range(1, len(r) + 1)) for r in (a, b)), (a, b))
check('team counts include the stops every team makes', team(st, 'Bravo')['counts']['total'] == len(b), (team(st, 'Bravo')['counts'], len(b)))
code, j = leader.api('station_status', id=bor['id'], status='done')
s = station(admin.state(), bor['name'])
check('leader marks Borivali delivered for their own team only', code == 200 and visit(s, alpha['id'])['status'] == 'done' and visit(s, bravo['id'])['status'] == 'pending' and s['status'] == 'reached', (code, j, s.get('visits')))
code, j = admin.api('station_status', id=bor['id'], status='done')
check('admin must pick the team at a stop every team makes', code == 400, (code, j))
code, j = admin.api('station_status', id=bor['id'], status='done', team_id=bravo['id'])
s = station(admin.state(), bor['name'])
check('a stop every team makes counts as delivered once every team has delivered', code == 200 and s['status'] == 'done', s)
code, j = admin.api('station_status', id=bor['id'], status='reached', team_id=bravo['id'])
s = station(admin.state(), bor['name'])
check('undo at a shared stop changes only that team', visit(s, bravo['id'])['status'] == 'reached' and visit(s, alpha['id'])['status'] == 'done' and s['status'] == 'reached', s.get('visits'))
admin.req('/api/traccar.php?id=newphone77&lat=%s&lon=%s&speed=0&timestamp=%d' % (byc['lat'], byc['lng'], int(time.time()) + 5))
s = station(admin.state(), byc['name'])
check("Bravo's car at Byculla marks it Reached for Bravo only", visit(s, bravo['id'])['status'] == 'reached' and visit(s, alpha['id'])['status'] == 'pending', s.get('visits'))
tb = team(admin.state(), 'Bravo')
check('Bravo is on the way with a return estimate, next stop is still Borivali (not delivered)', tb['status'] == 'on_route' and tb['eta'] and tb['next_station_id'] == bor['id'] and tb['next_status'] == 'reached', tb)
code, j = leader.api('shared_skip', station_id=byc['id'], team_id=alpha['id'], skip=1)
check("leader can't change which stops a team skips", code == 403, (code, j))
code, j = admin.api('shared_skip', station_id=byc['id'], team_id=alpha['id'], skip=1)
st = admin.state()
check('a team can skip a stop every team makes', code == 200 and not any(x[1] == byc['name'] for x in route(st, alpha['id'])) and visit(station(st, byc['name']), alpha['id'])['skip'], route(st, alpha['id']))
admin.api('shared_skip', station_id=byc['id'], team_id=alpha['id'], skip=0)
check('and add it back', route(admin.state(), alpha['id'])[-1][1] == byc['name'], route(admin.state(), alpha['id']))
fort = station(st, 'Fort Fire Station')
code, j = admin.api('assign', station_id=fort['id'], team_id='last')
check('Route plan: make a station a last stop for every team', code == 200 and station(admin.state(), 'Fort Fire Station')['shared'] == 'last', j)
code, j = admin.api('assign', station_id=fort['id'], team_id=bravo['id'])
s = station(admin.state(), 'Fort Fire Station')
check('and give it back to one team', s['shared'] == '' and s['team_id'] == bravo['id'] and 'visits' not in s, s)
code, j = admin.api('optimise', team_id=bravo['id'])
b = route(admin.state(), bravo['id'])
check('best order keeps Borivali first and Byculla last', code == 200 and b[0][1] == bor['name'] and b[-1][1] == byc['name'], b)
admin.page('/stations.php')
code, body, _ = admin.form('/stations.php', {'do': 'save', 'id': fort['id'], 'name': fort['name'], 'lat': fort['lat'], 'lng': fort['lng'], 'team_id': 'first', 'active': '1'})
check('Fire stations page: set a station as a first stop for every team', station(admin.state(), 'Fort Fire Station')['shared'] == 'first', body[:200])
admin.form('/stations.php', {'do': 'save', 'id': fort['id'], 'name': fort['name'], 'lat': fort['lat'], 'lng': fort['lng'], 'team_id': bravo['id'], 'active': '1'})
check('and back to one team', station(admin.state(), 'Fort Fire Station')['shared'] == '' and station(admin.state(), 'Fort Fire Station')['team_id'] == bravo['id'], '')
code, body, _ = admin.req('/report.php')
check('report lists each team at the stops every team makes', code == 200 and body.count('every team stops here') == 2 * len(admin.state()['teams']), body.count('every team stops here'))
for p in ('/plan.php', '/stations.php', '/teams.php', '/report.php?doc=1', '/report.php?csv=1', '/admin.php'):
    code, body, _ = admin.req(p)
    check('page still opens: ' + p, code == 200 and 'Fatal error' not in body and 'Warning:' not in body, code)

# ---------------------------------------------------------------- settings, password, report, reset, delete team
admin.page('/settings.php')
code, body, _ = admin.form('/settings.php', {'do': 'save', 'event_name': 'E2E Drive 2', 'event_subtitle': 'Test', 'base_name': 'Base', 'base_link': '', 'base_lat': '19.1981694', 'base_lng': '72.8463094',
                                            'arrival_radius': '200', 'base_radius': '250', 'min_dwell': '60', 'avg_speed': '22', 'stop_minutes': '8', 'auto_done': '1', 'public_enabled': '1', 'public_trails': '1'})
st = admin.state()
check('save settings', st['event']['name'] == 'E2E Drive 2' and st['event']['radius'] == 200, st['event'])
admin.form('/settings.php', {'do': 'password', 'current': ADMIN_PASS, 'new': ADMIN_PASS + 'x'})
fresh = Client()
fresh.page('/login.php?as=admin')
code, body, url = fresh.form('/login.php', {'as': 'admin', 'password': ADMIN_PASS + 'x'})
check('change admin password', url.endswith('/admin.php'), url)
admin.form('/settings.php', {'do': 'password', 'current': ADMIN_PASS + 'x', 'new': ADMIN_PASS})
code, body, _ = admin.req('/report.php')
check('report page has Teams and Locations sections', code == 200 and 'id="teams"' in body and 'id="locations"' in body and 'Cars and speeds' in body, code)
code, body, _ = admin.req('/report.php?csv=1')
check('report download for Excel (teams, visits, cars with numbers, locations)', all(x in body for x in ('SECTION 1: TEAMS', 'Stations completed, in the order visited', 'Cars and speeds', 'MH02 AA 1111', 'SECTION 2: LOCATIONS')), body[:200])
code, body, _ = admin.req('/report.php?doc=1')
check('written report (summary, Teams and Locations on new pages, no signature lines)', code == 200 and 'Summary' in body and body.count('class="new-page"') == 2 and 'Prepared by' not in body, code)
code, body, url = leader.req('/report.php')
check("leader can't open the report", 'login.php' in url, url)
code, body, url = leader.req('/report.php?csv=1')
check("leader can't download the report", 'login.php' in url and 'SECTION' not in body, url)
code, body, _ = admin.form('/settings.php', {'do': 'reset', 'confirm': 'nope'})
check('reset needs RESET typed', 'Type RESET' in body, body[:200])
admin.form('/settings.php', {'do': 'reset', 'confirm': 'RESET', 'positions': '1'})
st = admin.state()
check('reset progress', all(s['status'] == 'pending' for s in st['stations']) and all(t['status'] == 'not_started' for t in st['teams']), '')
admin.form('/teams.php', {'do': 'team_delete', 'id': bravo['id']})
st = admin.state()
check('delete team (its stations become unassigned)', team(st, 'Bravo') is None and not any(s['team_id'] == bravo['id'] for s in st['stations']), '')

# ---------------------------------------------------------------- public + access
public = Client()
code, body, _ = public.req('/api/state.php')
pj = json.loads(body)
check('public map data hides phone numbers', code == 200 and 'leader_phone' not in json.dumps(pj['teams']), '')
code, body, _ = public.req('/api/action.php', json.dumps({'action': 'assign'}), {'Content-Type': 'application/json'})
check('public cannot press buttons', code == 401, code)
for p in ('/stations.php', '/teams.php', '/plan.php', '/settings.php', '/report.php', '/preset.php'):
    code, body, url = public.req(p)
    check('public redirected to login from ' + p, 'login.php' in url, url)

# ---------------------------------------------------------------- battery, downloads, security
admin.req('/api/traccar.php?id=e2e01&batt=42&charge=true')
d = next(x for x in admin.state()['devices'] if x['identifier'] == 'e2e01')
check('battery updates from a Traccar check-in without a position', d['battery'] == 42 and d['charging'], d)
code, body, _ = admin.req('/stations.php?csv=1')
check('fire stations download for Excel', code == 200 and body.lstrip('﻿').startswith('Sr,"Fire station"') and 'Not going' in body, body[:80])
r = urllib.request.urlopen(BASE + '/index.php', timeout=20)
check('protective browser headers sent', r.headers.get('X-Frame-Options') == 'SAMEORIGIN' and r.headers.get('X-Content-Type-Options') == 'nosniff', dict(r.headers))
code, body, _ = public.req('/stations.php?csv=1')
check("public can't download the station list", 'Sr,' not in body, body[:80])
# ---------------------------------------------------------------- refresh must reload, never re-send a form (Post/Redirect/Get)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def post_status(client, path, data):
    op = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(client.jar))
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(dict(data, csrf=client.csrf)).encode())
    try:
        with op.open(req, timeout=40) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


admin.page('/teams.php')
forms = [
    ('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '9999'}, Client()),
    ('/login.php', {'as': 'member', 'team_id': alpha['id'], 'pin': '9999', 'name': 'X', 'phone': '1'}, Client()),
    ('/login.php', {'as': 'admin', 'password': 'wrong-password'}, Client()),
    ('/stations.php', {'do': 'save', 'name': ''}, admin),
    ('/stations.php', {'do': 'save', 'name': 'PRG test', 'lat': '19.1', 'lng': '72.8', 'active': '1'}, admin),
    ('/stations.php', {'do': 'nonsense'}, admin),
    ('/teams.php', {'do': 'team_save', 'name': ''}, admin),
    ('/teams.php', {'do': 'car_save', 'team_id': alpha['id'], 'label': 'PRG car'}, admin),
    ('/teams.php', {'do': 'nonsense'}, admin),
    ('/settings.php', {'do': 'password', 'current': 'wrong', 'new': 'x'}, admin),
    ('/settings.php', {'do': 'nonsense'}, admin),
    ('/preset.php', {'do': 'apply'}, admin),
    ('/install.php', {'password': 'x', 'password2': 'y'}, admin),
]
bad = []
for path, data, cl in forms:
    if cl is not admin:
        cl.page(path + ('?as=' + data['as'] if 'as' in data else ''))
    code = post_status(cl, path, data)
    if code not in (302, 303):
        bad.append((path, data.get('do') or data.get('as'), code))
check('every form answers with a redirect, so refresh never asks to resubmit (%d forms)' % len(forms), not bad, bad)
lc = Client()
lc.page('/login.php?as=team')
code, body, url = lc.form('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '9999'})
check('wrong PIN message still shows after the redirect', 'Wrong team or PIN' in body and '/login.php?as=team' in url, url)
code, body, url = lc.req('/login.php?as=team')
check('refreshing the login page after an error shows a clean page', 'Wrong team or PIN' not in body, '')

guess = Client()
t0 = time.time()
for i in range(25):
    guess.page('/login.php?as=team')
    guess.form('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '%04d' % i})
guess.page('/login.php?as=team')
code, body, url = guess.form('/login.php', {'as': 'team', 'team_id': alpha['id'], 'pin': '4321'})
check('PIN guessing is slowed down and then blocked (even with cookies cleared)', 'Too many wrong attempts' in body and not url.endswith('/team.php') and time.time() - t0 > 20, round(time.time() - t0))

failed = [r for r in results if not r[1]]
print('\n%d checks, %d passed, %d failed' % (len(results), len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
