(function () {
  const ctx = window.v2LabChecksContext;
  if (!ctx) return;

  const state = {
    loaded: false,
    loading: false,
    runBusy: false,
    runProgressTimer: null,
    runProgressValue: 0,
    runPollTimer: null,
    runPollInFlight: false,
    runPollKnownIds: [],
    runPollStartedAtMs: 0,
    runObservedId: '',
    runLiveEntries: [],
    runLiveSeenItemIds: Object.create(null),
    canManage: false,
    config: {
      settings: { grading_enabled: true, pass_percent: 60 },
      grades: [],
      items: []
    },
    runs: [],
    activeRunId: '',
    activeRun: null
  };
  const dropdownRepaints = Object.create(null);
  let dynamicDropdownIds = [];

  function byId(id) {
    return document.getElementById(id);
  }

  function tr(key) {
    try {
      return ctx.tr ? ctx.tr(key) : key;
    } catch (_) {
      return key;
    }
  }

  function esc(value) {
    try {
      return ctx.esc ? ctx.esc(value) : String(value == null ? '' : value);
    } catch (_) {
      return String(value == null ? '' : value);
    }
  }

  function toast(message, kind) {
    if (ctx.toast) {
      ctx.toast(message, kind || 'error');
    }
  }

  function isModalOpen() {
    const modal = byId('labChecksModal');
    return !!(modal && !modal.classList.contains('hidden'));
  }

  function theme() {
    return String((ctx.getTheme && ctx.getTheme()) || 'dark');
  }

  function labData() {
    return ctx.getLabData ? ctx.getLabData() : null;
  }

  function apiBase() {
    const labId = String((ctx.getLabId && ctx.getLabId()) || '').trim();
    return `/api/labs/${encodeURIComponent(labId)}/checks`;
  }

  function apiRuns() {
    return `${apiBase()}/runs`;
  }

  function apiRun(runId) {
    return `${apiRuns()}/${encodeURIComponent(runId)}`;
  }

  function apiRunExport(runId) {
    return `${apiRun(runId)}/export`;
  }

  function numberOr(value, fallback) {
    const v = Number(value);
    return Number.isFinite(v) ? v : fallback;
  }

  function boolOf(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    const text = String(value == null ? '' : value).trim().toLowerCase();
    return text === '1' || text === 'true' || text === 'yes' || text === 'on';
  }

  function nowDateLabel(raw) {
    const text = String(raw || '').trim();
    if (!text) return '-';
    const d = new Date(text);
    if (Number.isNaN(d.getTime())) return text;
    return d.toLocaleString((ctx.getLang && ctx.getLang()) === 'ru' ? 'ru-RU' : 'en-US', {
      hour12: false,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  }

  function errorLabel(errorText) {
    const code = String(errorText || '').trim().toLowerCase();
    if (!code) return '';
    const map = {
      node_not_running: 'labChecksErrNodeNotRunning',
      console_not_text_mode: 'labChecksErrConsoleNotText',
      empty_command: 'labChecksErrEmptyCommand',
      expected_empty: 'labChecksErrExpectedEmpty',
      invalid_regex: 'labChecksErrInvalidRegex',
      shell_type_not_supported_for_node: 'labChecksErrShellTypeUnsupported',
      console_execution_failed: 'labChecksErrConsoleExecutionFailed',
      console_port_missing: 'labChecksErrConsolePortMissing',
      console_connect_failed: 'labChecksErrConsoleConnectFailed',
      console_no_output: 'labChecksErrConsoleNoOutput',
      linux_console_no_output: 'labChecksErrLinuxConsoleNoOutput',
      linux_agent_unavailable: 'labChecksErrLinuxAgentUnavailable',
      linux_agent_exec_failed: 'labChecksErrLinuxAgentExecFailed',
      linux_agent_timeout: 'labChecksErrLinuxAgentTimeout',
      console_in_use: 'labChecksErrConsoleInUse',
      console_lock_failed: 'labChecksErrConsoleLockFailed',
      execution_failed: 'labChecksErrExecutionFailed',
      ssh_host_or_username_missing: 'labChecksErrSshMissingHostOrUser',
      ssh_password_required: 'labChecksErrSshPasswordRequired',
      ssh_auth_failed: 'labChecksErrSshAuthFailed',
      ssh_timeout: 'labChecksErrSshTimeout'
    };
    const key = map[code];
    return key ? tr(key) : String(errorText || '');
  }

  function isTimeoutResult(item) {
    const err = String((item && item.error_text) || '').toLowerCase();
    return err.includes('timeout');
  }

  function checkTitleClass(item) {
    const light = theme() === 'light';
    if (isTimeoutResult(item)) {
      return light ? 'font-semibold text-amber-600' : 'font-semibold text-amber-300';
    }
    const status = String((item && item.status) || '').toLowerCase();
    if (status === 'passed') {
      return light ? 'font-semibold text-emerald-600' : 'font-semibold text-emerald-300';
    }
    if (status === 'failed' || status === 'error') {
      return light ? 'font-semibold text-rose-600' : 'font-semibold text-rose-300';
    }
    return light ? 'font-semibold text-slate-900' : 'font-semibold text-slate-100';
  }

  function normalizeMultilineText(value) {
    return String(value || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  }

  function isCommandEchoLine(line, commandLine) {
    if (!line || !commandLine) return false;
    if (line === commandLine) return true;
    if (!line.endsWith(commandLine)) return false;
    const prefix = line.slice(0, line.length - commandLine.length).trimEnd();
    if (!prefix) return true;
    return /[>#\$]\s*$/.test(prefix);
  }

  function isPromptOnlyLine(line) {
    if (!line) return false;
    return /^[\w().:@/-]+(?:\([^)]+\))?[>#]\s*$/.test(line);
  }

  function normalizeOutputText(outputText, commandText) {
    const outputLines = normalizeMultilineText(outputText).split('\n');
    const cmdLines = normalizeMultilineText(commandText)
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line !== '');

    const cmdCounts = Object.create(null);
    cmdLines.forEach((line) => {
      cmdCounts[line] = (cmdCounts[line] || 0) + 1;
    });

    const cleaned = [];
    outputLines.forEach((rawLine) => {
      const noRight = String(rawLine || '').replace(/\s+$/g, '');
      const line = noRight.trim();
      if (!line) return;

      let matched = '';
      for (let i = 0; i < cmdLines.length; i += 1) {
        const cmd = cmdLines[i];
        if (isCommandEchoLine(line, cmd)) {
          matched = cmd;
          break;
        }
      }
      if (matched && (cmdCounts[matched] || 0) > 0) {
        cmdCounts[matched] -= 1;
        return;
      }

      if (isPromptOnlyLine(line)) return;

      cleaned.push(noRight);
    });

    return cleaned.join('\n');
  }

  function defaultItem() {
    const nodes = Array.isArray(labData() && labData().nodes) ? labData().nodes : [];
    const firstNodeId = nodes.length ? String(nodes[0].id || '') : '';
    return {
      node_id: firstNodeId,
      title: '',
      transport: 'auto',
      shell_type: 'auto',
      command_text: '',
      match_mode: 'contains',
      expected_text: '',
      hint_text: '',
      points: 1,
      timeout_seconds: 10,
      is_enabled: true,
      show_expected_to_learner: false,
      show_output_to_learner: false,
      ui_collapsed: false,
      ssh_host: '',
      ssh_port: 22,
      ssh_username: '',
      ssh_password: ''
    };
  }

  function normalizeConfigPayload(payload) {
    const settings = payload && typeof payload.settings === 'object' && payload.settings
      ? payload.settings
      : {};
    const grades = Array.isArray(payload && payload.grades) ? payload.grades : [];
    const items = Array.isArray(payload && payload.items) ? payload.items : [];

    return {
      settings: {
        grading_enabled: true,
        pass_percent: numberOr(settings.pass_percent, 60)
      },
      grades: grades.map((g) => ({
        grade_label: String((g && g.grade_label) || '').trim(),
        min_percent: numberOr(g && g.min_percent, 0)
      })).filter((g) => g.grade_label !== ''),
      items: items.map((item) => ({
        id: String((item && item.id) || ''),
        node_id: String((item && item.node_id) || ''),
        title: String((item && item.title) || ''),
        transport: String((item && item.transport) || 'auto'),
        shell_type: String((item && item.shell_type) || 'auto'),
        command_text: String((item && item.command_text) || ''),
        match_mode: String((item && item.match_mode) || 'contains'),
        expected_text: String((item && item.expected_text) || ''),
        hint_text: String((item && item.hint_text) || ''),
        points: numberOr(item && item.points, 1),
        timeout_seconds: numberOr(item && item.timeout_seconds, 10),
        is_enabled: boolOf(item && item.is_enabled),
        show_expected_to_learner: boolOf(item && item.show_expected_to_learner),
        show_output_to_learner: boolOf(item && item.show_output_to_learner),
        ui_collapsed: boolOf(item && item.ui_collapsed),
        ssh_host: String((item && item.ssh_host) || ''),
        ssh_port: numberOr(item && item.ssh_port, 22),
        ssh_username: String((item && item.ssh_username) || ''),
        ssh_password: String((item && item.ssh_password) || '')
      }))
    };
  }

  function optionList(rows, selectedValue) {
    const selected = String(selectedValue || '');
    return rows.map((row) => {
      const value = String(row.value || '');
      const label = String(row.label || value);
      return `<option value="${esc(value)}" ${value === selected ? 'selected' : ''}>${esc(label)}</option>`;
    }).join('');
  }

  function nodeNameById(nodeId) {
    const lookupId = String(nodeId || '').trim();
    if (!lookupId) return '';
    const nodes = Array.isArray(labData() && labData().nodes) ? labData().nodes : [];
    for (let i = 0; i < nodes.length; i += 1) {
      const node = nodes[i] || {};
      if (String(node.id || '') === lookupId) {
        return String(node.name || '').trim();
      }
    }
    return '';
  }

  function formatCheckTitleWithNode(title, nodeName) {
    const baseTitle = String(title || '').trim();
    const baseNode = String(nodeName || '').trim();
    if (!baseTitle) return baseNode;
    if (!baseNode) return baseTitle;
    const lowerTitle = baseTitle.toLowerCase();
    const lowerNode = baseNode.toLowerCase();
    if (lowerTitle.includes(`(${lowerNode})`) || lowerTitle.endsWith(` - ${lowerNode}`)) {
      return baseTitle;
    }
    return `${baseTitle} (${baseNode})`;
  }

  function runOrderFallbackKey(title, nodeName) {
    return `${String(title || '').trim().toLowerCase()}\u0000${String(nodeName || '').trim().toLowerCase()}`;
  }

  function buildRunItemOrderIndex() {
    const byId = new Map();
    const byFallback = new Map();
    const items = Array.isArray(state.config && state.config.items) ? state.config.items : [];
    items.forEach((item, idx) => {
      const id = String(item && item.id ? item.id : '').trim();
      if (id) {
        byId.set(id, idx);
      }
      const title = String(item && item.title ? item.title : '');
      const node = nodeNameById(item && item.node_id ? item.node_id : '');
      const key = runOrderFallbackKey(title, node);
      if (!byFallback.has(key)) {
        byFallback.set(key, idx);
      }
    });
    return { byId, byFallback };
  }

  function sortRunItemsForDisplay(rows) {
    const list = Array.isArray(rows) ? rows.slice() : [];
    if (!list.length) return list;

    const order = buildRunItemOrderIndex();
    return list
      .map((item, index) => {
        let priority = 1e9 + index;
        const itemId = String(item && item.check_item_id ? item.check_item_id : '').trim();
        if (itemId && order.byId.has(itemId)) {
          priority = Number(order.byId.get(itemId));
        } else {
          const key = runOrderFallbackKey(item && item.check_title ? item.check_title : '', item && item.node_name ? item.node_name : '');
          if (order.byFallback.has(key)) {
            priority = Number(order.byFallback.get(key));
          }
        }
        return { item, index, priority };
      })
      .sort((a, b) => {
        if (a.priority !== b.priority) {
          return a.priority - b.priority;
        }
        return a.index - b.index;
      })
      .map((entry) => entry.item);
  }

  function btnClass(kind, size) {
    const sizeMap = {
      xs: 'px-2.5 py-1.5 text-xs',
      sm: 'px-4 py-2 text-sm'
    };
    const base = `lab-btn inline-flex w-auto items-center justify-center gap-1.5 rounded-xl border font-semibold leading-none cursor-pointer transition duration-200 hover:-translate-y-0.5 active:translate-y-0 focus:outline-none ${sizeMap[size] || sizeMap.sm}`;
    if (theme() === 'light') {
      if (kind === 'danger') {
        return `${base} border-rose-300 bg-gradient-to-r from-rose-500 to-red-500 text-white shadow-lg shadow-rose-500/25 hover:from-rose-600 hover:to-red-600`;
      }
      if (kind === 'warn') {
        return `${base} border-amber-300 bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow-lg shadow-amber-500/25 hover:from-amber-600 hover:to-orange-600`;
      }
      return `${base} border-blue-300 bg-gradient-to-r from-blue-500 to-sky-500 text-white shadow-lg shadow-blue-500/30 hover:from-blue-600 hover:to-sky-600`;
    }
    if (kind === 'danger') {
      return `${base} border-rose-400/45 bg-gradient-to-r from-rose-500/85 to-red-500/85 text-white shadow-lg shadow-rose-950/30 hover:from-rose-500 hover:to-red-500`;
    }
    if (kind === 'warn') {
      return `${base} border-amber-400/45 bg-gradient-to-r from-amber-500/85 to-orange-500/85 text-white shadow-lg shadow-amber-950/30 hover:from-amber-500 hover:to-orange-500`;
    }
    return `${base} border-cyan-400/40 bg-gradient-to-r from-cyan-500/85 to-blue-500/85 text-white shadow-lg shadow-cyan-900/35 hover:from-cyan-500 hover:to-blue-500`;
  }

  function inputClass(options) {
    const opts = options || {};
    const rounded = opts.rounded || 'rounded-lg';
    const compact = !!opts.compact;
    const number = !!opts.number;
    const size = compact ? 'px-2 py-1 text-[11px]' : 'px-2.5 py-1.5 text-xs';
    const base = `lab-input ${number ? 'lab-input-number ' : ''}w-full ${rounded} border ${size} outline-none`;
    if (theme() === 'light') {
      return `${base} border-slate-300 bg-white text-slate-900 ring-sky-300 focus:ring-2`;
    }
    return `${base} border-white/20 bg-slate-950/60 text-slate-100 ring-cyan-300/40 focus:ring-2`;
  }

  function setDropdownRepaint(selectId, repaint) {
    if (typeof repaint === 'function') {
      dropdownRepaints[selectId] = repaint;
    }
  }

  function repaintDropdown(selectId) {
    const repaint = dropdownRepaints[selectId];
    if (typeof repaint === 'function') {
      repaint();
    }
  }

  function repaintAllDropdowns() {
    Object.keys(dropdownRepaints).forEach((selectId) => {
      const repaint = dropdownRepaints[selectId];
      if (typeof repaint === 'function') {
        repaint();
      } else {
        delete dropdownRepaints[selectId];
      }
    });
  }

  function clearDynamicDropdownRepaints() {
    dynamicDropdownIds.forEach((id) => {
      delete dropdownRepaints[id];
    });
    dynamicDropdownIds = [];
  }

  function setRunBusyUi(isBusy) {
    const busy = !!isBusy;
    ['labChecksRunBtn', 'labChecksSaveBtn', 'labChecksRefreshRunsBtn', 'labChecksExportBtn'].forEach((id) => {
      const el = byId(id);
      if (!el) return;
      el.disabled = busy;
      el.classList.toggle('pointer-events-none', busy);
      el.classList.toggle('opacity-70', busy);
    });
  }

  function stopRunProgressTimer() {
    if (state.runProgressTimer) {
      clearInterval(state.runProgressTimer);
      state.runProgressTimer = null;
    }
  }

  function stopRunPollTimer() {
    if (state.runPollTimer) {
      clearInterval(state.runPollTimer);
      state.runPollTimer = null;
    }
    state.runPollInFlight = false;
  }

  function resetRunLiveFeed() {
    state.runLiveEntries = [];
    state.runLiveSeenItemIds = Object.create(null);
    renderRunLiveFeed();
  }

  function runLiveStatusText(item) {
    if (isTimeoutResult(item)) return tr('labChecksRunLiveStatusTimeout');
    const status = String((item && item.status) || '').toLowerCase();
    if (status === 'passed') return tr('labChecksRunLiveStatusPassed');
    if (status === 'failed') return tr('labChecksRunLiveStatusFailed');
    return tr('labChecksRunLiveStatusError');
  }

  function runLiveStatusClass(item) {
    const light = theme() === 'light';
    if (isTimeoutResult(item)) {
      return light ? 'text-amber-700' : 'text-amber-300';
    }
    const status = String((item && item.status) || '').toLowerCase();
    if (status === 'passed') {
      return light ? 'text-emerald-700' : 'text-emerald-300';
    }
    if (status === 'failed' || status === 'error') {
      return light ? 'text-rose-700' : 'text-rose-300';
    }
    return light ? 'text-slate-700' : 'text-slate-200';
  }

  function renderRunLiveFeed() {
    const wrap = byId('labChecksRunLiveWrap');
    const feed = byId('labChecksRunLiveFeed');
    if (!wrap || !feed) return;

    const hasEntries = Array.isArray(state.runLiveEntries) && state.runLiveEntries.length > 0;
    wrap.classList.toggle('hidden', !state.runBusy && !hasEntries);

    if (!hasEntries) {
      feed.innerHTML = `<div class="${theme() === 'light' ? 'text-slate-500' : 'text-slate-300'}">${esc(tr('labChecksRunLiveEmpty'))}</div>`;
      return;
    }

    feed.innerHTML = state.runLiveEntries.map((entry) => `
      <div class="flex items-center justify-between gap-2 py-0.5">
        <div class="min-w-0 truncate">
          <span class="${runLiveStatusClass({ status: entry.status, error_text: entry.error_text })} font-semibold">${esc(entry.statusText)}</span>
          <span class="opacity-80"> - ${esc(entry.title)}</span>
        </div>
        <span class="${theme() === 'light' ? 'text-slate-500' : 'text-slate-300'} shrink-0">${esc(entry.when)}</span>
      </div>
    `).join('');
    feed.scrollTop = feed.scrollHeight;
  }

  function registerRunLiveItem(item, index) {
    const itemId = String(item && item.id ? item.id : `idx:${index}`).trim();
    if (!itemId || state.runLiveSeenItemIds[itemId]) return;
    state.runLiveSeenItemIds[itemId] = true;
    const title = formatCheckTitleWithNode(item && item.check_title ? item.check_title : '', item && item.node_name ? item.node_name : '');
    state.runLiveEntries.push({
      id: itemId,
      title: `${tr('labChecksRunLivePrefix')} ${index + 1}: ${title || '-'}`,
      when: nowDateLabel(item && item.created_at ? item.created_at : new Date().toISOString()),
      status: String(item && item.status ? item.status : ''),
      error_text: String(item && item.error_text ? item.error_text : ''),
      statusText: runLiveStatusText(item)
    });
  }

  function syncLiveFeedFromRows(rows) {
    const list = Array.isArray(rows) ? rows : [];
    list.forEach((item, index) => {
      registerRunLiveItem(item, index);
    });
    renderRunLiveFeed();
  }

  function selectObservedRunIdFromRuns(runs) {
    if (state.runObservedId) {
      return state.runObservedId;
    }
    const list = Array.isArray(runs) ? runs : [];
    const me = ctx.getCurrentUser ? (ctx.getCurrentUser() || {}) : {};
    const meId = String(me && me.id ? me.id : '').trim();
    const knownSet = new Set(Array.isArray(state.runPollKnownIds) ? state.runPollKnownIds : []);
    const startedAtMs = Number(state.runPollStartedAtMs || 0);

    for (let i = 0; i < list.length; i += 1) {
      const run = list[i] || {};
      const runId = String(run.id || '').trim();
      if (!runId) continue;
      if (knownSet.has(runId)) continue;
      if (meId && String(run.started_by || '').trim() && String(run.started_by || '').trim() !== meId) continue;
      const runStartedText = String(run.started_at || '').trim();
      if (startedAtMs > 0 && runStartedText) {
        const runStartedMs = Date.parse(runStartedText);
        if (Number.isFinite(runStartedMs) && runStartedMs + 5000 < startedAtMs) continue;
      }
      return runId;
    }
    return '';
  }

  function setRunProgress(visible, value, text) {
    const wrap = byId('labChecksRunProgressWrap');
    const bar = byId('labChecksRunProgressBar');
    const textEl = byId('labChecksRunProgressText');
    const percentEl = byId('labChecksRunProgressPercent');
    if (!wrap || !bar || !textEl || !percentEl) return;

    const clamped = Math.max(0, Math.min(100, Number(value) || 0));
    state.runProgressValue = clamped;

    wrap.classList.toggle('hidden', !visible);
    bar.style.width = `${clamped}%`;
    percentEl.textContent = `${Math.round(clamped)}%`;
    if (typeof text === 'string' && text.trim() !== '') {
      textEl.textContent = text;
    }
  }

  function startRunProgress() {
    stopRunProgressTimer();
    state.runProgressValue = 4;
    setRunProgress(true, state.runProgressValue, tr('labChecksRunInProgress'));
    state.runProgressTimer = setInterval(() => {
      let next = state.runProgressValue;
      if (next < 70) {
        next += 3 + Math.random() * 4;
      } else if (next < 90) {
        next += 0.8 + Math.random() * 1.6;
      } else if (next < 95) {
        next += 0.25 + Math.random() * 0.45;
      }
      if (next > 95) next = 95;
      setRunProgress(true, next, tr('labChecksRunInProgress'));
    }, 380);
  }

  function finishRunProgress(ok) {
    stopRunProgressTimer();
    setRunProgress(true, 100, ok ? tr('labChecksRunProgressDone') : tr('labChecksRunProgressFailed'));
    setTimeout(() => {
      if (state.runBusy) return;
      setRunProgress(false, 0, tr('labChecksRunInProgress'));
    }, ok ? 900 : 1300);
  }

  async function pollRunProgressTick() {
    if (!state.runBusy || state.runPollInFlight) return;
    state.runPollInFlight = true;
    try {
      const runsRes = await ctx.jget(`${apiRuns()}?limit=80`);
      const runsData = (runsRes && runsRes.data) ? runsRes.data : {};
      const runs = Array.isArray(runsData.runs) ? runsData.runs : [];
      state.runs = runs;

      const candidateRunId = state.runObservedId || selectObservedRunIdFromRuns(runs);
      if (candidateRunId) {
        state.runObservedId = candidateRunId;
        const detailRes = await ctx.jget(apiRun(candidateRunId));
        const detail = (detailRes && detailRes.data) ? detailRes.data : {};
        state.activeRunId = candidateRunId;
        state.activeRun = detail;

        const rows = sortRunItemsForDisplay(Array.isArray(detail.items) ? detail.items : []);
        const run = detail && detail.run ? detail.run : {};
        const total = Math.max(
          1,
          Number(run.total_items || 0),
          rows.length,
          Array.isArray(state.config && state.config.items) ? state.config.items.length : 0
        );
        const done = Math.min(
          total,
          Math.max(
            rows.length,
            Number(run.passed_items || 0) + Number(run.failed_items || 0) + Number(run.error_items || 0)
          )
        );
        const nextProgress = Math.max(state.runProgressValue, Math.min(95, (done / total) * 100));
        setRunProgress(true, nextProgress, tr('labChecksRunInProgress'));
        syncLiveFeedFromRows(rows);
        renderRunSelect();
        renderRunResult();
      } else {
        renderRunSelect();
      }
    } catch (_) {
      // Keep silent: polling is best-effort while run is in progress.
    } finally {
      state.runPollInFlight = false;
    }
  }

  function startRunPoller() {
    stopRunPollTimer();
    pollRunProgressTick();
    state.runPollTimer = setInterval(() => {
      pollRunProgressTick();
    }, 700);
  }

  function itemSelectId(idx, field) {
    return `labChecksItemSelect_${field}_${idx}`;
  }

  function itemSelectHostId(idx, field) {
    return `labChecksItemSelectHost_${field}_${idx}`;
  }

  function mountSelectDropdown(selectId, hostId) {
    const select = byId(selectId);
    const host = byId(hostId);
    if (!select) return;
    if (typeof ctx.buildSelectDropdown !== 'function' || !host) {
      select.classList.remove('hidden');
      select.className = inputClass({ rounded: 'rounded-xl' });
      return;
    }
    select.classList.add('hidden');
    const repaint = ctx.buildSelectDropdown(selectId, hostId);
    setDropdownRepaint(selectId, repaint);
    repaintDropdown(selectId);
  }

  function mountRunSelectDropdown() {
    mountSelectDropdown('labChecksRunSelect', 'labChecksRunSelectHost');
  }

  function mountItemDropdowns(itemCount) {
    clearDynamicDropdownRepaints();
    for (let idx = 0; idx < itemCount; idx += 1) {
      ['node_id', 'transport', 'shell_type', 'match_mode'].forEach((field) => {
        const selectId = itemSelectId(idx, field);
        const hostId = itemSelectHostId(idx, field);
        mountSelectDropdown(selectId, hostId);
        dynamicDropdownIds.push(selectId);
      });
    }
  }

  function applyModalTheme() {
    const card = byId('labChecksModalCard');
    const configSection = byId('labChecksConfigSection');
    const runsPanel = byId('labChecksRunsPanel');
    const noManage = byId('labChecksNoManage');
    const runSummary = byId('labChecksRunSummary');
    const runProgressWrap = byId('labChecksRunProgressWrap');
    const runProgressTrack = byId('labChecksRunProgressTrack');
    const runProgressText = byId('labChecksRunProgressText');
    const runProgressPercent = byId('labChecksRunProgressPercent');
    const runProgressBar = byId('labChecksRunProgressBar');
    const runLiveWrap = byId('labChecksRunLiveWrap');
    const runLiveFeed = byId('labChecksRunLiveFeed');
    const runsBox = byId('labChecksRunSelect') ? byId('labChecksRunSelect').closest('.rounded-xl.border') : null;
    const resultsBox = byId('labChecksResultsBody') ? byId('labChecksResultsBody').closest('.min-h-0.flex-1.overflow-auto.rounded-xl.border') : null;
    const head = byId('labChecksResultsBody') ? byId('labChecksResultsBody').closest('table').querySelector('thead') : null;
    if (!card) return;
    const noManageHidden = !!(noManage && noManage.classList.contains('hidden'));
    const runProgressHidden = !!(runProgressWrap && runProgressWrap.classList.contains('hidden'));
    const runLiveHidden = !!(runLiveWrap && runLiveWrap.classList.contains('hidden'));

    if (theme() === 'light') {
      card.className = 'relative flex h-[90vh] w-full max-w-[98vw] max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-2xl xl:w-[80vw] xl:max-w-[80vw]';
      if (configSection) configSection.className = 'flex min-h-0 flex-col border-b border-slate-200 p-4 xl:border-b-0 xl:border-r';
      if (runsPanel) runsPanel.className = 'flex min-h-0 flex-col p-4';
      if (noManage) noManage.className = 'rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700';
      if (runSummary) runSummary.className = 'mt-2 text-xs text-slate-700';
      if (runProgressWrap) runProgressWrap.className = 'mt-2';
      if (runProgressTrack) runProgressTrack.className = 'h-2 overflow-hidden rounded-full border border-slate-200 bg-slate-100';
      if (runProgressText) runProgressText.className = 'text-[11px] text-slate-700';
      if (runProgressPercent) runProgressPercent.className = 'text-[11px] text-slate-600 tabular-nums';
      if (runProgressBar) runProgressBar.className = 'h-full w-0 bg-gradient-to-r from-blue-500 to-sky-500 transition-all duration-300';
      if (runLiveWrap) runLiveWrap.className = 'mt-2';
      if (runLiveFeed) runLiveFeed.className = 'max-h-28 overflow-auto rounded-xl border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-700';
      if (runsBox) runsBox.className = 'mb-3 rounded-xl border border-slate-200 bg-slate-50 p-3';
      if (resultsBox) resultsBox.className = 'min-h-[240px] max-h-[48vh] overflow-auto rounded-xl border border-slate-200 bg-white xl:min-h-0 xl:max-h-none xl:flex-1';
      if (head) head.className = 'sticky top-0 z-10 bg-slate-100 text-slate-700';
    } else {
      card.className = 'relative flex h-[90vh] w-full max-w-[98vw] max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-white/20 bg-slate-900 text-slate-100 shadow-2xl xl:w-[80vw] xl:max-w-[80vw]';
      if (configSection) configSection.className = 'flex min-h-0 flex-col border-b border-white/20 p-4 xl:border-b-0 xl:border-r';
      if (runsPanel) runsPanel.className = 'flex min-h-0 flex-col p-4';
      if (noManage) noManage.className = 'rounded-xl border border-slate-500/35 bg-slate-950/45 px-3 py-3 text-sm text-slate-200';
      if (runSummary) runSummary.className = 'mt-2 text-xs text-slate-300';
      if (runProgressWrap) runProgressWrap.className = 'mt-2';
      if (runProgressTrack) runProgressTrack.className = 'h-2 overflow-hidden rounded-full border border-slate-500/35 bg-slate-800/60';
      if (runProgressText) runProgressText.className = 'text-[11px] text-slate-200';
      if (runProgressPercent) runProgressPercent.className = 'text-[11px] text-slate-300 tabular-nums';
      if (runProgressBar) runProgressBar.className = 'h-full w-0 bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-300';
      if (runLiveWrap) runLiveWrap.className = 'mt-2';
      if (runLiveFeed) runLiveFeed.className = 'max-h-28 overflow-auto rounded-xl border border-slate-500/35 bg-slate-950/50 px-2 py-1 text-[11px] text-slate-200';
      if (runsBox) runsBox.className = 'mb-3 rounded-xl border border-slate-500/35 bg-slate-950/40 p-3';
      if (resultsBox) resultsBox.className = 'min-h-[240px] max-h-[48vh] overflow-auto rounded-xl border border-slate-500/35 bg-slate-950/35 xl:min-h-0 xl:max-h-none xl:flex-1';
      if (head) head.className = 'sticky top-0 z-10 bg-slate-800 text-slate-200';
    }
    if (noManage) {
      noManage.classList.toggle('hidden', noManageHidden);
    }
    if (runProgressWrap) {
      runProgressWrap.classList.toggle('hidden', runProgressHidden);
    }
    if (runLiveWrap) {
      runLiveWrap.classList.toggle('hidden', runLiveHidden);
    }

    const buttonDefs = [
      ['labChecksRunBtn', 'primary', 'sm', 'min-w-[132px]'],
      ['labChecksSaveBtn', 'primary', 'xs', 'min-w-[96px]'],
      ['labChecksClose', 'danger', 'sm', 'min-w-[110px]'],
      ['labChecksRefreshRunsBtn', 'primary', 'xs', 'min-w-[110px]'],
      ['labChecksExportBtn', 'warn', 'xs', 'min-w-[110px]'],
      ['labChecksGradeAddBtn', 'primary', 'sm', 'w-full'],
      ['labChecksAddItemBtn', 'primary', 'xs', '']
    ];
    buttonDefs.forEach(([id, kind, size, extra]) => {
      const el = byId(id);
      if (!el) return;
      const keepHidden = el.classList.contains('hidden');
      el.className = `${btnClass(kind, size)} ${extra || ''}`.trim();
      if (keepHidden) el.classList.add('hidden');
      if (id === 'labChecksSaveBtn' && !state.canManage) {
        el.classList.add('hidden');
      }
    });

    const passInput = byId('labChecksPassPercentInput');
    if (passInput) {
      passInput.className = `${inputClass({ compact: true, number: true, rounded: 'rounded-xl' })} h-7`;
    }

    const gradesPanel = byId('labChecksGradesPanel');
    if (gradesPanel) {
      gradesPanel.className = `min-h-0 min-w-0 overflow-hidden rounded-xl border p-3 ${theme() === 'light' ? 'border-slate-200 bg-slate-50' : 'border-slate-500/35 bg-slate-950/40'}`;
    }
    const gradesColumn = byId('labChecksGradesColumn');
    if (gradesColumn) {
      gradesColumn.className = 'flex min-h-0 min-w-0 flex-col gap-2';
    }
    const itemsPanel = byId('labChecksItemsPanel');
    if (itemsPanel) {
      itemsPanel.className = `flex min-h-0 min-w-0 flex-col overflow-hidden rounded-xl border p-3 ${theme() === 'light' ? 'border-slate-200 bg-slate-50' : 'border-slate-500/35 bg-slate-950/40'}`;
    }

    const gradesHeading = byId('labChecksGradesHeading');
    if (gradesHeading) {
      gradesHeading.className = theme() === 'light'
        ? 'mb-2 text-sm font-semibold text-slate-900'
        : 'mb-2 text-sm font-semibold text-slate-100';
    }
    const passPercentWrap = byId('labChecksPassPercentWrap');
    if (passPercentWrap) {
      passPercentWrap.className = `mt-2 flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-xs ${theme() === 'light' ? 'border-slate-200 bg-white text-slate-700' : 'border-slate-500/35 bg-slate-950/50 text-slate-200'}`;
    }

    const gradeRows = Array.from(document.querySelectorAll('.js-lab-grade-row'));
    gradeRows.forEach((row) => {
      row.className = 'js-lab-grade-row grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_34px] items-center gap-1.5 min-w-0 w-full';
    });

    const checkCards = Array.from(document.querySelectorAll('.js-lab-check-item'));
    checkCards.forEach((cardEl) => {
      cardEl.className = `js-lab-check-item rounded-xl border p-3 ${theme() === 'light' ? 'border-slate-200 bg-slate-50/70' : 'border-slate-500/35 bg-slate-950/35'}`;
    });

    document.querySelectorAll('.js-lab-check-input').forEach((input) => {
      const isNumber = input.classList.contains('js-lab-check-input-number');
      input.className = `js-lab-check-input ${isNumber ? 'js-lab-check-input-number' : ''} ${inputClass({ compact: true, number: isNumber })} h-7`.trim();
    });
    document.querySelectorAll('.js-lab-check-textarea').forEach((area) => {
      const minClass = area.getAttribute('data-min-h') || 'min-h-[56px]';
      area.className = `js-lab-check-textarea ${minClass} ${inputClass({ compact: true })} resize-y`;
    });
    document.querySelectorAll('.js-lab-check-btn-delete').forEach((btn) => {
      btn.className = `${btnClass('danger', 'xs')} whitespace-nowrap`;
    });
    document.querySelectorAll('.js-lab-check-btn-collapse').forEach((btn) => {
      btn.className = `${btnClass('primary', 'xs')} whitespace-nowrap`;
    });
    document.querySelectorAll('.js-lab-grade-del-btn').forEach((btn) => {
      btn.className = `${btnClass('danger', 'xs')} h-8 w-8 px-0 py-0`;
    });
    document.querySelectorAll('.js-lab-grade-input').forEach((input) => {
      const isNumber = input.classList.contains('js-lab-check-input-number');
      input.className = `js-lab-grade-input js-lab-check-input ${isNumber ? 'js-lab-check-input-number' : ''} ${inputClass({ compact: true, number: isNumber })} h-7 text-[11px]`;
    });
    document.querySelectorAll('#labChecksModal button i.fa').forEach((icon) => {
      icon.className = icon.className.replace(/\s*mr-\d+/g, '');
    });

    repaintAllDropdowns();
  }

  function renderGradeRows() {
    const root = byId('labChecksGradesList');
    if (!root) return;
    const grades = Array.isArray(state.config.grades) ? state.config.grades : [];

    root.innerHTML = grades.map((g, idx) => {
      const minPercent = numberOr(g && g.min_percent, 0);
      return `<div class="js-lab-grade-row grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_34px] items-center gap-1.5 min-w-0 w-full" data-grade-idx="${idx}">
        <input type="text" class="js-lab-grade-input js-lab-check-input" data-grade-field="grade_label" maxlength="64" value="${esc((g && g.grade_label) || '')}" placeholder="${esc(tr('labChecksGradeLabel'))}">
        <input type="number" class="js-lab-grade-input js-lab-check-input js-lab-check-input-number" data-grade-field="min_percent" min="0" max="100" step="0.01" value="${esc(String(minPercent))}" placeholder="${esc(tr('labChecksGradeMin'))}">
        <button type="button" class="js-lab-grade-del-btn" data-grade-del="${idx}">
          <i class="fa fa-trash"></i>
        </button>
      </div>`;
    }).join('');

    root.querySelectorAll('[data-grade-del]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-grade-del'));
        if (!Number.isInteger(idx) || idx < 0) return;
        collectConfigFromDom();
        state.config.grades.splice(idx, 1);
        if (!state.config.grades.length) {
          state.config.grades.push({ min_percent: 0, grade_label: '2' });
        }
        renderManageSection();
      });
    });
  }

  function renderItems() {
    const root = byId('labChecksItemsList');
    if (!root) return;

    const items = Array.isArray(state.config.items) ? state.config.items : [];
    const nodes = Array.isArray(labData() && labData().nodes) ? labData().nodes : [];
    const nodeOptions = nodes.map((node) => ({
      value: String(node.id || ''),
      label: String(node.name || node.id || '')
    }));

    const transportOptions = [
      { value: 'auto', label: tr('labChecksTransportAuto') },
      { value: 'console', label: tr('labChecksTransportConsole') }
    ];
    const shellOptions = [
      { value: 'auto', label: tr('labChecksShellAuto') },
      { value: 'ios', label: tr('labChecksShellIos') },
      { value: 'sh', label: tr('labChecksShellSh') },
      { value: 'cmd', label: tr('labChecksShellCmd') },
      { value: 'powershell', label: tr('labChecksShellPowershell') }
    ];
    const matchOptions = [
      { value: 'contains', label: tr('labChecksMatchContains') },
      { value: 'equals', label: tr('labChecksMatchEquals') },
      { value: 'regex', label: tr('labChecksMatchRegex') },
      { value: 'not_contains', label: tr('labChecksMatchNotContains') }
    ];

    root.innerHTML = items.map((item, idx) => {
      const points = numberOr(item.points, 1);
      const timeout = numberOr(item.timeout_seconds, 10);
      const nodeSelect = itemSelectId(idx, 'node_id');
      const nodeHost = itemSelectHostId(idx, 'node_id');
      const transportSelect = itemSelectId(idx, 'transport');
      const transportHost = itemSelectHostId(idx, 'transport');
      const shellSelect = itemSelectId(idx, 'shell_type');
      const shellHost = itemSelectHostId(idx, 'shell_type');
      const matchSelect = itemSelectId(idx, 'match_mode');
      const matchHost = itemSelectHostId(idx, 'match_mode');
      const collapsed = boolOf(item.ui_collapsed);
      const collapseLabel = collapsed ? tr('labChecksExpandCheck') : tr('labChecksCollapseCheck');
      const collapseIcon = collapsed ? 'fa-chevron-down' : 'fa-chevron-up';
      const rawTitle = String(item.title || '').trim() || `${tr('labChecksItemTitle')} #${idx + 1}`;
      const titlePreview = formatCheckTitleWithNode(rawTitle, nodeNameById(item.node_id || ''));
      const previewTone = theme() === 'light' ? 'text-slate-700' : 'text-slate-300';
      const itemId = String(item && item.id ? item.id : '');
      return `<article class="js-lab-check-item rounded-xl border p-3" data-check-idx="${idx}" data-check-id="${esc(itemId)}" data-check-collapsed="${collapsed ? '1' : '0'}">
        <div class="mb-2 flex items-center justify-between gap-2">
          <div class="min-w-0 truncate text-xs font-semibold ${previewTone}" title="${esc(titlePreview)}">${esc(titlePreview)}</div>
          <div class="flex items-center gap-2">
            <button type="button" class="js-lab-check-btn-collapse" data-check-toggle="${idx}" aria-expanded="${collapsed ? 'false' : 'true'}" title="${esc(collapseLabel)}">
              <i class="fa ${collapseIcon}"></i>
              <span>${esc(collapseLabel)}</span>
            </button>
            <button type="button" class="js-lab-check-btn-delete" data-check-del="${idx}">
              <i class="fa fa-trash"></i>
              <span>${esc(tr('labChecksDeleteCheck'))}</span>
            </button>
          </div>
        </div>
        <div class="js-lab-check-body ${collapsed ? 'hidden' : ''}">
          <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemTitle'))}</span>
              <input type="text" class="js-lab-check-input" data-check-field="title" maxlength="255" value="${esc(item.title || '')}">
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemNode'))}</span>
              <select id="${esc(nodeSelect)}" class="hidden" data-check-field="node_id">${optionList(nodeOptions, item.node_id || '')}</select>
              <div id="${esc(nodeHost)}" class="mt-1"></div>
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemTransport'))}</span>
              <select id="${esc(transportSelect)}" class="hidden" data-check-field="transport">${optionList(transportOptions, item.transport || 'auto')}</select>
              <div id="${esc(transportHost)}" class="mt-1"></div>
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemShell'))}</span>
              <select id="${esc(shellSelect)}" class="hidden" data-check-field="shell_type">${optionList(shellOptions, item.shell_type || 'auto')}</select>
              <div id="${esc(shellHost)}" class="mt-1"></div>
            </label>
            <label class="md:col-span-2 text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemCommand'))}</span>
              <textarea class="js-lab-check-textarea" data-min-h="min-h-[64px]" data-check-field="command_text">${esc(item.command_text || '')}</textarea>
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemMatchMode'))}</span>
              <select id="${esc(matchSelect)}" class="hidden" data-check-field="match_mode">${optionList(matchOptions, item.match_mode || 'contains')}</select>
              <div id="${esc(matchHost)}" class="mt-1"></div>
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemPoints'))}</span>
              <input type="number" min="0" max="100000" class="js-lab-check-input js-lab-check-input-number" data-check-field="points" value="${esc(String(points))}">
            </label>
            <label class="text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemTimeout'))}</span>
              <input type="number" min="1" max="240" class="js-lab-check-input js-lab-check-input-number" data-check-field="timeout_seconds" value="${esc(String(timeout))}">
            </label>
            <label class="md:col-span-2 text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemExpected'))}</span>
              <textarea class="js-lab-check-textarea" data-min-h="min-h-[56px]" data-check-field="expected_text">${esc(item.expected_text || '')}</textarea>
            </label>
            <label class="md:col-span-2 text-xs">
              <span class="mb-1 block opacity-80">${esc(tr('labChecksItemHint'))}</span>
              <textarea class="js-lab-check-textarea" data-min-h="min-h-[52px]" data-check-field="hint_text">${esc(item.hint_text || '')}</textarea>
            </label>
          </div>
          <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" class="js-lab-check-cb-input sr-only" data-check-field="is_enabled" ${item.is_enabled ? 'checked' : ''}>
              <span class="js-lab-check-cb-host inline-flex"></span>
              <span>${esc(tr('labChecksItemEnabled'))}</span>
            </label>
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" class="js-lab-check-cb-input sr-only" data-check-field="show_expected_to_learner" ${item.show_expected_to_learner ? 'checked' : ''}>
              <span class="js-lab-check-cb-host inline-flex"></span>
              <span>${esc(tr('labChecksItemShowExpected'))}</span>
            </label>
            <label class="inline-flex items-center gap-2">
              <input type="checkbox" class="js-lab-check-cb-input sr-only" data-check-field="show_output_to_learner" ${item.show_output_to_learner ? 'checked' : ''}>
              <span class="js-lab-check-cb-host inline-flex"></span>
              <span>${esc(tr('labChecksItemShowOutput'))}</span>
            </label>
          </div>
        </div>
      </article>`;
    }).join('');

    mountItemDropdowns(items.length);

    root.querySelectorAll('[data-check-del]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-check-del'));
        if (!Number.isInteger(idx) || idx < 0) return;
        collectConfigFromDom();
        state.config.items.splice(idx, 1);
        renderManageSection();
      });
    });

    root.querySelectorAll('[data-check-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.getAttribute('data-check-toggle'));
        if (!Number.isInteger(idx) || idx < 0) return;
        const card = btn.closest('.js-lab-check-item');
        if (!card) return;
        const body = card.querySelector('.js-lab-check-body');
        const nextCollapsed = String(card.getAttribute('data-check-collapsed') || '0') !== '1';
        card.setAttribute('data-check-collapsed', nextCollapsed ? '1' : '0');
        if (body) body.classList.toggle('hidden', nextCollapsed);
        if (state.config.items[idx]) {
          state.config.items[idx].ui_collapsed = nextCollapsed;
        }

        const icon = btn.querySelector('i.fa');
        if (icon) {
          icon.classList.remove('fa-chevron-up', 'fa-chevron-down');
          icon.classList.add(nextCollapsed ? 'fa-chevron-down' : 'fa-chevron-up');
        }
        const text = btn.querySelector('span');
        const label = nextCollapsed ? tr('labChecksExpandCheck') : tr('labChecksCollapseCheck');
        if (text) text.textContent = label;
        btn.setAttribute('aria-expanded', nextCollapsed ? 'false' : 'true');
        btn.setAttribute('title', label);
      });
    });

    root.querySelectorAll('.js-lab-check-item').forEach((card) => {
      card.querySelectorAll('.js-lab-check-cb-input').forEach((input) => {
        const host = input.nextElementSibling;
        if (!host || !window.mountV2Checkbox) return;
        const paint = () => {
          window.mountV2Checkbox(host, {
            checked: !!input.checked,
            size: 'sm',
            getTheme: () => theme(),
            onChange: (next) => {
              input.checked = !!next;
              paint();
            }
          });
        };
        paint();
      });
    });
  }

  function collectConfigFromDom() {
    const result = {
      settings: {
        grading_enabled: true,
        pass_percent: 60
      },
      grades: [],
      items: []
    };

    const passInput = byId('labChecksPassPercentInput');
    if (passInput) {
      result.settings.pass_percent = numberOr(passInput.value, 60);
    }

    document.querySelectorAll('.js-lab-grade-row').forEach((row) => {
      const labelEl = row.querySelector('[data-grade-field="grade_label"]');
      const minEl = row.querySelector('[data-grade-field="min_percent"]');
      const label = String(labelEl ? labelEl.value : '').trim();
      if (!label) return;
      result.grades.push({
        grade_label: label,
        min_percent: numberOr(minEl ? minEl.value : 0, 0)
      });
    });

    document.querySelectorAll('.js-lab-check-item').forEach((card) => {
      const get = (field, fallback) => {
        const el = card.querySelector(`[data-check-field="${field}"]`);
        if (!el) return fallback;
        if (el.type === 'checkbox') return !!el.checked;
        return String(el.value || '');
      };
      const isCollapsed = String(card.getAttribute('data-check-collapsed') || '0') === '1';
      const itemId = String(card.getAttribute('data-check-id') || '').trim();
      result.items.push({
        id: itemId,
        node_id: get('node_id', ''),
        title: get('title', ''),
        transport: get('transport', 'auto'),
        shell_type: get('shell_type', 'auto'),
        command_text: get('command_text', ''),
        match_mode: get('match_mode', 'contains'),
        expected_text: get('expected_text', ''),
        hint_text: get('hint_text', ''),
        points: numberOr(get('points', '1'), 1),
        timeout_seconds: numberOr(get('timeout_seconds', '10'), 10),
        is_enabled: !!get('is_enabled', true),
        show_expected_to_learner: !!get('show_expected_to_learner', false),
        show_output_to_learner: !!get('show_output_to_learner', false),
        ui_collapsed: isCollapsed,
        ssh_host: get('ssh_host', ''),
        ssh_port: numberOr(get('ssh_port', '22'), 22),
        ssh_username: get('ssh_username', ''),
        ssh_password: get('ssh_password', '')
      });
    });

    state.config = result;
    return result;
  }

  function renderManageSection() {
    const manageRoot = byId('labChecksManageRoot');
    const noManage = byId('labChecksNoManage');
    const saveBtn = byId('labChecksSaveBtn');

    if (manageRoot) manageRoot.classList.toggle('hidden', !state.canManage);
    if (noManage) noManage.classList.toggle('hidden', !!state.canManage);
    if (saveBtn) saveBtn.classList.toggle('hidden', !state.canManage);

    if (!state.canManage) {
      clearDynamicDropdownRepaints();
      applyModalTheme();
      return;
    }

    if (!Array.isArray(state.config.grades) || !state.config.grades.length) {
      state.config.grades = [{ min_percent: 0, grade_label: '2' }];
    }
    if (!Array.isArray(state.config.items)) {
      state.config.items = [];
    }

    const passInput = byId('labChecksPassPercentInput');
    if (passInput) passInput.value = String(numberOr(state.config.settings.pass_percent, 60));

    renderGradeRows();
    renderItems();
    applyModalTheme();
  }

  function renderRunSelect() {
    const select = byId('labChecksRunSelect');
    if (!select) return;

    if (!state.runs.length) {
      select.innerHTML = `<option value="">${esc(tr('labChecksRunEmpty'))}</option>`;
      state.activeRunId = '';
      state.activeRun = null;
      repaintDropdown('labChecksRunSelect');
      renderRunResult();
      return;
    }

    select.innerHTML = state.runs.map((run) => {
      const id = String(run.id || '');
      const score = `${Number(run.earned_points || 0)}/${Number(run.total_points || 0)}`;
      const started = nowDateLabel(run.started_at);
      const selected = id === String(state.activeRunId || '') ? 'selected' : '';
      return `<option value="${esc(id)}" ${selected}>${esc(started)} | ${esc(score)} | ${esc(Number(run.score_percent || 0).toFixed(2))}%</option>`;
    }).join('');
    repaintDropdown('labChecksRunSelect');
  }

  function renderRunResult() {
    const summary = byId('labChecksRunSummary');
    const body = byId('labChecksResultsBody');
    if (!summary || !body) return;

    if (!state.activeRun || !state.activeRun.run) {
      summary.textContent = '';
      body.innerHTML = '';
      return;
    }

    const run = state.activeRun.run;
    summary.textContent = `${tr('labChecksScoreSummary')}: ${Number(run.earned_points || 0)}/${Number(run.total_points || 0)} (${Number(run.score_percent || 0).toFixed(2)}%)` +
      (run.grade_label ? ` | ${tr('labChecksGradeLabel')}: ${run.grade_label}` : '') +
      ` | ${tr('labChecksRunsCount')}: ${Number(run.total_items || 0)}`;

    const rows = sortRunItemsForDisplay(Array.isArray(state.activeRun.items) ? state.activeRun.items : []);
    body.innerHTML = rows.map((item) => {
      const output = item.output_text === null
        ? tr('labChecksOutputHidden')
        : normalizeOutputText(item.output_text, item.command_text || '');
      const expected = item.expected_text === null ? tr('labChecksExpectedHidden') : String(item.expected_text || '');
      const errorText = errorLabel(item.error_text || '');
      const outputWithError = errorText
        ? (output ? `${output}\n${tr('labChecksErrorPrefix')}: ${errorText}` : `${tr('labChecksErrorPrefix')}: ${errorText}`)
        : output;
      const checkClass = checkTitleClass(item);
      const titleText = formatCheckTitleWithNode(item.check_title || '', item.node_name || '');
      const hintText = String(item.hint_text || '').trim();
      const hintTooltip = hintText || tr('labChecksHintEmpty');
      const hintIconClass = theme() === 'light'
        ? 'inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-500 cursor-help'
        : 'inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-500/40 bg-slate-900/60 text-slate-300 cursor-help';
      const metaClass = theme() === 'light' ? 'text-[11px] text-slate-600' : 'text-[11px] text-slate-300';
      const scoreValue = `${Number(item.points || 0)}`;
      return `<tr class="${theme() === 'light' ? 'border-b border-slate-100' : 'border-b border-white/10'} align-top">
        <td class="px-3 py-2">
          <div class="flex items-center gap-2">
            <span class="${checkClass}">${esc(titleText)}</span>
            <span class="${hintIconClass}" title="${esc(hintTooltip)}" aria-label="${esc(hintTooltip)}">
              <i class="fa fa-question text-[10px]"></i>
            </span>
          </div>
          <div class="mt-1 ${metaClass}">${esc(tr('labChecksNodeNameLabel'))}: ${esc(item.node_name || '')}</div>
          <div class="${metaClass}">${esc(tr('labChecksPointsCountLabel'))}: ${esc(scoreValue)}</div>
        </td>
        <td class="px-3 py-2 whitespace-pre-wrap break-words">${esc(outputWithError)}</td>
        <td class="px-3 py-2 whitespace-pre-wrap break-words">${esc(expected)}</td>
      </tr>`;
    }).join('');
  }

  function syncHints() {
    const subline = byId('labChecksSubline');
    const runsHint = byId('labChecksRunsHint');
    if (subline) {
      subline.textContent = state.canManage ? tr('labChecksHintManager') : tr('labChecksHintNoManager');
    }
    if (runsHint) {
      runsHint.textContent = state.canManage ? tr('labChecksHintManager') : tr('labChecksHintNoManager');
    }
  }

  async function loadConfig(showToast) {
    const res = await ctx.jget(apiBase());
    const data = (res && res.data) ? res.data : {};
    state.canManage = !!data.can_manage;
    state.config = normalizeConfigPayload(data);
    state.loaded = true;
    renderManageSection();
    syncHints();
    if (showToast) toast(tr('labChecksLoaded'), 'success');
  }

  async function loadRuns(preferredRunId) {
    const res = await ctx.jget(`${apiRuns()}?limit=80`);
    const data = (res && res.data) ? res.data : {};
    state.runs = Array.isArray(data.runs) ? data.runs : [];

    if (preferredRunId) {
      state.activeRunId = String(preferredRunId || '');
    } else if (!state.activeRunId && state.runs.length) {
      state.activeRunId = String(state.runs[0].id || '');
    }

    renderRunSelect();
    if (state.activeRunId) {
      await loadRunDetails(state.activeRunId);
    } else {
      state.activeRun = null;
      renderRunResult();
    }
  }

  async function loadRunDetails(runId) {
    const id = String(runId || '').trim();
    if (!id) {
      state.activeRunId = '';
      state.activeRun = null;
      renderRunResult();
      return;
    }

    const res = await ctx.jget(apiRun(id));
    const data = (res && res.data) ? res.data : {};
    state.activeRunId = id;
    state.activeRun = data;
    renderRunSelect();
    renderRunResult();
  }

  async function saveConfig() {
    if (!state.canManage) return;
    const payload = collectConfigFromDom();
    const savePayload = {
      settings: payload.settings,
      grades: payload.grades,
      items: (Array.isArray(payload.items) ? payload.items : []).map((item) => ({
        node_id: item.node_id,
        title: item.title,
        transport: item.transport,
        shell_type: item.shell_type,
        command_text: item.command_text,
        match_mode: item.match_mode,
        expected_text: item.expected_text,
        hint_text: item.hint_text,
        points: item.points,
        timeout_seconds: item.timeout_seconds,
        is_enabled: item.is_enabled,
        show_expected_to_learner: item.show_expected_to_learner,
        show_output_to_learner: item.show_output_to_learner,
        ssh_host: item.ssh_host,
        ssh_port: item.ssh_port,
        ssh_username: item.ssh_username,
        ssh_password: item.ssh_password
      }))
    };
    const res = await ctx.jget(apiBase(), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(savePayload)
    });
    const data = (res && res.data) ? res.data : payload;
    state.config = normalizeConfigPayload(data);
    renderManageSection();
    applyModalTheme();
    toast(tr('labChecksSaved'), 'success');
  }

  async function runChecksNow() {
    if (state.runBusy) return;
    const knownIds = Array.isArray(state.runs) ? state.runs.map((run) => String(run && run.id ? run.id : '').trim()).filter((id) => id) : [];
    state.runPollKnownIds = knownIds;
    state.runPollStartedAtMs = Date.now();
    state.runObservedId = '';
    resetRunLiveFeed();
    state.runBusy = true;
    setRunBusyUi(true);
    renderRunLiveFeed();
    startRunProgress();
    startRunPoller();
    try {
      const res = await ctx.jget(`${apiBase()}/run`, { method: 'POST' });
      setRunProgress(true, 96, tr('labChecksRunInProgress'));
      const data = (res && res.data) ? res.data : {};
      const runId = data && data.run && data.run.id ? String(data.run.id) : '';
      if (runId) {
        state.runObservedId = runId;
      }
      await loadRuns(runId);
      const finalRows = sortRunItemsForDisplay(Array.isArray(state.activeRun && state.activeRun.items) ? state.activeRun.items : []);
      syncLiveFeedFromRows(finalRows);
      finishRunProgress(true);
      toast(tr('labChecksRunDone'), 'success');
    } catch (err) {
      finishRunProgress(false);
      throw err;
    } finally {
      stopRunPollTimer();
      state.runBusy = false;
      setRunBusyUi(false);
      renderRunLiveFeed();
      state.runPollKnownIds = [];
      state.runPollStartedAtMs = 0;
      state.runObservedId = '';
    }
  }

  function b64ToBlob(base64, mimeType) {
    const raw = atob(String(base64 || ''));
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
      bytes[i] = raw.charCodeAt(i);
    }
    return new Blob([bytes], { type: String(mimeType || 'application/octet-stream') });
  }

  async function exportRun() {
    const runId = String(state.activeRunId || '');
    if (!runId) {
      toast(tr('labChecksRunEmpty'));
      return;
    }
    const res = await ctx.jget(apiRunExport(runId));
    const data = (res && res.data) ? res.data : {};
    const csvBase64 = String(data.csv_base64 || '');
    if (!csvBase64) {
      throw new Error('empty_export');
    }
    const fileName = String(data.filename || `lab-check-${runId}.csv`);
    const blob = b64ToBlob(csvBase64, data.content_type || 'text/csv; charset=utf-8');
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  async function openModal() {
    const modal = byId('labChecksModal');
    if (!modal || state.loading) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    mountRunSelectDropdown();
    applyModalTheme();

    state.loading = true;
    try {
      await loadConfig(false);
      await loadRuns();
    } finally {
      state.loading = false;
    }
  }

  function closeModal() {
    const modal = byId('labChecksModal');
    if (!modal) return;
    stopRunProgressTimer();
    stopRunPollTimer();
    state.runBusy = false;
    setRunBusyUi(false);
    state.runPollKnownIds = [];
    state.runPollStartedAtMs = 0;
    state.runObservedId = '';
    resetRunLiveFeed();
    setRunProgress(false, 0, tr('labChecksRunInProgress'));
    if (ctx.closeModalDropdownPanels) {
      ctx.closeModalDropdownPanels();
    }
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  function bindEvents() {
    const openBtn = byId('btnLabChecks');
    const closeBtn = byId('labChecksClose');
    const backdrop = byId('labChecksModalBackdrop');
    const saveBtn = byId('labChecksSaveBtn');
    const runBtn = byId('labChecksRunBtn');
    const refreshRunsBtn = byId('labChecksRefreshRunsBtn');
    const exportBtn = byId('labChecksExportBtn');
    const runSelect = byId('labChecksRunSelect');
    const addGradeBtn = byId('labChecksGradeAddBtn');
    const addItemBtn = byId('labChecksAddItemBtn');

    if (!openBtn || !closeBtn || !backdrop || !runBtn) {
      return;
    }

    openBtn.addEventListener('click', async () => {
      try {
        await openModal();
      } catch (err) {
        const message = err && err.message ? err.message : tr('labChecksRunFailed');
        toast(message, 'error');
      }
    });
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    runBtn.addEventListener('click', async () => {
      try {
        await runChecksNow();
      } catch (err) {
        const msg = err && err.message ? err.message : tr('labChecksRunFailed');
        if (String(msg).toLowerCase().includes('no checks configured')) {
          toast(tr('labChecksNoChecksConfigured'));
        } else {
          toast(msg, 'error');
        }
      }
    });

    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        try {
          await saveConfig();
        } catch (err) {
          toast(err && err.message ? err.message : tr('labChecksRunFailed'), 'error');
        }
      });
    }

    if (refreshRunsBtn) {
      refreshRunsBtn.addEventListener('click', async () => {
        try {
          await loadRuns(state.activeRunId || '');
        } catch (err) {
          toast(err && err.message ? err.message : tr('labChecksRunFailed'), 'error');
        }
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', async () => {
        try {
          await exportRun();
        } catch (err) {
          toast(err && err.message ? err.message : tr('labChecksRunFailed'), 'error');
        }
      });
    }

    if (runSelect) {
      runSelect.addEventListener('change', async () => {
        const runId = String(runSelect.value || '');
        state.activeRunId = runId;
        try {
          await loadRunDetails(runId);
        } catch (err) {
          toast(err && err.message ? err.message : tr('labChecksRunFailed'), 'error');
        }
      });
    }

    if (addGradeBtn) {
      addGradeBtn.addEventListener('click', () => {
        collectConfigFromDom();
        state.config.grades.push({ min_percent: 0, grade_label: '' });
        renderManageSection();
        applyModalTheme();
      });
    }

    if (addItemBtn) {
      addItemBtn.addEventListener('click', () => {
        collectConfigFromDom();
        state.config.items.push(defaultItem());
        renderManageSection();
        applyModalTheme();
      });
    }

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (!isModalOpen()) return;
      closeModal();
    });
  }

  window.v2LabChecksThemeSync = function () {
    applyModalTheme();
    renderRunLiveFeed();
  };

  window.v2LabChecksI18nSync = function () {
    if (!isModalOpen()) return;
    renderManageSection();
    renderRunSelect();
    renderRunResult();
    renderRunLiveFeed();
    syncHints();
    applyModalTheme();
  };

  bindEvents();
})();
