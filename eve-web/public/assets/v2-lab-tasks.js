(function () {
  const ctx = window.v2LabTasksContext;
  if (!ctx) return;

  const state = {
    loaded: false,
    loading: false,
    saving: false,
    marksSaving: false,
    marksSavePending: false,
    marksTimer: null,
    canManage: false,
    config: {
      settings: { intro_text: '' },
      items: []
    },
    doneItemIds: new Set(),
    drag: {
      fromIdx: -1,
      overIdx: -1,
      overPlacement: '',
    },
  };

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

  function getLang() {
    return String((ctx.getLang && ctx.getLang()) || 'ru');
  }

  function getTheme() {
    return String((ctx.getTheme && ctx.getTheme()) || 'dark');
  }

  function apiBase() {
    const labId = String((ctx.getLabId && ctx.getLabId()) || '').trim();
    return `/api/labs/${encodeURIComponent(labId)}/checks/tasks`;
  }

  function apiMarks() {
    return `${apiBase()}/marks`;
  }

  function apiSyncCopies() {
    return `${apiBase()}/sync-copies`;
  }

  function isModalOpen() {
    const modal = byId('labTasksModal');
    return !!(modal && !modal.classList.contains('hidden'));
  }

  function setInlineBtnVisible(el, visible) {
    if (!el) return;
    if (visible) {
      el.classList.remove('hidden');
      el.classList.add('inline-flex');
      el.style.display = 'inline-flex';
    } else {
      el.classList.add('hidden');
      el.classList.remove('inline-flex');
      el.style.display = 'none';
    }
  }

  function boolOf(value) {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value !== 0;
    const txt = String(value == null ? '' : value).trim().toLowerCase();
    return txt === '1' || txt === 'true' || txt === 'yes' || txt === 'on';
  }

  function fillTokens(template, tokens) {
    let out = String(template == null ? '' : template);
    const map = tokens && typeof tokens === 'object' ? tokens : {};
    Object.keys(map).forEach((key) => {
      const val = String(map[key] == null ? '' : map[key]);
      out = out.split(`{${key}}`).join(val);
    });
    return out;
  }

  function normalizeConfigPayload(payload) {
    const src = (payload && typeof payload === 'object') ? payload : {};
    const settings = (src.settings && typeof src.settings === 'object') ? src.settings : {};
    const itemsRaw = Array.isArray(src.items) ? src.items : [];
    const doneRaw = Array.isArray(src.done_item_ids) ? src.done_item_ids : [];

    const items = [];
    itemsRaw.forEach((item, idx) => {
      const row = (item && typeof item === 'object') ? item : {};
      const id = String(row.id || '').trim();
      const text = String(row.task_text || row.text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
      if (text === '') return;
      items.push({
        id,
        task_text: text,
        is_enabled: boolOf(row.is_enabled),
        order_index: Number.isFinite(Number(row.order_index)) ? Number(row.order_index) : idx,
      });
    });

    items.sort((a, b) => {
      if (a.order_index !== b.order_index) return a.order_index - b.order_index;
      return String(a.id).localeCompare(String(b.id));
    });

    const doneIds = [];
    const seen = Object.create(null);
    doneRaw.forEach((raw) => {
      const id = String(raw || '').trim();
      if (!id || seen[id]) return;
      seen[id] = true;
      doneIds.push(id);
    });

    return {
      settings: {
        intro_text: String(settings.intro_text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim(),
      },
      items,
      done_item_ids: doneIds,
    };
  }

  function collectConfigFromDom() {
    const introInput = byId('labTasksIntroInput');
    const introText = String((introInput && introInput.value) || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();

    const items = state.config.items
      .map((item, idx) => ({
        task_text: String((item && item.task_text) || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim(),
        is_enabled: true,
        order_index: idx,
      }))
      .filter((item) => item.task_text !== '');

    return {
      settings: { intro_text: introText },
      items,
    };
  }

  function buildTemplatePayload() {
    const current = collectConfigFromDom();
    return {
      template_type: 'eve_lab_tasks_config',
      version: 1,
      exported_at: new Date().toISOString(),
      payload: {
        settings: {
          intro_text: String((current.settings && current.settings.intro_text) || ''),
        },
        items: (Array.isArray(current.items) ? current.items : []).map((item, idx) => ({
          task_text: String((item && item.task_text) || ''),
          is_enabled: true,
          order_index: Number.isFinite(Number(item && item.order_index)) ? Number(item.order_index) : idx,
        })),
      },
    };
  }

  function normalizeTemplatePayload(rawPayload) {
    const src = (rawPayload && typeof rawPayload === 'object') ? rawPayload : {};
    const payload = (src.payload && typeof src.payload === 'object') ? src.payload : src;
    const normalized = normalizeConfigPayload({
      settings: payload.settings || {},
      items: payload.items || [],
      done_item_ids: [],
    });
    return {
      settings: normalized.settings,
      items: normalized.items.map((item, idx) => ({
        id: '',
        task_text: String(item.task_text || ''),
        is_enabled: true,
        order_index: idx,
      }))
    };
  }

  function textInputClass() {
    if (getTheme() === 'light') {
      return 'lab-input w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none ring-sky-300 focus:ring-2';
    }
    return 'lab-input w-full rounded-xl border border-white/20 bg-slate-950/60 px-3 py-2 text-sm text-slate-100 outline-none ring-cyan-300/40 focus:ring-2';
  }

  function manageRowClass() {
    return getTheme() === 'light'
      ? 'flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-2 py-2'
      : 'flex items-center gap-2 rounded-xl border border-slate-500/35 bg-slate-950/40 px-2 py-2';
  }

  function viewerRowClass(done) {
    if (getTheme() === 'light') {
      return `flex items-start gap-2 rounded-xl border border-slate-200 px-3 py-2 ${done ? 'bg-emerald-50' : 'bg-white'}`;
    }
    return `flex items-start gap-2 rounded-xl border border-slate-500/35 px-3 py-2 ${done ? 'bg-emerald-500/10' : 'bg-slate-950/35'}`;
  }

  function deleteBtnClass() {
    return getTheme() === 'light'
      ? 'inline-flex items-center justify-center rounded-lg border border-rose-300 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-100'
      : 'inline-flex items-center justify-center rounded-lg border border-rose-400/45 bg-rose-500/10 px-2 py-1 text-xs font-semibold text-rose-200 hover:bg-rose-500/20';
  }

  function insertBtnClass() {
    return getTheme() === 'light'
      ? 'js-task-insert inline-flex items-center justify-center gap-1 rounded-lg border border-blue-300 bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-100'
      : 'js-task-insert inline-flex items-center justify-center gap-1 rounded-lg border border-blue-400/45 bg-blue-500/10 px-2 py-1 text-xs font-semibold text-blue-200 hover:bg-blue-500/20';
  }

  function dragHandleClass() {
    return getTheme() === 'light'
      ? 'js-task-drag-handle inline-flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-100 active:cursor-grabbing'
      : 'js-task-drag-handle inline-flex h-8 w-8 shrink-0 cursor-grab items-center justify-center rounded-lg border border-slate-500/45 bg-slate-900/60 text-slate-300 hover:bg-slate-800/60 active:cursor-grabbing';
  }

  function updateTaskOrderIndexes() {
    if (!Array.isArray(state.config.items)) return;
    state.config.items.forEach((item, idx) => {
      if (!item || typeof item !== 'object') return;
      item.order_index = idx;
    });
  }

  function insertTaskAt(index) {
    if (!state.canManage) return;
    if (!Array.isArray(state.config.items)) state.config.items = [];
    const nextIndex = Math.max(0, Math.min(Number(index) || 0, state.config.items.length));
    state.config.items.splice(nextIndex, 0, { id: '', task_text: '', is_enabled: true, order_index: nextIndex });
    updateTaskOrderIndexes();
    renderItems();
    setTimeout(() => {
      const input = byId('labTasksItemsList')
        ? byId('labTasksItemsList').querySelector(`.js-task-text[data-task-idx="${nextIndex}"]`)
        : null;
      if (input) input.focus();
    }, 0);
  }

  function clearTaskDragMarkers() {
    const list = byId('labTasksItemsList');
    if (!list) return;
    list.querySelectorAll('.js-task-manage-row').forEach((row) => {
      row.style.outline = '';
      row.style.outlineOffset = '';
      row.style.boxShadow = '';
      row.style.opacity = '';
      row.style.transform = '';
    });
    list.style.outline = '';
    list.style.outlineOffset = '';
  }

  function resetTaskDragState() {
    state.drag.fromIdx = -1;
    state.drag.overIdx = -1;
    state.drag.overPlacement = '';
    clearTaskDragMarkers();
  }

  function applyTaskDragUiState() {
    const list = byId('labTasksItemsList');
    if (!list) return;
    clearTaskDragMarkers();
    if (!state.canManage) return;
    if (!Number.isInteger(state.drag.fromIdx) || state.drag.fromIdx < 0) return;

    const light = getTheme() === 'light';
    const draggedRow = list.querySelector(`.js-task-manage-row[data-task-idx="${state.drag.fromIdx}"]`);
    if (draggedRow) {
      draggedRow.style.opacity = '0.55';
      draggedRow.style.transform = 'scale(0.99)';
      draggedRow.style.outline = light ? '1px solid rgba(14, 165, 233, 0.45)' : '1px solid rgba(56, 189, 248, 0.5)';
      draggedRow.style.outlineOffset = '1px';
    }

    const overIdx = Number(state.drag.overIdx);
    const placement = String(state.drag.overPlacement || '');
    if (Number.isInteger(overIdx) && overIdx >= 0) {
      const targetRow = list.querySelector(`.js-task-manage-row[data-task-idx="${overIdx}"]`);
      if (targetRow) {
        const color = light ? 'rgba(14, 165, 233, 0.95)' : 'rgba(56, 189, 248, 0.95)';
        targetRow.style.boxShadow = placement === 'after'
          ? `inset 0 -3px 0 0 ${color}`
          : `inset 0 3px 0 0 ${color}`;
      }
      return;
    }

    list.style.outline = light ? '2px dashed rgba(14, 165, 233, 0.75)' : '2px dashed rgba(56, 189, 248, 0.8)';
    list.style.outlineOffset = '2px';
  }

  function resolveDropTarget(event) {
    const row = event && event.target && event.target.closest ? event.target.closest('.js-task-manage-row') : null;
    if (!row) {
      return {
        toIdx: state.config.items.length,
        overIdx: -1,
        placement: 'after',
      };
    }

    const idx = Number(row.getAttribute('data-task-idx'));
    if (!Number.isInteger(idx) || idx < 0) {
      return {
        toIdx: state.config.items.length,
        overIdx: -1,
        placement: 'after',
      };
    }

    const rect = row.getBoundingClientRect();
    const clientY = Number(event && event.clientY);
    const placement = Number.isFinite(clientY) && rect.height > 0 && (clientY - rect.top) > (rect.height / 2)
      ? 'after'
      : 'before';

    return {
      toIdx: placement === 'after' ? idx + 1 : idx,
      overIdx: idx,
      placement,
    };
  }

  function moveTaskItem(fromIdx, toIdx) {
    const items = Array.isArray(state.config.items) ? state.config.items : [];
    if (!Number.isInteger(fromIdx) || !Number.isInteger(toIdx)) return false;
    if (fromIdx < 0 || toIdx < 0 || fromIdx >= items.length || toIdx > items.length) return false;
    if (fromIdx === toIdx || fromIdx + 1 === toIdx) return false;
    const moved = items.splice(fromIdx, 1)[0];
    const insertIdx = fromIdx < toIdx ? Math.max(0, toIdx - 1) : toIdx;
    items.splice(insertIdx, 0, moved);
    updateTaskOrderIndexes();
    return true;
  }

  function bindViewerCheckboxes() {
    if (state.canManage) return;
    const list = byId('labTasksItemsList');
    if (!list) return;
    if (!window.mountV2Checkbox) return;

    list.querySelectorAll('.js-task-done').forEach((input) => {
      repaintViewerCheckboxForInput(input);
    });
  }

  function repaintViewerCheckboxForInput(input) {
    if (!input) return;
    if (state.canManage) return;
    if (!window.mountV2Checkbox) return;
    const host = input.parentElement ? input.parentElement.querySelector('.js-task-done-host') : null;
    if (!host) return;
    window.mountV2Checkbox(host, {
      checked: !!input.checked,
      disabled: false,
      size: 'sm',
      ariaLabel: 'task-done',
      getTheme,
      onChange: (next) => {
        const boolNext = !!next;
        if (!!input.checked === boolNext) return;
        input.checked = boolNext;
        repaintViewerCheckboxForInput(input);
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function updateViewerRowForInput(input) {
    if (!input) return;
    const row = input.closest('.js-task-row');
    if (!row) return;
    const checked = !!input.checked;
    row.className = `js-task-row ${viewerRowClass(checked)}`;
    const label = row.querySelector('.js-task-label');
    if (label) {
      label.className = `js-task-label text-sm ${checked ? 'line-through opacity-70' : ''}`;
    }
  }

  function syncViewerRowsFromState() {
    if (state.canManage) return;
    const list = byId('labTasksItemsList');
    if (!list) return;
    list.querySelectorAll('.js-task-done').forEach((input) => {
      const taskId = String(input.getAttribute('data-task-id') || '').trim();
      const shouldBeChecked = !!(taskId && state.doneItemIds.has(taskId));
      if (input.checked !== shouldBeChecked) {
        input.checked = shouldBeChecked;
      }
      updateViewerRowForInput(input);
      repaintViewerCheckboxForInput(input);
    });
  }

  function applyModalTheme() {
    const card = byId('labTasksModalCard');
    const noManage = byId('labTasksNoManage');
    const introWrap = byId('labTasksIntroWrap');

    if (!card) return;
    if (getTheme() === 'light') {
      card.className = 'relative flex h-[90vh] w-full max-w-[98vw] max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-2xl xl:w-[68vw] xl:max-w-[68vw]';
      if (noManage) noManage.className = 'hidden mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700';
      if (introWrap) introWrap.className = 'mb-3';
    } else {
      card.className = 'relative flex h-[90vh] w-full max-w-[98vw] max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-white/20 bg-slate-900 text-slate-100 shadow-2xl xl:w-[68vw] xl:max-w-[68vw]';
      if (noManage) noManage.className = 'hidden mb-3 rounded-xl border border-slate-500/35 bg-slate-950/45 px-3 py-3 text-sm text-slate-200';
      if (introWrap) introWrap.className = 'mb-3';
    }

    const introInput = byId('labTasksIntroInput');
    if (introInput) {
      introInput.className = textInputClass();
    }
  }

  function renderHeaderState() {
    const subline = byId('labTasksSubline');
    const noManage = byId('labTasksNoManage');
    const addBtn = byId('labTasksAddItemBtn');
    const saveBtn = byId('labTasksSaveBtn');
    const importBtn = byId('labTasksImportBtn');
    const exportBtn = byId('labTasksExportBtn');
    const syncBtn = byId('labTasksSyncCopiesBtn');
    const closeBtn = byId('labTasksClose');
    const introInput = byId('labTasksIntroInput');

    if (subline) {
      subline.textContent = state.canManage ? tr('labTasksHintManager') : tr('labTasksHintNoManager');
    }
    if (noManage) {
      noManage.classList.toggle('hidden', !!state.canManage);
    }

    setInlineBtnVisible(addBtn, !!state.canManage);
    setInlineBtnVisible(saveBtn, !!state.canManage);
    setInlineBtnVisible(importBtn, !!state.canManage);
    setInlineBtnVisible(exportBtn, !!state.canManage);
    setInlineBtnVisible(syncBtn, !!state.canManage);
    setInlineBtnVisible(closeBtn, true);

    if (introInput) {
      introInput.disabled = !state.canManage;
      introInput.value = String((state.config.settings && state.config.settings.intro_text) || '');
      introInput.placeholder = tr('labTasksIntroLabel');
    }
  }

  function renderItems() {
    const list = byId('labTasksItemsList');
    if (!list) return;

    const items = Array.isArray(state.config.items) ? state.config.items : [];
    if (!items.length) {
      list.innerHTML = `
        <div class="${getTheme() === 'light' ? 'rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-sm text-slate-600' : 'rounded-xl border border-dashed border-slate-500/40 bg-slate-950/35 px-3 py-4 text-sm text-slate-300'}">
          ${esc(tr('labTasksEmpty'))}
        </div>
      `;
      return;
    }

    if (state.canManage) {
      list.innerHTML = items.map((item, idx) => {
        const text = String((item && item.task_text) || '');
        return `
            <div class="js-task-manage-row ${manageRowClass()}" data-task-idx="${idx}">
              <button type="button"
                      class="${dragHandleClass()}"
                      data-task-idx="${idx}"
                      draggable="true"
                      title="${esc(tr('labTasksDragTitle'))}">
                <i class="fa fa-bars"></i>
              </button>
              <span class="shrink-0 text-xs font-semibold ${getTheme() === 'light' ? 'text-slate-600' : 'text-slate-300'}">${idx + 1}.</span>
              <input type="text"
                     class="js-task-text ${textInputClass()}"
                     data-task-idx="${idx}"
                     value="${esc(text)}"
                     placeholder="${esc(tr('labTasksItemPlaceholder'))}">
              <div class="flex shrink-0 flex-col items-stretch gap-1">
                <button type="button"
                        class="js-task-delete ${deleteBtnClass()}"
                        data-task-idx="${idx}">
                  <i class="fa fa-trash"></i>
                  <span class="ml-1">${esc(tr('labTasksDelete'))}</span>
                </button>
                <button type="button"
                        class="${insertBtnClass()}"
                        data-task-insert-at="${idx + 1}">
                  <i class="fa fa-plus"></i>
                  <span>${esc(tr('labTasksAdd'))}</span>
                </button>
              </div>
            </div>
        `;
      }).join('');
      applyTaskDragUiState();
      return;
    }

    list.innerHTML = items.map((item, idx) => {
      const id = String((item && item.id) || '').trim();
      const text = String((item && item.task_text) || '');
      const checked = !!(id && state.doneItemIds.has(id));
      return `
        <label class="js-task-row ${viewerRowClass(checked)}" data-task-id="${esc(id)}">
          <input type="checkbox"
                 class="js-task-done sr-only"
                 data-task-id="${esc(id)}"
                 ${checked ? 'checked' : ''}>
          <span class="js-task-done-host inline-flex"></span>
          <span class="js-task-label text-sm ${checked ? 'line-through opacity-70' : ''}">
            <span class="mr-1 text-xs opacity-70">${idx + 1}.</span>${esc(text)}
          </span>
        </label>
      `;
    }).join('');
    bindViewerCheckboxes();
  }

  function renderAll() {
    applyModalTheme();
    renderHeaderState();
    renderItems();
  }

  async function loadConfig(showToast) {
    const res = await ctx.jget(apiBase());
    const data = (res && res.data) ? res.data : {};
    state.canManage = !!data.can_manage;

    const normalized = normalizeConfigPayload(data);
    state.config = {
      settings: normalized.settings,
      items: normalized.items.map((item, idx) => ({
        id: String(item.id || ''),
        task_text: String(item.task_text || ''),
        is_enabled: true,
        order_index: Number.isFinite(Number(item.order_index)) ? Number(item.order_index) : idx,
      }))
    };

    state.doneItemIds = new Set(normalized.done_item_ids);
    state.loaded = true;
    renderAll();
    if (showToast) {
      toast(tr('labTasksLoaded'), 'success');
    }
  }

  async function saveConfig() {
    if (!state.canManage || state.saving) return;
    state.saving = true;
    try {
      const payload = collectConfigFromDom();
      const res = await ctx.jget(apiBase(), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = (res && res.data) ? res.data : payload;
      const normalized = normalizeConfigPayload(data);
      state.config = {
        settings: normalized.settings,
        items: normalized.items.map((item, idx) => ({
          id: String(item.id || ''),
          task_text: String(item.task_text || ''),
          is_enabled: true,
          order_index: Number.isFinite(Number(item.order_index)) ? Number(item.order_index) : idx,
        }))
      };
      renderAll();
      toast(tr('labTasksSaved'), 'success');
    } catch (err) {
      toast((err && err.message) ? err.message : tr('labTasksSaveFailed'));
    } finally {
      state.saving = false;
    }
  }

  async function saveMarksNow() {
    if (state.canManage || state.marksSaving) return;
    state.marksSaving = true;
    try {
      const payload = {
        done_item_ids: Array.from(state.doneItemIds)
      };
      const res = await ctx.jget(apiMarks(), {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      // Keep optimistic local state to avoid checkbox flicker while autosave is running.
      void res;
    } catch (_) {
      toast(tr('labTasksMarksSaveFailed'));
    } finally {
      state.marksSaving = false;
      if (state.marksSavePending) {
        state.marksSavePending = false;
        saveMarksNow();
      }
    }
  }

  function scheduleMarksSave() {
    if (state.canManage) return;
    state.marksSavePending = true;
    if (state.marksTimer) {
      clearTimeout(state.marksTimer);
      state.marksTimer = null;
    }
    state.marksTimer = setTimeout(() => {
      state.marksTimer = null;
      if (state.marksSaving) {
        state.marksSavePending = true;
        return;
      }
      state.marksSavePending = false;
      saveMarksNow();
    }, 260);
  }

  async function exportTemplate() {
    if (!state.canManage) return;
    const template = buildTemplatePayload();
    const blob = new Blob([JSON.stringify(template, null, 2)], { type: 'application/json;charset=utf-8' });
    const stamp = new Date().toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
    const fileName = `lab-tasks-template-${stamp}.json`;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    setTimeout(() => {
      URL.revokeObjectURL(url);
      link.remove();
    }, 0);
    toast(tr('labTasksExportDone'), 'success');
  }

  async function syncTasksToCopies() {
    if (!state.canManage) {
      toast(tr('labTasksNoAccessConfig'), 'error');
      return;
    }
    const res = await ctx.jget(apiSyncCopies(), { method: 'POST' });
    const data = (res && res.data) ? res.data : {};
    const updated = Number(data.updated || 0);
    const failed = Number(data.failed || 0);
    const msg = fillTokens(tr('labTasksSyncCopiesDone'), {
      updated: String(updated),
      failed: String(failed),
    });
    toast(msg, failed > 0 ? 'error' : 'success');
    await loadConfig(false);
  }

  async function importTemplateFromFile(file) {
    if (!state.canManage || !file) return;
    let text = '';
    try {
      text = await file.text();
    } catch (_) {
      throw new Error(tr('labTasksImportReadFailed'));
    }

    let parsed = null;
    try {
      parsed = JSON.parse(text);
    } catch (_) {
      throw new Error(tr('labTasksImportInvalid'));
    }

    const normalized = normalizeTemplatePayload(parsed);
    state.config = {
      settings: {
        intro_text: String((normalized.settings && normalized.settings.intro_text) || ''),
      },
      items: (Array.isArray(normalized.items) ? normalized.items : []).map((item, idx) => ({
        id: '',
        task_text: String((item && item.task_text) || ''),
        is_enabled: true,
        order_index: idx,
      })),
    };
    renderAll();
    toast(tr('labTasksImportDone'), 'success');
  }

  function openModalShell() {
    const modal = byId('labTasksModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeModal() {
    const modal = byId('labTasksModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    if (state.marksTimer) {
      clearTimeout(state.marksTimer);
      state.marksTimer = null;
    }
  }

  async function openModal() {
    if (state.loading) return;
    openModalShell();
    state.loading = true;
    try {
      await loadConfig(false);
    } catch (err) {
      toast((err && err.message) ? err.message : tr('labTasksLoadFailed'));
      closeModal();
    } finally {
      state.loading = false;
    }
  }

  function bindEvents() {
    const openBtn = byId('btnLabTasks');
    const closeBtn = byId('labTasksClose');
    const backdrop = byId('labTasksModalBackdrop');
    const addBtn = byId('labTasksAddItemBtn');
    const saveBtn = byId('labTasksSaveBtn');
    const importBtn = byId('labTasksImportBtn');
    const exportBtn = byId('labTasksExportBtn');
    const syncBtn = byId('labTasksSyncCopiesBtn');
    const importInput = byId('labTasksImportFileInput');
    const introInput = byId('labTasksIntroInput');
    const itemsList = byId('labTasksItemsList');

    if (openBtn) {
      openBtn.addEventListener('click', async () => {
        await openModal();
      });
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        closeModal();
      });
    }
    if (backdrop) {
      backdrop.addEventListener('click', () => {
        closeModal();
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        await saveConfig();
      });
    }

    if (addBtn) {
      addBtn.addEventListener('click', () => {
        insertTaskAt(state.config.items.length);
      });
    }

    if (importBtn && importInput) {
      importBtn.addEventListener('click', () => {
        if (!state.canManage) return;
        importInput.value = '';
        importInput.click();
      });
      importInput.addEventListener('change', async () => {
        const file = importInput.files && importInput.files[0] ? importInput.files[0] : null;
        if (!file) return;
        try {
          await importTemplateFromFile(file);
        } catch (err) {
          toast((err && err.message) ? err.message : tr('labTasksImportReadFailed'));
        } finally {
          importInput.value = '';
        }
      });
    }

    if (exportBtn) {
      exportBtn.addEventListener('click', async () => {
        try {
          await exportTemplate();
        } catch (err) {
          toast((err && err.message) ? err.message : tr('labTasksExportDone'));
        }
      });
    }

    if (syncBtn) {
      syncBtn.addEventListener('click', async () => {
        try {
          await syncTasksToCopies();
        } catch (err) {
          toast((err && err.message) ? err.message : tr('labTasksSyncCopiesFailed'));
        }
      });
    }

    if (introInput) {
      introInput.addEventListener('input', (e) => {
        if (!state.canManage) return;
        state.config.settings.intro_text = String(e && e.target ? e.target.value : '');
      });
    }

    if (itemsList) {
      itemsList.addEventListener('input', (e) => {
        const target = e && e.target;
        if (!target) return;
        if (state.canManage && target.classList.contains('js-task-text')) {
          const idx = Number(target.getAttribute('data-task-idx'));
          if (!Number.isInteger(idx) || idx < 0 || idx >= state.config.items.length) return;
          state.config.items[idx].task_text = String(target.value || '');
        }
      });

      itemsList.addEventListener('change', (e) => {
        const target = e && e.target;
        if (!target) return;
        if (!state.canManage && target.classList.contains('js-task-done')) {
          const taskId = String(target.getAttribute('data-task-id') || '').trim();
          if (!taskId) return;
          if (target.checked) {
            state.doneItemIds.add(taskId);
          } else {
            state.doneItemIds.delete(taskId);
          }
          updateViewerRowForInput(target);
          repaintViewerCheckboxForInput(target);
          scheduleMarksSave();
        }
      });

      itemsList.addEventListener('click', (e) => {
        if (!state.canManage) return;
        const insertBtn = e.target && e.target.closest ? e.target.closest('.js-task-insert') : null;
        if (insertBtn) {
          const insertAt = Number(insertBtn.getAttribute('data-task-insert-at'));
          if (Number.isInteger(insertAt) && insertAt >= 0) {
            insertTaskAt(insertAt);
          }
          return;
        }
        const deleteBtn = e.target && e.target.closest ? e.target.closest('.js-task-delete') : null;
        if (!deleteBtn) return;
        const idx = Number(deleteBtn.getAttribute('data-task-idx'));
        if (!Number.isInteger(idx) || idx < 0 || idx >= state.config.items.length) return;
        state.config.items.splice(idx, 1);
        updateTaskOrderIndexes();
        renderItems();
      });

      itemsList.addEventListener('dragstart', (e) => {
        if (!state.canManage) return;
        const handle = e.target && e.target.closest ? e.target.closest('.js-task-drag-handle') : null;
        if (!handle) {
          e.preventDefault();
          return;
        }
        const fromIdx = Number(handle.getAttribute('data-task-idx'));
        if (!Number.isInteger(fromIdx) || fromIdx < 0 || fromIdx >= state.config.items.length) {
          e.preventDefault();
          return;
        }
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', String(fromIdx));
        }
        state.drag.fromIdx = fromIdx;
        state.drag.overIdx = fromIdx;
        state.drag.overPlacement = 'before';
        applyTaskDragUiState();
      });

      itemsList.addEventListener('dragover', (e) => {
        if (!state.canManage) return;
        if (!Number.isInteger(state.drag.fromIdx) || state.drag.fromIdx < 0) return;
        e.preventDefault();
        if (e.dataTransfer) {
          e.dataTransfer.dropEffect = 'move';
        }
        const target = resolveDropTarget(e);
        state.drag.overIdx = target.overIdx;
        state.drag.overPlacement = target.placement;
        applyTaskDragUiState();
      });

      itemsList.addEventListener('drop', (e) => {
        if (!state.canManage) return;
        e.preventDefault();
        const fromRaw = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
        const fromParsed = Number(fromRaw);
        const fromIdx = Number.isInteger(fromParsed) ? fromParsed : Number(state.drag.fromIdx);
        if (!Number.isInteger(fromIdx)) {
          resetTaskDragState();
          return;
        }
        const target = resolveDropTarget(e);
        const toIdx = Number(target.toIdx);
        if (!Number.isInteger(toIdx)) {
          resetTaskDragState();
          return;
        }
        if (moveTaskItem(fromIdx, toIdx)) {
          renderItems();
        }
        resetTaskDragState();
      });

      itemsList.addEventListener('dragend', () => {
        resetTaskDragState();
      });
    }

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (!isModalOpen()) return;
      closeModal();
    });
  }

  window.v2LabTasksThemeSync = function () {
    if (!isModalOpen()) return;
    renderAll();
  };

  window.v2LabTasksI18nSync = function () {
    if (!isModalOpen()) return;
    renderAll();
  };

  bindEvents();
})();
