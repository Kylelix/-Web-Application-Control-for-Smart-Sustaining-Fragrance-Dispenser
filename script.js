(() => {
  'use strict';

  const APP = window.APP || { role: 'client', room: null, device: 'SFD-01', csrf: '', pollMs: 1000 };
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  let activeDevice = APP.device || 'SFD-01';
  let lastOnline = false;
  let lastTelemetry = null;
  let lastPermissions = null;
  let pollTimer = null;
  let pollInFlight = false;
  let logPage = 1;
  let logPages = 1;
  let lastTrendFetchAt = 0;
  let lastLogsFetchAt = 0;
  const pendingControls = new Set();

  function showToast(message, type = 'info') {
    const container = $('#toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type === 'error' ? 'toast-error' : (type === 'warn' ? 'toast-warn' : '')}`;
    toast.textContent = message;
    container.prepend(toast);
    setTimeout(() => {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 4500);
  }

  /**
   * Maps raw SQL activity_log events/states to one of:
   * Misted, Paused, Standby, Locked (single mapping function used across UI).
   */
  function mapEventToTag(row) {
    const ev = String(row.event || '').toUpperCase();
    const st = String(row.state || '').toUpperCase();
    const dt = String(row.detail || '').toLowerCase();

    if (ev === 'MIST_START' || st === 'MISTING' || (ev === 'COMMAND' && dt.includes('spray'))) {
      return { label: 'Misted', css: 'good' };
    }
    if (ev === 'LOCK_ON' || st === 'LOCKED_OUT' || ev === 'OFFLINE' || dt.includes('empty') || dt.includes('high voc')) {
      return { label: 'Locked', css: 'alert' };
    }
    if (ev === 'MIST_END' || st === 'COOLDOWN' || st === 'OFF' || dt.includes('power off')) {
      return { label: 'Paused', css: 'warn' };
    }
    return { label: 'Standby', css: 'idle' };
  }

  function formatDuration(sec) {
    const s = Math.max(0, Number(sec) || 0);
    if (s < 60) return `${s} s`;
    const m = Math.floor(s / 60);
    const r = s % 60;
    return r > 0 ? `${m}m ${r}s` : `${m}m`;
  }

  function formatTimeFromIso(iso) {
    if (!iso) return '--:--:--';
    const parts = String(iso).split(/[ T]/);
    return parts.length > 1 ? parts[1] : String(iso);
  }

  function setActiveButton(selector, attr, val) {
    $$(selector).forEach((btn) => {
      const btnVal = btn.getAttribute(attr);
      btn.classList.toggle('active', String(btnVal) === String(val));
    });
  }

  function setGroupDisabled(selector, disabled) {
    $$(selector).forEach((btn) => {
      btn.disabled = Boolean(disabled);
    });
  }

  function renderTelemetry(payload) {
    const online = Boolean(payload.online);
    const data = payload.data || {};
    const perms = payload.permissions || {};
    lastOnline = online;
    lastTelemetry = data;
    lastPermissions = perms;

    const sbState = $('#sidebarDeviceState');
    if (sbState) {
      sbState.className = `device-state ${online ? '' : 'is-offline'}`;
      const span = sbState.querySelector('span');
      if (span) span.textContent = online ? 'Online' : 'Offline';
    }
    const lastUp = $('#lastUpdated');
    if (lastUp) {
      lastUp.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    const heroCard = $('#heroStatusCard') || $('#overview');
    const heroIcon = $('#heroIcon');
    const heroDevLbl = $('#heroDeviceLabel');
    const heroLbl = $('#heroStateLabel');
    const heroPill = $('#heroPhasePill');
    const heroDetail = $('#heroStateDetail');

    if (heroDevLbl) heroDevLbl.textContent = `${payload.device_id} LIVE STATE`;

    let heroClass = 'state-offline';
    let iconClass = 'fa-solid fa-plug';
    let labelText = 'OFFLINE';
    let pillText = 'Disconnected';
    let detailText = payload.last_seen ? `Last seen ${formatTimeFromIso(payload.last_seen)}` : 'Device unreachable';

    if (online) {
      const st = data.state || 'IDLE';
      if (st === 'OFF') {
        heroClass = 'state-off';
        iconClass = 'fa-solid fa-power-off';
        labelText = 'OFF';
        pillText = 'Powered Off';
        detailText = 'Master device power is turned off.';
      } else if (st === 'LOCKED_OUT') {
        heroClass = 'state-locked';
        iconClass = 'fa-solid fa-triangle-exclamation';
        labelText = 'LOCKED OUT';
        pillText = 'Safety Interlock';
        detailText = data.lock && data.lock.empty_tank
          ? 'Reason: Empty tank lock — refill water reservoir.'
          : 'Reason: High VOC lock — ventilation recommended.';
      } else if (st === 'MISTING') {
        heroClass = 'state-misting';
        iconClass = 'fa-solid fa-droplet';
        labelText = 'MISTING';
        pillText = `Phase: ${data.phase || 'MIST'}`;
        const pInfo = (data.profiles && data.profiles[data.profile]) || { mist_s: '--' };
        detailText = `${data.profile} cycle active (${pInfo.mist_s} s burst).`;
      } else if (st === 'COOLDOWN') {
        heroClass = 'state-cooldown';
        iconClass = 'fa-solid fa-clock-rotate-left';
        labelText = 'COOLDOWN';
        pillText = data.phase === 'FAN_POST' ? 'Chamber Clear (Fan ON)' : 'Resting';
        detailText = `Cooldown: ${data.cooldown_remaining_s || 0} s remaining.`;
      } else {
        heroClass = 'state-idle';
        iconClass = 'fa-solid fa-circle-check';
        labelText = 'IDLE';
        pillText = `Mode: ${data.mode || 'SENSOR'}`;
        detailText = (data.mode === 'TIMED' && data.next_timed_s > 0)
          ? `Next scheduled mist in ${formatDuration(data.next_timed_s)}.`
          : 'Standby and monitoring room VOC response.';
      }
    }

    if (heroCard) heroCard.className = `hero-status ${heroClass}`;
    if (heroIcon) heroIcon.className = iconClass;
    if (heroLbl) heroLbl.textContent = labelText;
    if (heroPill) heroPill.textContent = pillText;
    if (heroDetail) heroDetail.textContent = detailText;

    const chipConn = $('#chipConnection');
    if (chipConn) {
      chipConn.className = `summary-chip ${online ? 'good' : 'neutral'}`;
      chipConn.innerHTML = '';
      const dot = document.createElement('span');
      chipConn.appendChild(dot);
      chipConn.appendChild(document.createTextNode(online ? ' Connected' : ' Offline'));
    }

    const chipTank = $('#chipTank');
    if (chipTank) {
      const tankOk = online && Boolean(data.water);
      chipTank.className = `summary-chip ${!online ? 'neutral' : (tankOk ? 'good' : 'alert')}`;
      chipTank.innerHTML = '';
      const dot = document.createElement('span');
      chipTank.appendChild(dot);
      chipTank.appendChild(document.createTextNode(!online ? ' Tank: Offline' : (tankOk ? ' Tank OK' : ' Tank Empty')));
    }

    const chipSafety = $('#chipSafety');
    if (chipSafety) {
      let sClass = 'neutral';
      let sText = ' Misting: Offline';
      if (online) {
        if (!data.power) {
          sClass = 'neutral';
          sText = ' Misting locked: Power Off';
        } else if (data.lock && data.lock.empty_tank) {
          sClass = 'alert';
          sText = ' Misting locked: Empty tank';
        } else if (data.lock && data.lock.high_voc) {
          sClass = 'alert';
          sText = ' Misting locked: High VOC';
        } else if ((data.cooldown_remaining_s || 0) > 0) {
          sClass = 'warn';
          sText = ` Cooldown: ${data.cooldown_remaining_s} s`;
        } else {
          sClass = 'safe';
          sText = ' Misting allowed';
        }
      }
      chipSafety.className = `summary-chip ${sClass}`;
      chipSafety.innerHTML = '';
      const dot = document.createElement('span');
      chipSafety.appendChild(dot);
      chipSafety.appendChild(document.createTextNode(sText));
    }

    const vocVal = $('#vocValue');
    const vocSourceBadge = $('#vocSourceBadge');
    const vocBaselineText = $('#vocBaselineText');

    if (vocVal) {
      vocVal.textContent = (online && data.voc_ready && data.voc_index !== null) ? data.voc_index : '--';
    }
    if (vocSourceBadge) {
      if (!online) {
        vocSourceBadge.textContent = 'Offline';
        vocSourceBadge.className = '';
      } else if (!data.voc_ready) {
        vocSourceBadge.textContent = 'Warming up...';
        vocSourceBadge.className = 'badge-sim';
      } else if (data.voc_source !== 'SGP41') {
        vocSourceBadge.textContent = 'Simulated';
        vocSourceBadge.className = 'badge-sim';
      } else {
        vocSourceBadge.textContent = 'SGP41';
        vocSourceBadge.className = '';
      }
    }
    if (vocBaselineText) {
      vocBaselineText.textContent = `Baseline ${data.baseline !== undefined ? data.baseline : '--'}`;
    }

    const vocStatusEl = $('#vocStatus');
    const vocBadgeEl = $('#vocBadge');
    const vocThreshEl = $('#vocThresholdsText');
    const statusMap = { LOW: 'Low', NORMAL: 'Normal', HIGH: 'High', UNKNOWN: 'Warming Up' };
    const stText = online ? (statusMap[data.voc_status] || 'Normal') : 'Offline';
    if (vocStatusEl) {
      vocStatusEl.textContent = stText;
      vocStatusEl.className = `metric-value text-value ${data.voc_status === 'HIGH' ? 'is-alert' : ''}`;
    }
    if (vocBadgeEl) {
      vocBadgeEl.textContent = !online ? '--' : (data.lock && data.lock.high_voc ? 'Locked' : stText);
    }
    if (vocThreshEl && data.thresholds) {
      const lo = data.thresholds.low ?? 100;
      const hi = data.thresholds.high ?? 180;
      vocThreshEl.textContent = `Low < ${lo} · Normal ${lo}–${hi - 1} · High >= ${hi}`;
    }

    const tankMetricVal = $('#tankMetricValue');
    const tankMetricSub = $('#tankMetricSub');
    if (tankMetricVal) {
      tankMetricVal.textContent = online ? (data.water ? 'OK' : 'EMPTY') : '--';
      tankMetricVal.className = `metric-value text-value ${!online ? '' : (data.water ? 'is-good' : 'is-alert')}`;
    }
    if (tankMetricSub) {
      tankMetricSub.textContent = !online
        ? 'Waiting for telemetry'
        : (data.water ? 'Liquid detected' : 'Empty - refill required');
    }

    const devModeBadge = $('#deviceModeBadge');
    const devStateMetric = $('#deviceStateMetric');
    const devStateSub = $('#deviceStateSub');
    const devLabelMap = {
      OFF: 'Off',
      IDLE: 'Idle',
      MISTING: 'Misting',
      COOLDOWN: 'Cooldown',
      LOCKED_OUT: 'Locked Out',
    };
    if (devModeBadge) devModeBadge.textContent = online ? (data.mode || '--') : 'OFFLINE';
    if (devStateMetric) {
      devStateMetric.textContent = online ? (devLabelMap[data.state] || data.state) : 'Offline';
      devStateMetric.className = `metric-value text-value ${online && data.state === 'LOCKED_OUT' ? 'is-alert' : ''}`;
    }
    if (devStateSub) {
      devStateSub.textContent = online
        ? `Fan: ${data.fan ? 'On' : 'Off'} · Mist: ${data.mist ? 'On' : 'Off'}`
        : 'Hardware unreachable';
    }

    const tankVisualBox = $('#tankVisualBox');
    const tankVisualLabel = $('#tankVisualLabel');
    const tankPill = $('#tankPill');
    const tankStatus = $('#tankStatus');
    const tankDesc = $('#tankDesc');
    const tankLock = $('#tankLock');

    if (tankVisualBox && tankVisualLabel && tankPill && tankStatus && tankDesc && tankLock) {
      if (!online) {
        tankVisualBox.className = 'tank-binary-visual is-unknown';
        tankVisualLabel.textContent = '--';
        tankPill.className = 'status-pill neutral';
        tankPill.textContent = 'Offline';
        tankStatus.textContent = 'Sensor Offline';
        tankDesc.textContent = 'Cannot verify water reservoir while device is offline.';
        tankLock.textContent = '--';
      } else if (data.water) {
        tankVisualBox.className = 'tank-binary-visual is-ok';
        tankVisualLabel.textContent = 'OK';
        tankPill.className = 'status-pill good';
        tankPill.textContent = 'OK';
        tankStatus.textContent = 'Liquid detected';
        tankDesc.textContent = 'Reservoir water is detected. Dry-burn protection lock is inactive.';
        tankLock.textContent = data.lock && data.lock.empty_tank ? 'Active' : 'Inactive';
      } else {
        tankVisualBox.className = 'tank-binary-visual is-empty';
        tankVisualLabel.textContent = 'EMPTY';
        tankPill.className = 'status-pill alert';
        tankPill.textContent = 'Empty';
        tankStatus.textContent = 'Empty - refill required';
        tankDesc.textContent = 'No liquid detected by XKC-Y23A sensor. Atomizer is locked out to protect the piezo disc.';
        tankLock.textContent = 'Active';
      }
    }

    if (data.profiles) {
      if (data.profiles.MILD && $('#profileSubMILD')) {
        $('#profileSubMILD').textContent = `${data.profiles.MILD.mist_s} s mist, ${data.profiles.MILD.cooldown_s} s rest`;
      }
      if (data.profiles.STRONG && $('#profileSubSTRONG')) {
        $('#profileSubSTRONG').textContent = `${data.profiles.STRONG.mist_s} s mist, ${data.profiles.STRONG.cooldown_s} s rest`;
      }
    }

    setActiveButton('[data-group="mode"] button', 'data-mode', data.mode || '');
    setActiveButton('[data-group="profile"] button', 'data-profile', data.profile || '');
    setActiveButton('[data-quick-profile] button', 'data-profile', data.profile || '');
    setActiveButton('[data-group="interval"] button', 'data-minutes', data.interval_min || '');

    const powerToggle = $('#powerToggle');
    const quickPower = $('#quickPowerToggle');
    if (powerToggle && !pendingControls.has('POWER')) {
      powerToggle.checked = Boolean(data.power);
      powerToggle.disabled = !online || !perms.power;
    }
    if (quickPower && !pendingControls.has('POWER')) {
      quickPower.checked = Boolean(data.power);
      quickPower.disabled = !online || !perms.power;
    }

    if (!pendingControls.has('SET_MODE')) {
      setGroupDisabled('[data-group="mode"] button', !online || !perms.mode);
    }
    if (!pendingControls.has('SET_PROFILE')) {
      const profDis = !online || !perms.profile || !data.power;
      setGroupDisabled('[data-group="profile"] button', profDis);
      setGroupDisabled('[data-quick-profile] button', profDis);
    }
    if (!pendingControls.has('SET_INTERVAL')) {
      setGroupDisabled('[data-group="interval"] button', !online || !perms.interval || !data.power);
    }

    const baselineBtn = $('#baselineBtn');
    if (baselineBtn && !pendingControls.has('SET_BASELINE')) {
      baselineBtn.disabled = !online || !perms.baseline || !data.voc_ready;
    }
    const exportBtn = $('#exportBtn');
    if (exportBtn) {
      exportBtn.disabled = !perms.export;
    }

    const fanTestBtn = $('#fanTestBtn');
    if (fanTestBtn && !pendingControls.has('FAN')) {
      fanTestBtn.disabled = !online || !perms.fan || !data.power || data.state === 'MISTING' || data.phase === 'FAN_POST';
    }

    let sprayDisabled = false;
    let hintText = 'Manual command is ready.';

    if (!online) {
      sprayDisabled = true;
      hintText = 'Device is offline.';
    } else if (!perms.spray) {
      sprayDisabled = true;
      hintText = 'Misting permission denied.';
    } else if (!data.power) {
      sprayDisabled = true;
      hintText = 'Device is powered off.';
    } else if (data.lock && data.lock.empty_tank) {
      sprayDisabled = true;
      hintText = 'Misting disabled due to empty tank lock.';
    } else if (data.lock && data.lock.high_voc) {
      sprayDisabled = true;
      hintText = 'Misting disabled due to high VOC lock. Ventilation recommended.';
    } else if (data.state === 'MISTING') {
      sprayDisabled = true;
      hintText = 'Misting in progress.';
    } else if ((data.cooldown_remaining_s || 0) > 0) {
      sprayDisabled = true;
      hintText = `Cooldown: ${data.cooldown_remaining_s} s remaining.`;
    }

    const sprayBtn = $('#sprayBtn');
    const sprayHint = $('#sprayHint');
    const quickSprayBtn = $('#quickSprayBtn');
    const quickSprayHint = $('#quickSprayHint');

    if (sprayBtn && !pendingControls.has('SPRAY_NOW')) sprayBtn.disabled = sprayDisabled;
    if (sprayHint) sprayHint.textContent = hintText;
    if (quickSprayBtn && !pendingControls.has('SPRAY_NOW')) quickSprayBtn.disabled = sprayDisabled;
    if (quickSprayHint) quickSprayHint.textContent = hintText;
  }
  async function fetchJsonWithTimeout(url, options = {}, timeoutMs = 2500) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const resp = await fetch(url, { ...options, signal: controller.signal });
      if (resp.status === 401) {
        window.location.href = 'login.php?error=session';
        return null;
      }
      const data = await resp.json().catch(() => null);
      return { status: resp.status, ok: resp.ok, json: data };
    } finally {
      clearTimeout(timer);
    }
  }

  async function pollTelemetry(forceImmediateLogs = false) {
    if (pollInFlight || document.hidden) return;
    pollInFlight = true;
    try {
      const res = await fetchJsonWithTimeout(
        `api/telemetry.php?device=${encodeURIComponent(activeDevice)}`,
        { cache: 'no-store' },
        2500
      );
      if (res && res.json && res.json.ok) {
        renderTelemetry(res.json);
      }
    } catch (_err) {
      if (lastTelemetry !== null) {
        renderTelemetry({
          ok: true,
          device_id: activeDevice,
          online: false,
          last_seen: null,
          data: lastTelemetry,
          permissions: lastPermissions || {},
        });
      }
    } finally {
      pollInFlight = false;
    }

    const now = Date.now();
    if (forceImmediateLogs || (now - lastLogsFetchAt) >= 5000) {
      refreshLogsAndNotifs();
    }
    if (forceImmediateLogs || (now - lastTrendFetchAt) >= 30000) {
      refreshTrend();
    }
  }

  function scheduleNextPoll() {
    if (pollTimer) clearTimeout(pollTimer);
    if (document.hidden) return;
    const delay = lastOnline ? (APP.pollMs || 1000) : 2000;
    pollTimer = setTimeout(async () => {
      await pollTelemetry(false);
      scheduleNextPoll();
    }, delay);
  }

  async function sendCommand(payload, controlEls = []) {
    const cmdKey = payload.command;
    if (pendingControls.has(cmdKey)) return;

    pendingControls.add(cmdKey);
    controlEls.forEach((el) => {
      if (!el) return;
      el.classList.add('is-pending');
      el.setAttribute('aria-busy', 'true');
      if ('disabled' in el) el.disabled = true;
    });

    try {
      const res = await fetchJsonWithTimeout(
        'api/control.php',
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': APP.csrf || '',
          },
          body: JSON.stringify({ device: activeDevice, ...payload }),
        },
        5000
      );
      if (!res) return;

      const body = res.json || {};
      if (res.ok && body.ok) {
        showToast(body.message || 'Command accepted.', 'info');
        if (body.data && lastPermissions) {
          renderTelemetry({
            ok: true,
            device_id: activeDevice,
            online: true,
            data: body.data,
            permissions: lastPermissions,
          });
        }
      } else {
        const msg = body.message || `Command refused (${body.error || res.status}).`;
        showToast(msg, res.status === 409 ? 'warn' : 'error');
      }
    } catch (err) {
      const msg = err && err.name === 'AbortError'
        ? 'Command timed out after 5 s.'
        : 'Failed to reach gateway.';
      showToast(msg, 'error');
    } finally {
      pendingControls.delete(cmdKey);
      controlEls.forEach((el) => {
        if (!el) return;
        el.classList.remove('is-pending');
        el.removeAttribute('aria-busy');
      });
      await pollTelemetry(true);
    }
  }

  function getLogDeviceParam() {
    const devSel = $('#logDeviceFilter');
    if (!devSel) return activeDevice;
    return devSel.value === 'ACTIVE' ? activeDevice : devSel.value;
  }

  async function refreshNotifications() {
    const list = $('#notificationList');
    if (!list) return;
    try {
      const res = await fetchJsonWithTimeout(
        `api/logs.php?type=notifications&device=${encodeURIComponent(activeDevice)}`,
        { cache: 'no-store' },
        2500
      );
      if (!res || !res.ok || !res.json) return;

      const items = res.json.items || [];
      list.innerHTML = '';
      if (items.length === 0) {
        const li = document.createElement('li');
        const st = document.createElement('strong');
        st.textContent = 'No alerts yet';
        const sp = document.createElement('span');
        sp.className = 'notification-msg';
        sp.textContent = 'Lock transitions and commands will appear here.';
        li.append(st, sp);
        list.appendChild(li);
        return;
      }

      items.forEach((item) => {
        const li = document.createElement('li');
        const head = document.createElement('div');
        head.className = 'notification-head';
        const title = document.createElement('strong');
        title.textContent = item.title || item.event;
        const tm = document.createElement('span');
        tm.className = 'notification-time';
        tm.textContent = item.time || '';
        head.append(title, tm);

        const msg = document.createElement('span');
        msg.className = 'notification-msg';
        msg.textContent = item.message || '';
        li.append(head, msg);
        list.appendChild(li);
      });
    } catch (_err) {
      // Passive UI failure ignored cleanly
    }
  }

  async function refreshLogs() {
    const logBody = $('#logBody');
    if (!logBody) return;
    lastLogsFetchAt = Date.now();

    const logDev = getLogDeviceParam();
    const dateVal = ($('#logDateFilter') && $('#logDateFilter').value) || '';
    const qs = new URLSearchParams({
      device: logDev,
      limit: '8',
      page: String(logPage),
    });
    if (dateVal) qs.set('date', dateVal);

    try {
      const res = await fetchJsonWithTimeout(`api/logs.php?${qs.toString()}`, { cache: 'no-store' }, 2500);
      if (!res || !res.ok || !res.json) return;

      const rows = res.json.rows || [];
      logPages = Math.max(1, Number(res.json.pages) || 1);
      logPage = Math.min(logPage, logPages);

      const pageInfo = $('#logPageInfo');
      if (pageInfo) pageInfo.textContent = `Page ${logPage} of ${logPages} (${res.json.total || 0} rows)`;
      if ($('#logPrevBtn')) $('#logPrevBtn').disabled = logPage <= 1;
      if ($('#logNextBtn')) $('#logNextBtn').disabled = logPage >= logPages;

      logBody.innerHTML = '';
      if (rows.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 5;
        td.textContent = 'No activity logs recorded yet for this selection.';
        tr.appendChild(td);
        logBody.appendChild(tr);
        return;
      }

      rows.forEach((row) => {
        const tr = document.createElement('tr');
        const tag = mapEventToTag(row);

        const tdTime = document.createElement('td');
        tdTime.textContent = row.time || '--:--:--';

        const tdVoc = document.createElement('td');
        tdVoc.textContent = row.voc_index !== null && row.voc_index !== undefined ? String(row.voc_index) : '--';

        const tdTank = document.createElement('td');
        tdTank.textContent = row.tank_status || '--';

        const tdMode = document.createElement('td');
        const modeLabel = row.mode && row.mode !== '--' ? row.mode : row.event;
        tdMode.textContent = logDev === 'ALL' ? `${modeLabel} (${row.device_id})` : modeLabel;

        const tdAction = document.createElement('td');
        const pill = document.createElement('span');
        pill.className = `table-tag ${tag.css}`;
        pill.textContent = tag.label;
        tdAction.appendChild(pill);

        tr.append(tdTime, tdVoc, tdTank, tdMode, tdAction);
        logBody.appendChild(tr);
      });
    } catch (_err) {
      // Retry next interval
    }
  }

  function refreshLogsAndNotifs() {
    refreshLogs();
    refreshNotifications();
  }

  async function refreshTrend() {
    const barsEl = $('#chartBars');
    const labelsEl = $('#chartLabels');
    if (!barsEl || !labelsEl) return;
    lastTrendFetchAt = Date.now();

    try {
      const res = await fetchJsonWithTimeout(
        `api/logs.php?type=trend&limit=7&device=${encodeURIComponent(activeDevice)}`,
        { cache: 'no-store' },
        2500
      );
      if (!res || !res.ok || !res.json) return;

      const pts = res.json.points || [];
      barsEl.innerHTML = '';
      labelsEl.innerHTML = '';

      if (pts.length === 0) {
        const msg = document.createElement('div');
        msg.className = 'chart-empty-msg';
        msg.textContent = 'No 30s SAMPLE readings logged yet.';
        barsEl.appendChild(msg);
        return;
      }

      const maxVal = Math.max(200, ...pts.map((p) => Number(p.voc_index) || 0));
      pts.forEach((pt) => {
        const val = Number(pt.voc_index) || 0;
        const pct = Math.max(18, Math.min(100, Math.round((val / maxVal) * 100)));
        const st = String(pt.voc_status || '').toUpperCase();
        const barClass = st === 'LOW' ? 'bar-low' : (st === 'HIGH' ? 'bar-high' : 'bar-normal');

        const bar = document.createElement('div');
        bar.className = `chart-bar ${barClass}`;
        bar.style.height = `${pct}%`;
        const valSpan = document.createElement('span');
        valSpan.textContent = String(val);
        bar.appendChild(valSpan);
        barsEl.appendChild(bar);

        const lbl = document.createElement('span');
        lbl.textContent = pt.time || '--';
        labelsEl.appendChild(lbl);
      });
    } catch (_err) {
      // Retry on next 30 s tick
    }
  }

  function bindControls() {
    [$('#powerToggle'), $('#quickPowerToggle')].forEach((toggle) => {
      if (!toggle) return;
      toggle.addEventListener('change', (e) => {
        sendCommand({ command: 'POWER', value: Boolean(e.target.checked) }, [$('#powerToggle'), $('#quickPowerToggle')]);
      });
    });

    const modeGroup = $('[data-group="mode"]');
    if (modeGroup) {
      modeGroup.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-mode]');
        if (!btn || btn.disabled) return;
        sendCommand({ command: 'SET_MODE', mode: btn.getAttribute('data-mode') }, [modeGroup]);
      });
    }

    [$('[data-group="profile"]'), $('[data-quick-profile]')].forEach((group) => {
      if (!group) return;
      group.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-profile]');
        if (!btn || btn.disabled) return;
        sendCommand(
          { command: 'SET_PROFILE', profile: btn.getAttribute('data-profile') },
          [$('[data-group="profile"]'), $('[data-quick-profile]')]
        );
      });
    });

    const intervalGroup = $('[data-group="interval"]');
    if (intervalGroup) {
      intervalGroup.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-minutes]');
        if (!btn || btn.disabled) return;
        const mins = Number(btn.getAttribute('data-minutes'));
        sendCommand({ command: 'SET_INTERVAL', minutes: mins }, [intervalGroup]);
      });
    }

    [$('#sprayBtn'), $('#quickSprayBtn')].forEach((btn) => {
      if (!btn) return;
      btn.addEventListener('click', () => {
        if (btn.disabled) return;
        sendCommand({ command: 'SPRAY_NOW' }, [$('#sprayBtn'), $('#quickSprayBtn')]);
      });
    });

    const fanTestBtn = $('#fanTestBtn');
    if (fanTestBtn) {
      fanTestBtn.addEventListener('click', () => {
        if (fanTestBtn.disabled) return;
        sendCommand({ command: 'FAN', value: true, duration_s: 5 }, [fanTestBtn]);
      });
    }

    const baselineBtn = $('#baselineBtn');
    if (baselineBtn) {
      baselineBtn.addEventListener('click', () => {
        if (baselineBtn.disabled) return;
        sendCommand({ command: 'SET_BASELINE' }, [baselineBtn]);
      });
    }

    const exportBtn = $('#exportBtn');
    if (exportBtn) {
      exportBtn.addEventListener('click', () => {
        const logDev = getLogDeviceParam();
        const dateVal = ($('#logDateFilter') && $('#logDateFilter').value) || '';
        const qs = new URLSearchParams();
        if (logDev && logDev !== 'ALL') qs.set('device', logDev);
        if (dateVal) {
          qs.set('from', dateVal);
          qs.set('to', dateVal);
        }
        window.location.href = `api/export_csv.php?${qs.toString()}`;
      });
    }

    const devSelect = $('#adminDeviceSelect');
    if (devSelect) {
      devSelect.addEventListener('change', (e) => {
        activeDevice = e.target.value;
        logPage = 1;
        showToast(`Switched active prototype to ${activeDevice}.`, 'info');
        pollTelemetry(true);
      });
    }

    const logDevFilter = $('#logDeviceFilter');
    const logDateFilter = $('#logDateFilter');
    if (logDevFilter) {
      logDevFilter.addEventListener('change', () => {
        logPage = 1;
        refreshLogs();
      });
    }
    if (logDateFilter) {
      logDateFilter.addEventListener('change', () => {
        logPage = 1;
        refreshLogs();
      });
    }
    const resetBtn = $('#logFilterReset');
    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (logDevFilter) logDevFilter.value = 'ACTIVE';
        if (logDateFilter) logDateFilter.value = '';
        logPage = 1;
        refreshLogs();
      });
    }
    const prevBtn = $('#logPrevBtn');
    const nextBtn = $('#logNextBtn');
    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        if (logPage > 1) {
          logPage--;
          refreshLogs();
        }
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (logPage < logPages) {
          logPage++;
          refreshLogs();
        }
      });
    }

    const clearBtn = $('#clearLogsBtn');
    if (clearBtn) {
      clearBtn.addEventListener('click', async () => {
        const dev = getLogDeviceParam();
        const targetText = dev === 'ALL' ? 'ALL devices' : dev;
        if (!window.confirm(`Clear recorded activity logs for ${targetText}?`)) return;
        try {
          const res = await fetchJsonWithTimeout(
            'api/logs.php',
            {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': APP.csrf || '',
              },
              body: JSON.stringify({ action: 'clear', device: dev }),
            },
            5000
          );
          if (res && res.ok && res.json && res.json.ok) {
            showToast(`Cleared ${res.json.deleted} log entries.`, 'info');
            logPage = 1;
            refreshLogsAndNotifs();
            refreshTrend();
          } else {
            showToast((res && res.json && res.json.message) || 'Could not clear logs.', 'error');
          }
        } catch (_e) {
          showToast('Error clearing logs.', 'error');
        }
      });
    }

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) {
        if (pollTimer) clearTimeout(pollTimer);
      } else {
        pollTelemetry(true).then(scheduleNextPoll);
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindControls();
    pollTelemetry(true).then(scheduleNextPoll);
  });
})();