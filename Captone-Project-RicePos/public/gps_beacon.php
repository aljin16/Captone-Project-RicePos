<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>GPS Beacon - Edgar</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; padding: 18px; }
    .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; max-width: 520px; margin: auto; box-shadow: 0 6px 20px rgba(0,0,0,0.06); }
    .row { display:flex; gap: 8px; align-items:center; margin-bottom: 10px; }
    input, button { font-size: 16px; padding: 10px 12px; border-radius: 10px; border: 1px solid #d1d5db; }
    button { background:#22c55e; color:white; border-color:#22c55e; cursor:pointer; }
    button:disabled { opacity: 0.6; cursor: not-allowed; }
    .muted { color:#6b7280; font-size: 14px; }
    .ok { color:#16a34a; }
    .err { color:#b91c1c; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
  </style>
  <script>
    // Change this to match the token in gps_set.php
    const TOKEN = 'edgar-123';
    const POST_URL = 'gps_set.php';

    let watchId = null;

    function logMsg(msg, cls) {
      const el = document.getElementById('log');
      const p = document.createElement('div');
      p.className = cls || 'muted';
      p.textContent = msg;
      el.prepend(p);
    }

    function startBeacon() {
      if (!('geolocation' in navigator)) {
        logMsg('Geolocation not supported on this device', 'err');
        return;
      }
      const btnStart = document.getElementById('btnStart');
      const btnStop = document.getElementById('btnStop');
      btnStart.disabled = true; btnStop.disabled = false;

      const opts = { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 };
      watchId = navigator.geolocation.watchPosition(async (pos) => {
        const { latitude, longitude, accuracy } = pos.coords;
        const ts = Date.now();
        document.getElementById('coords').textContent = `${latitude.toFixed(6)}, ${longitude.toFixed(6)} (±${Math.round(accuracy)}m)`;
        try {
          const body = new URLSearchParams({ token: TOKEN, lat: latitude, lng: longitude, accuracy, ts });
          const res = await fetch(POST_URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
          const data = await res.json();
          if (data && data.ok) {
            logMsg(`Updated at ${new Date().toLocaleTimeString()}`, 'ok');
          } else {
            logMsg('Server error: ' + (data && data.error ? data.error : 'unknown'), 'err');
          }
        } catch (e) {
          logMsg('Network error: ' + e.message, 'err');
        }
      }, (err) => {
        logMsg('GPS error: ' + err.message, 'err');
      }, opts);
    }

    function stopBeacon() {
      const btnStart = document.getElementById('btnStart');
      const btnStop = document.getElementById('btnStop');
      btnStart.disabled = false; btnStop.disabled = true;
      if (watchId != null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
        logMsg('Beacon stopped', 'muted');
      }
    }
  </script>
  </head>
  <body>
    <div class="card">
      <h2>Live GPS Beacon (Edgar)</h2>
      <p class="muted">Keep this page open while delivering. Your live location will update automatically.</p>
      <div class="row">
        <button id="btnStart" onclick="startBeacon()">Start Sharing</button>
        <button id="btnStop" onclick="stopBeacon()" disabled>Stop</button>
      </div>
      <div class="row">
        <div class="muted">Current:</div>
        <div id="coords" class="mono">-</div>
      </div>
      <div id="log" class="mono" style="margin-top:10px;"></div>
    </div>
  </body>
</html>


