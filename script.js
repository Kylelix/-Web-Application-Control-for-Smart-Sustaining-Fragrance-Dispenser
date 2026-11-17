const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => document.querySelectorAll(selector);

const state = {
  voc: 132,
  humidity: 62,
  tank: 30,
  powered: true,
  highVocLock: false,
  emptyTankLock: false,
};

function timeNow() {
  return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function addNotification(title, message) {
  const list = $('#notificationList');
  if (!list) return;
  const item = document.createElement('li');
  item.innerHTML = `<strong>${title}</strong><span>${message}</span>`;
  list.prepend(item);
  while (list.children.length > 5) list.lastElementChild.remove();
}

function addLog(mode, action) {
  const logBody = $('#logBody');
  if (!logBody) return;
  const row = document.createElement('tr');
  const tagClass = action.includes('Misted') ? 'good' : action.includes('Paused') || action.includes('Locked') ? 'alert' : 'idle';
  row.innerHTML = `
    <td>${timeNow()}</td>
    <td>${state.voc}</td>
    <td>${state.humidity}%</td>
    <td>${state.tank}%</td>
    <td>${mode}</td>
    <td><span class="table-tag ${tagClass}">${action}</span></td>
  `;
  logBody.prepend(row);
  while (logBody.children.length > 8) logBody.lastElementChild.remove();
}

function updateSafety() {
  state.highVocLock = state.voc >= 180;
  state.emptyTankLock = state.tank <= 10;

  const sprayBtn = $('#sprayBtn');
  const hint = $('#sprayHint');

  if (sprayBtn && hint) {
    if (!state.powered) {
      sprayBtn.disabled = true;
      hint.textContent = 'Device is powered off.';
    } else if (state.emptyTankLock) {
      sprayBtn.disabled = true;
      hint.textContent = 'Misting disabled due to empty tank lock.';
    } else if (state.highVocLock) {
      sprayBtn.disabled = true;
      hint.textContent = 'Misting disabled due to high VOC lock. Ventilation recommended.';
    } else {
      sprayBtn.disabled = false;
      hint.textContent = window.currentUserRole === 'client'
        ? 'Manual misting command is ready.'
        : 'Manual command is allowed because tank level and VOC response are safe.';
    }
  }

  const vocStatus = $('#vocStatus');
  const vocBadge = $('#vocBadge');
  if (vocStatus && vocBadge) {
    if (state.voc < 100) {
      vocStatus.textContent = 'Low';
      vocBadge.textContent = 'Low';
    } else if (state.voc < 180) {
      vocStatus.textContent = 'Normal';
      vocBadge.textContent = 'Normal';
    } else {
      vocStatus.textContent = 'High';
      vocBadge.textContent = 'Locked';
    }
  }

  const tankStatus = $('#tankStatus');
  const tankPill = $('#tankPill');
  const tankLock = $('#tankLock');
  if (tankStatus && tankPill && tankLock) {
    if (state.tank <= 10) {
      tankStatus.textContent = 'Empty Tank';
      tankPill.textContent = 'Empty';
      tankLock.textContent = 'Active';
    } else if (state.tank < 40) {
      tankStatus.textContent = 'Refill Required';
      tankPill.textContent = 'Near Empty';
      tankLock.textContent = 'Inactive';
    } else {
      tankStatus.textContent = 'Normal Level';
      tankPill.textContent = 'Normal';
      tankLock.textContent = 'Inactive';
    }
  }
}

function refreshReadings() {
  const vocVal = $('#vocValue');
  const humVal = $('#humidityValue');
  const tankPct = $('#tankPercent');
  const tankFill = $('#tankFill');
  const lastUp = $('#lastUpdated');

  if (vocVal) vocVal.textContent = state.voc;
  if (humVal) humVal.textContent = state.humidity;
  if (tankPct) tankPct.textContent = `${state.tank}%`;
  if (tankFill) tankFill.style.height = `${state.tank}%`;
  if (lastUp) lastUp.textContent = timeNow();
  updateSafety();
}

// Segmented controls: clients can adjust dose profile, mode/interval are admin-only
$$('[data-group]').forEach((group) => {
  group.addEventListener('click', (event) => {
    const groupName = group.getAttribute('data-group');
    if (window.currentUserRole === 'client' && (groupName === 'mode' || groupName === 'interval')) {
      return;
    }
    const btn = event.target.tagName === 'BUTTON' ? event.target : event.target.closest('button');
    if (!btn) return;
    group.querySelectorAll('button').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
  });
});

const powerToggle = $('#powerToggle');
if (powerToggle) {
  powerToggle.addEventListener('change', (event) => {
    state.powered = event.target.checked;
    addNotification(state.powered ? 'Device powered on' : 'Device powered off', 'Power status was changed from the dashboard.');
    addLog('Dashboard', state.powered ? 'Standby' : 'Locked');
    refreshReadings();
  });
}

const sprayBtn = $('#sprayBtn');
if (sprayBtn) {
  sprayBtn.addEventListener('click', () => {
    addNotification('Manual misting command', 'Spray Now command was sent to the fragrance dispenser.');
    addLog('Manual', 'Misted');
  });
}

const baselineBtn = $('#baselineBtn');
if (baselineBtn) {
  baselineBtn.addEventListener('click', () => {
    addNotification('Baseline recorded', `Current VOC baseline set at ${state.voc}.`);
    addLog('Calibration', 'Standby');
  });
}

const exportBtn = $('#exportBtn');
if (exportBtn) {
  exportBtn.addEventListener('click', () => {
    addNotification('CSV export prepared', 'Activity log export is ready (VOC, Humidity, Tank, Mode, Action).');
  });
}

const adminDeviceSelect = $('#adminDeviceSelect');
if (adminDeviceSelect) {
  adminDeviceSelect.addEventListener('change', (e) => {
    window.currentDevice = e.target.value;
    addNotification('Device switched', `Active prototype switched to ${e.target.value}.`);
  });
}

setInterval(() => {
  state.voc = Math.max(70, Math.min(210, state.voc + Math.round((Math.random() - 0.48) * 18)));
  state.humidity = Math.max(55, Math.min(70, Math.round(state.humidity + (Math.random() - 0.5) * 2)));
  refreshReadings();
}, 5000);

refreshReadings();
