<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Enter Results</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  :root {
    --border:#d0d7de; --muted:#6e7781; --bg:#fafafa; --card:#fff;
    --radius:10px; --pad:6px; --pad-sm:4px; --gap:12px;
    --blue:#0d6efd; --red:#dc3545;
  }
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); margin: 24px; color:#111; }
  .container { max-width: 1000px; margin: 0 auto; }
  .card { background: var(--card); border:1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 16px; }
  .row { display:grid; gap: var(--gap); grid-template-columns: 1fr 1fr; }
  .row > div { display:flex; flex-direction:column; }
  label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }

  select, input[type="text"] {
    border:1px solid var(--border);
    border-radius:8px;
    padding: var(--pad-sm) var(--pad);
    font: inherit;
    line-height: 1.1;
    background:#fff;
  }

  input[type="text"] {
    padding: calc(var(--pad-sm) + 1.5px) var(--pad);
  }

  table { width:100%; border-collapse: collapse; }
  th, td { border:1px solid var(--border); padding: 8px; vertical-align: top; }
  th { background:#f5f5f5; text-align:left; }
  th.lane, td.lane { text-align:center; }
  th.lane { width:40px; }            /* lane column width */
  td.lane { width:40px; }

  tr {
    td:first-child span {
      background: #eee;
      width: 1.8rem;
      height: 1.8rem;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: auto;
      border-radius: 50%;
    }

    &:nth-child(1) td span {
      background: red;
    }
    &:nth-child(2) td span {
      background: pink;
    }
    &:nth-child(3) td span {
      background: yellow;
      border: 1px solid #ffcc00;
    }
    &:nth-child(4) td span {
      background: purple;
      color: #fff;
    }
    &:nth-child(5) td span {
      background: white;
      border: 1px solid silver;
    }
    &:nth-child(6) td span {
      background: orange;
    }
    &:nth-child(7) td span {
      background: black;
      color: #fff;
    }
    &:nth-child(8) td span {
      background: green;
      color: #fff;
    }
    &:nth-child(9) td span {
      background: silver;
    }
    &:nth-child(10) td span {
      background: lightblue;
    }
  }

  .slot-stack { display:flex; flex-direction:column; gap:6px; }
  .slot-stack select { width:100%; }

  td.place-cell select { width:100%; }
  td.time-cell  input.w-time { width:100%; text-align:right; box-sizing: border-box; } /* important */

  /* Controls row as flex: buttons left, status right */
  .controls { display:flex; align-items:center; gap:8px; margin-top: 12px; }
  .controls .spacer { flex:1; }
  .btn {
    display:inline-block; border:1px solid transparent; color:#fff; cursor:pointer;
    padding: 8px 14px; border-radius:8px; font-weight: 600;
  }
  .btn-primary { background: var(--blue); }
  .btn-danger  { background: var(--red); }
  .btn:disabled { opacity: .55; cursor: not-allowed; }

  .status { font-size: 14px; text-align: right; min-height: 1.2em; }
  .ok   { color: #0a7a0a; }
  .err  { color: #b00020; }
  .hidden { display:none; }

  th.place { width:100px; }
  th.time  { width:130px; }

  .invalid { border-color: #dc3545 !important; box-shadow: 0 0 0 2px rgba(220,53,69,0.1); }
</style>
</head>
<body>
<div class="container">

  <!-- Picker card -->
  <div class="card">
    <div class="row">
      <div>
        <label for="raceSelect">Race</label>
        <select id="raceSelect"></select>
      </div>
      <div>
        <label for="heatSelect">Heat</label>
        <select id="heatSelect">
          <option value="1">Heat A</option>
          <option value="2">Heat B</option>
          <option value="3">Heat C</option>
        </select>
      </div>
    </div>

    <div class="controls">
      <div class="left">
        <button id="saveBtn"   class="btn btn-primary">Save Results</button>
        <button id="deleteBtn" class="btn btn-danger hidden">Delete Heat</button>
      </div>
      <div class="spacer"></div>
      <div id="status" class="status"></div>
    </div>
  </div>

  <!-- Entry table -->
  <div class="card">
    <table>
      <thead>
        <tr>
          <th class="lane">Lane</th>
          <th>Club</th>
          <th class="place">Place</th>
          <th class="time">Time</th>
        </tr>
      </thead>
      <tbody id="rowsBody"></tbody>
    </table>
  </div>

</div>

<script>
(() => {
  // ===== CONFIG =====
  const API_BASE = 'api';
  const EP = {
    races:   () => `${API_BASE}/races`,
    clubs:   () => `${API_BASE}/clubs`,
    results: (raceId) => `${API_BASE}/results/${raceId}`,
    post:    () => `${API_BASE}/results`,
    del:     (raceId, heat) => `${API_BASE}/results/${raceId}/${heat}`,
  };

  // ===== DOM =====
  const raceSelect = document.getElementById('raceSelect');
  const heatSelect = document.getElementById('heatSelect');
  const rowsBody   = document.getElementById('rowsBody');
  const saveBtn    = document.getElementById('saveBtn');
  const deleteBtn  = document.getElementById('deleteBtn');
  const statusEl   = document.getElementById('status');

  // ===== State =====
  const state = { clubs: [], races: [] };

  // ===== Utils =====
  const setStatus = (msg, kind='') => { statusEl.textContent = msg || ''; statusEl.className = `status ${kind}`; };
  const genderLabel = (a, g) => g === 'M' ? (a === 'Open' ? 'men' : 'boys') : g === 'F' ? (a === 'Open' ? 'women' : 'girls') : 'mixed';
  const raceLabel = (r) => `Event #${r.id}: ${r.age} ${genderLabel(r.age, r.gender)} ${r.event}`;

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts);
    const txt = await res.text();
    let data = null; try { data = JSON.parse(txt); } catch {}
    if (!res.ok) throw new Error(data?.error || data?.details || txt || 'Request failed');
    return data;
  }

  // Parse time text -> seconds (float). Accept:
  //  - "mm:ss.ms" (e.g., 1:23.45)
  //  - "mm.ss.ms" (e.g., 1.23.45)
  //  - "ss" or "ss.ms" (e.g., 59.23)
  function parseTimeToSeconds(str) {
    if (!str || !str.trim()) return { seconds: null };
    const s = str.trim();

    // 1) mm:ss.ms (preferred)
    let m = s.match(/^(\d{1,2}):(\d{1,2})(?:\.(\d{1,3}))?$/);
    if (m) {
        const mm = parseInt(m[1], 10), ss = parseInt(m[2], 10);
        if (ss >= 60) return { error: 'Seconds must be < 60' };
        const frac = m[3] ? parseInt(m[3], 10) / Math.pow(10, m[3].length) : 0;
        return { seconds: mm * 60 + ss + frac };
    }

    // 2) plain seconds (e.g., 59.95)
    if (/^\d+(?:\.\d+)?$/.test(s)) {
        return { seconds: parseFloat(s) };
    }

    // 3) mm.ss.ms (fallback for "1.23.45" style)
    m = s.match(/^(\d{1,2})\.(\d{1,2})(?:\.(\d{1,3}))?$/);
    if (m) {
        const mm = parseInt(m[1], 10), ss = parseInt(m[2], 10);
        if (ss >= 60) return { error: 'Seconds must be < 60' };
        const frac = m[3] ? parseInt(m[3], 10) / Math.pow(10, m[3].length) : 0;
        return { seconds: mm * 60 + ss + frac };
    }

    return { error: 'Invalid time format. Use mm:ss.ms, mm.ss.ms, or seconds.' };
    }

  // ===== Build rows =====
  function buildRows() {
    rowsBody.innerHTML = '';
    for (let i = 1; i <= 10; i++) {
      const tr = document.createElement('tr');

      // Lane #
      const tdLane = document.createElement('td');
      tdLane.className = 'lane';
      tdLane.innerHTML = '<span>' + i + '</span>';
      tr.appendChild(tdLane);

      // Clubs: four stacked selects (no labels)
      const tdClubs = document.createElement('td');
      const stack = document.createElement('div');
      stack.className = 'slot-stack';
      for (let slot = 1; slot <= 4; slot++) {
        const sel = document.createElement('select');
        sel.dataset.slot = String(slot);
        const empty = document.createElement('option');
        empty.value = ''; empty.textContent = '';
        sel.appendChild(empty);
        for (const c of state.clubs) {
          const opt = document.createElement('option');
          opt.value = c.id; opt.textContent = c.name;
          sel.appendChild(opt);
        }
        stack.appendChild(sel);
      }
      tdClubs.appendChild(stack);
      tr.appendChild(tdClubs);

      // Place
      const tdPlace = document.createElement('td');
      tdPlace.className = 'place-cell';
      const selPlace = document.createElement('select');
      const blank = document.createElement('option'); blank.value=''; blank.textContent='';
      selPlace.appendChild(blank);
      for (let p = 1; p <= 10; p++) {
        const opt = document.createElement('option');
        opt.value = String(p); opt.textContent = String(p);
        selPlace.appendChild(opt);
      }
      const dnf = document.createElement('option'); dnf.value='DNF'; dnf.textContent='DNF/DQ';
      selPlace.appendChild(dnf);
      tdPlace.appendChild(selPlace);
      tr.appendChild(tdPlace);

      // Time (text)
      const tdTime = document.createElement('td');
      tdTime.className = 'time-cell';
      const inpTime = document.createElement('input');
      inpTime.type = 'text';
      inpTime.placeholder = 'mm:ss.ms';
      inpTime.className = 'w-time';
      inpTime.addEventListener('input', () => inpTime.classList.remove('invalid'));
      tdTime.appendChild(inpTime);
      tr.appendChild(tdTime);

      rowsBody.appendChild(tr);
    }
  }

  function clearFormValues() {
    rowsBody.querySelectorAll('tr').forEach(tr => {
      tr.querySelectorAll('select[data-slot]').forEach(sel => sel.value = '');
      const place = tr.querySelector('td.place-cell select'); place.value = '';
      const time = tr.querySelector('td.time-cell input'); time.value = ''; time.classList.remove('invalid');
    });
  }

  function populateFromResults(heats, heat) {
    clearFormValues();
    const h = heats.find(x => Number(x.heat) === Number(heat));
    const has = h && Array.isArray(h.results) && h.results.length > 0;
    deleteBtn.classList.toggle('hidden', !has);

    if (!has) return;

    const rows = rowsBody.querySelectorAll('tr');
    for (let i = 0; i < rows.length && i < h.results.length; i++) {
      const res = h.results[i];
      const tr = rows[i];

      // clubs
      const clubs = (res.clubs || []).map(c => c.id);
      for (let slot = 1; slot <= 4; slot++) {
        const sel = tr.querySelector(`select[data-slot="${slot}"]`);
        sel.value = clubs[slot - 1] != null ? String(clubs[slot - 1]) : '';
      }

      // place
      const selPlace = tr.querySelector('td.place-cell select');
      selPlace.value = res.place == null ? '' : String(res.place);

      // time -> display mm:ss.ss if >=60 else seconds with 2 dp
      const inp = tr.querySelector('td.time-cell input');
      if (res.time == null || res.time === '') {
        inp.value = '';
      } else {
        const secs = Number(res.time);
        if (!Number.isNaN(secs) && secs >= 60) {
          const mm = Math.floor(secs / 60);
          const ss = secs - mm*60;
          inp.value = `${mm}:${ss.toFixed(2).padStart(5,'0')}`;
        } else if (!Number.isNaN(secs)) {
          inp.value = secs.toFixed(2);
        } else {
          inp.value = '';
        }
      }
    }
  }

  function gatherPayloadWithValidation() {
    const raceId = Number(raceSelect.value);
    const heat = Number(heatSelect.value);
    const results = [];
    let invalidMsg = null;

    rowsBody.querySelectorAll('tr').forEach(tr => {
      const slots = [];
      for (let s = 1; s <= 4; s++) {
        const sel = tr.querySelector(`select[data-slot="${s}"]`);
        const v = sel.value.trim();
        if (v !== '') slots.push(Number(v));
      }
      if (slots.length === 0) return; // only rows with ≥1 club

      const selPlace = tr.querySelector('td.place-cell select');
      let place = null;
      if (selPlace.value !== '' && selPlace.value !== 'DNF') place = Number(selPlace.value);

      const inp = tr.querySelector('td.time-cell input');
      const t = inp.value.trim();
      const { seconds, error } = parseTimeToSeconds(t);
      if (t !== '' && error) {
        inp.classList.add('invalid');
        invalidMsg = invalidMsg || error;
      } else {
        inp.classList.remove('invalid');
      }

      results.push({ place, time: seconds, clubs: slots.slice(0,4) });
    });

    if (invalidMsg) return { error: invalidMsg };
    return { payload: { raceId, heat, results } };
  }

  // ===== API =====
  async function loadClubs() {
    const data = await fetchJSON(EP.clubs());
    state.clubs = Array.isArray(data) ? data : (data.data || []);
  }
  async function loadRaces() {
    const data = await fetchJSON(EP.races());
    state.races = data.data || [];
    raceSelect.innerHTML = '';
    for (const r of state.races) {
      const opt = document.createElement('option');
      opt.value = r.id; opt.textContent = raceLabel(r);
      raceSelect.appendChild(opt);
    }
  }
  async function loadHeats(raceId) {
    const data = await fetchJSON(EP.results(raceId));
    return data.data?.heats || [];
  }

  async function saveResults() {
    const check = gatherPayloadWithValidation();
    if (check.error) { setStatus(check.error, 'err'); return; }
    const { payload } = check;

    setStatus('Saving…');
    try {
      await fetchJSON(EP.post(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      setStatus('Saved.', 'ok');
      // Reload current heat so delete button becomes visible and data is synced
      const heats = await loadHeats(payload.raceId);
      populateFromResults(heats, payload.heat);
      setTimeout(() => setStatus('', ''), 1200);
    } catch (e) {
      setStatus(String(e.message || e), 'err');
    }
  }

  async function deleteHeat() {
    const raceId = Number(raceSelect.value);
    const heat = Number(heatSelect.value);
    if (!raceId || !heat) return;
    if (!confirm(`Delete all results for Event #${raceId}, Heat ${heat}?`)) return;

    setStatus('Deleting…');
    try {
      await fetchJSON(EP.del(raceId, heat), { method: 'DELETE' });
      clearFormValues();
      deleteBtn.classList.add('hidden');
      setStatus('Deleted.', 'ok');
      setTimeout(() => setStatus('', ''), 1200);
    } catch (e) {
      setStatus(String(e.message || e), 'err');
    }
  }

  // ===== Events =====
  raceSelect.addEventListener('change', async () => {
    heatSelect.value = '1';                   // reset to heat 1 on race change
    setStatus('Loading race…');
    try {
      const heats = await loadHeats(Number(raceSelect.value));
      populateFromResults(heats, 1);
      setStatus('', '');
    } catch (e) { setStatus(String(e.message || e), 'err'); }
  });

  heatSelect.addEventListener('change', async () => {
    if (!raceSelect.value) return;
    setStatus('Loading heat…');
    try {
      const heats = await loadHeats(Number(raceSelect.value));
      populateFromResults(heats, Number(heatSelect.value));
      setStatus('', '');
    } catch (e) { setStatus(String(e.message || e), 'err'); }
  });

  saveBtn.addEventListener('click', saveResults);
  deleteBtn.addEventListener('click', deleteHeat);

  // ===== Init =====
  (async function init() {
    try {
      setStatus('Loading…');
      await loadClubs();
      buildRows();
      await loadRaces();
      const first = state.races.find(r => Number(r.id) === 1) || state.races[0];
      if (first) {
        raceSelect.value = String(first.id);
        heatSelect.value = '1';
        const heats = await loadHeats(Number(first.id));
        populateFromResults(heats, 1);
      }
      setStatus('', '');
    } catch (e) {
      setStatus(String(e.message || e), 'err');
    }
  })();
})();
</script>
</body>
</html>
