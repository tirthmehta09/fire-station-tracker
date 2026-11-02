# Fire Station Tracker

Live tracking for the Sunday sweets distribution to the Western line fire stations (Kandivali → Churchgate).

- **Admin**: live map of every car, which stations are done or pending, return-time estimates, phones' battery and signal.
- **Team leaders** (phone, PIN login): their phone **shares the team's location** from the same page (tap Start), their stations in a *suggested* order (any order is fine), Navigate / Reached / Delivered buttons, expected return time.
- **Installable app (PWA)**: "Install app" on Android Chrome or "Add to Home Screen" on iPhone; it opens straight to the leader's page.
- **Public read-only map** for all volunteers.
- **Automatic**: a car within 150 m of its station is marked *Reached*; driving away marks it *Delivered*; leaving the start point marks the team *On the way*; coming back after the last station marks it *Back at base*. Leaders can always correct it by hand.
- Stations are added with a **name + Google Maps link** (short `maps.app.goo.gl` links work). "Delete" = **Not going** (inactive); nothing is lost.

Built with plain PHP 8 + MySQL so it runs on the existing Hostinger shared hosting (the Hostinger plan in use doesn't run Node.js). Same look as the Save Birds Kandivali attendance system. Location comes from the **team leader's phone** (browser, page kept open) and, for a second phone per car, the free **Traccar Client** app (keeps working with the screen off). Maps: OpenFreeMap (free, no key).

---

## Put it on Hostinger (about 15 minutes)

1. **Pick the address.** Easiest: a folder on an existing site, e.g. `https://savebirdskandivali.org/firetrack/`. (Or create a subdomain like `track.yourdomain` in hPanel → Domains → Subdomains and use its folder.)
2. **Create the database.** hPanel → *Databases* → *Management* → create a database and user. Write down the database name, user and password (on Hostinger they are prefixed with your account number).
3. **Upload.** hPanel → *Files* → *File Manager* → open `public_html` → *New folder* `firetrack` → open it → *Upload* `firetrack-upload.zip` → right-click → *Extract* → delete the zip.
4. **Install.** Open `https://<your-site>/firetrack/install.php` **straight away** (until it's done, anyone who finds that page could set the admin password):
   - enter the database details,
   - choose the admin password,
   - keep *Load the researched fire stations* ticked.
5. **Set up** (logged in as admin):
   - *Settings*: check the start/end point (Aum Darshan CHS, Kandivali West, already filled in).
   - *Stations*: switch stations on/off (**Not going**), add any missing ones with a Google Maps link, or *Paste many* straight from the Excel sheet.
   - *Teams & Phones*: add each team with a name, description, colour, the leader's name/phone and a **4–8 digit PIN**, plus its cars. The leader's phone is added automatically when they tap **Start** on their team page; add Traccar phones (second phone per car) here.
   - *Plan*: give every station a team; **Best order** sorts each team's route.
6. **Phones.** Leaders log in and tap **Start** (pick their car if the team has several). For the second phone in each car, send the *Help* page link (`/firetrack/help.php`): it has the Traccar settings and the exact Server URL to copy. Phones that switch on the app appear under *Teams & Phones → New phones*; pick their team there.
7. **Saturday test run.** Drive one car around the block with Traccar on and watch it on *Live*. Afterwards: *Settings → Reset after a test run*.

Links to share on the day:

| Who | Link |
| --- | --- |
| Everyone (read-only map) | `https://<your-site>/firetrack/` |
| Team leaders | `https://<your-site>/firetrack/login.php?as=team` |
| Volunteer guide | `https://<your-site>/firetrack/help.php` |
| Admin | `https://<your-site>/firetrack/login.php` |

## Traccar Client settings (also on the Help page)

| Setting | Value |
| --- | --- |
| Device identifier | e.g. `car01` (or keep the number shown) |
| Server URL | `https://<your-site>/firetrack/api/traccar.php` |
| Location accuracy | Highest |
| Distance | 0 |
| Interval | 15 |
| Angle | 30 |
| Offline buffering | On |
| Wake lock | On |
| Stop detection | Off |

Allow location **all the time** and set the app's battery usage to **Unrestricted** (Android). Keep the phone on the car charger.

## Files

| Path | What |
| --- | --- |
| `index.php` | Public live map |
| `admin.php`, `plan.php`, `stations.php`, `teams.php`, `report.php`, `settings.php` | Admin screens |
| `team.php` | Team leader screen |
| `share.php` | Share location from the browser for a phone without a login (backup) |
| `manifest.php`, `sw.js`, `offline.html` | Installable app (PWA) |
| `help.php` | Volunteer guide |
| `api/traccar.php` | Receives Traccar Client locations (old query format and new JSON format) |
| `api/location.php` | Receives browser locations |
| `api/state.php` | Live data for the screens (public copy cached for 5 s) |
| `api/action.php` | Reached / Delivered / plan buttons |
| `inc/` | Database, logic (arrival detection, estimates, route order), Google Maps link reader |
| `data/stations.json` | Researched station list loaded by the installer |
| `tools/simulate.py` | Fake a car driving a route, for testing |

## Testing locally (XAMPP)

```bash
C:/xampp/php/php.exe -S 127.0.0.1:8090 -t D:/fire-station-tracker
```

Then open `http://127.0.0.1:8090/install.php`. To check every admin and team-leader action on a **fresh test copy** (it creates and deletes data, so never point it at the live site):

```bash
python tools/e2e_test.py http://127.0.0.1:8091 some-new-admin-password
```

To drive a fake car:

```bash
python tools/simulate.py --server http://127.0.0.1:8090/api/traccar.php --device car01 --stops "19.1982,72.8463;19.2060,72.8505;19.1982,72.8463" --speed 30 --fast 20 --dwell 180
```
