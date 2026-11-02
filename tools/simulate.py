"""
Pretend to be the Traccar Client app driving a car, for testing before the event.

  python tools/simulate.py --server http://127.0.0.1:8090/api/traccar.php --device car01 \
      --stops "19.1982,72.8463;19.2060,72.8505;19.1535,72.8403;19.1982,72.8463" --speed 30 --fast 20 --dwell 60

--stops   lat,lng pairs separated by ';' (start, stations..., end)
--speed   driving speed in km/h
--step    seconds between location updates (default 15, like the app)
--fast    time-lapse factor: 20 means 20x faster than real life
--dwell   seconds to wait at each stop (real-life seconds, divided by --fast)
--json    send the new Traccar Client JSON format instead of the old query string
--virtual-start N   replay the drive as if it started N minutes ago (timestamps follow the simulated clock,
                    so a 3-hour drive takes seconds and the report shows realistic times and speeds)
--vary    random +/- percentage on the speed of each stretch, like traffic (default 35)
"""
import argparse
import json
import math
import random
import time
import urllib.parse
import urllib.request


def dist(a, b):
    r = 6371000
    la1, lo1, la2, lo2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * r * math.asin(math.sqrt(h))


def bearing(a, b):
    la1, lo1, la2, lo2 = map(math.radians, (a[0], a[1], b[0], b[1]))
    y = math.sin(lo2 - lo1) * math.cos(la2)
    x = math.cos(la1) * math.sin(la2) - math.sin(la1) * math.cos(la2) * math.cos(lo2 - lo1)
    return (math.degrees(math.atan2(y, x)) + 360) % 360


CLOCK = {"t": None}


def now():
    return CLOCK["t"] if CLOCK["t"] is not None else time.time()


def send(args, lat, lng, speed_kmh, head, battery):
    if args.json:
        body = json.dumps({
            "device_id": args.device,
            "location": {
                "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(now())),
                "coords": {"latitude": lat, "longitude": lng, "speed": speed_kmh / 3.6, "heading": head, "accuracy": 8, "altitude": 10},
                "is_moving": speed_kmh > 0,
                "battery": {"level": battery / 100, "is_charging": True},
            },
        }).encode()
        req = urllib.request.Request(args.server, data=body, headers={"Content-Type": "application/json"})
    else:
        q = urllib.parse.urlencode({"id": args.device, "lat": f"{lat:.6f}", "lon": f"{lng:.6f}", "timestamp": int(now()),
                                    "speed": round(speed_kmh / 1.852, 1), "bearing": round(head), "accuracy": 8, "batt": battery})
        req = urllib.request.Request(args.server + ("&" if "?" in args.server else "?") + q, data=b"", method="POST")
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--server", required=True)
    p.add_argument("--device", required=True)
    p.add_argument("--stops", required=True)
    p.add_argument("--speed", type=float, default=25)
    p.add_argument("--step", type=float, default=15)
    p.add_argument("--fast", type=float, default=1)
    p.add_argument("--dwell", type=float, default=300)
    p.add_argument("--json", action="store_true")
    p.add_argument("--virtual-start", type=float, default=None)
    p.add_argument("--vary", type=float, default=35)
    args = p.parse_args()
    if args.virtual_start is not None:
        CLOCK["t"] = time.time() - args.virtual_start * 60

    def tick(seconds):
        if CLOCK["t"] is not None:
            CLOCK["t"] += seconds
        time.sleep(seconds / args.fast)

    stops = [tuple(map(float, s.split(","))) for s in args.stops.split(";") if s.strip()]
    battery = 90
    pos = stops[0]
    print(f"{args.device}: start at {pos}")
    for target in stops[1:]:
        while True:
            d = dist(pos, target)
            v = max(4.0, args.speed * (1 + random.uniform(-args.vary, args.vary) / 100))
            move = v / 3.6 * args.step
            head = bearing(pos, target)
            if d <= move:
                tick(max(1.0, d / (v / 3.6)))
                pos = target
                print(f"{args.device}: arrived {pos} -> HTTP {send(args, pos[0], pos[1], 0, head, battery)}")
                break
            f = move / d
            pos = (pos[0] + (target[0] - pos[0]) * f, pos[1] + (target[1] - pos[1]) * f)
            tick(args.step)
            status = send(args, pos[0], pos[1], v, head, battery)
            print(f"{args.device}: {pos[0]:.5f},{pos[1]:.5f} {d:.0f} m to go, {v:.0f} km/h -> HTTP {status}")
        waited = 0.0
        while waited < args.dwell:
            step = min(args.step, args.dwell - waited)
            tick(step)
            waited += step
            send(args, pos[0], pos[1], 0, 0, battery)
        battery = max(5, battery - 3)
    print(f"{args.device}: done")


if __name__ == "__main__":
    main()
