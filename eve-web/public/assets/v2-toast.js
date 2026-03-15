(function () {
  function ensureToastStyles() {
    if (document.getElementById('v2-toast-styles')) return;
    var style = document.createElement('style');
    style.id = 'v2-toast-styles';
    style.textContent = "@keyframes v2-toast-ring{from{stroke-dashoffset:0}to{stroke-dashoffset:37.7}}.v2-toast-ring{stroke-dasharray:37.7;stroke-dashoffset:0;animation-name:v2-toast-ring;animation-timing-function:linear;animation-fill-mode:forwards}";
    document.head.appendChild(style);
  }

  function createV2ToastManager(options) {
    ensureToastStyles();
    var cfg = options || {};
    var rootId = cfg.rootId || 'toastRoot';
    var getTheme = typeof cfg.getTheme === 'function' ? cfg.getTheme : function () { return 'dark'; };
    var getLabel = typeof cfg.getLabel === 'function' ? cfg.getLabel : function () { return 'Notifications'; };
    var durationMs = Number(cfg.durationMs || 5000);
    var state = { items: [], expanded: false };

    function escapeHtml(value) {
      var text = String(value == null ? '' : value);
      return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function colors(kind) {
      var theme = getTheme();
      if (kind === 'success') {
        return theme === 'light'
          ? { box: 'border-emerald-300 bg-emerald-50/95 text-emerald-800' }
          : { box: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100' };
      }
      return theme === 'light'
        ? { box: 'border-red-300 bg-red-50/95 text-red-700' }
        : { box: 'border-red-400/40 bg-red-500/15 text-red-100' };
    }

    function dismiss(id) {
      state.items = state.items.filter(function (t) { return t.id !== id; });
      if (!state.items.length) state.expanded = false;
      render();
    }

    function closeControl(id, remainingMs) {
      var theme = getTheme();
      var ringColor = theme === 'light' ? 'text-slate-500' : 'text-slate-300';
      var btnColor = theme === 'light' ? 'text-slate-700 hover:bg-slate-200/80' : 'text-slate-100 hover:bg-white/15';
      var ringDuration = Math.max(120, remainingMs);
      return (
        '<button type="button" data-dismiss="' + id + '" class="relative inline-flex h-6 w-6 items-center justify-center rounded-full ' + btnColor + '">' +
          '<svg class="absolute inset-0 h-6 w-6 -rotate-90 ' + ringColor + '" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
            '<circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.2" opacity="0.22"></circle>' +
            '<circle class="v2-toast-ring" cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.4" style="animation-duration:' + ringDuration + 'ms"></circle>' +
          '</svg>' +
          '<span class="relative z-10 text-[11px] font-semibold leading-none">×</span>' +
        '</button>'
      );
    }

    function bindDismiss(container, id) {
      var btn = container.querySelector('[data-dismiss="' + id + '"]');
      if (!btn) return;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dismiss(id);
      });
    }

    function render() {
      var root = document.getElementById(rootId);
      if (!root) return;

      var now = Date.now();
      state.items = state.items.filter(function (t) { return t.expiresAt > now; });
      root.innerHTML = '';
      if (!state.items.length) return;

      var theme = getTheme();
      var label = escapeHtml(getLabel());

      if (!state.expanded && state.items.length > 1) {
        var latest = state.items[state.items.length - 1];
        var c1 = colors(latest.kind);
        var wrap = document.createElement('button');
        wrap.type = 'button';
        wrap.className = 'relative w-full text-left rounded-xl border px-4 py-3 shadow-2xl backdrop-blur transition hover:-translate-y-0.5 ' + c1.box;
        wrap.innerHTML =
          '<div class="absolute -z-10 inset-x-1 -bottom-2 h-full rounded-xl border ' + (theme === 'light' ? 'border-slate-300 bg-white/80' : 'border-white/10 bg-slate-900/70') + '"></div>' +
          '<div class="absolute -z-20 inset-x-2 -bottom-4 h-full rounded-xl border ' + (theme === 'light' ? 'border-slate-300 bg-white/70' : 'border-white/10 bg-slate-900/60') + '"></div>' +
          '<div class="min-w-0 pr-8 flex min-h-[24px] flex-col justify-center">' +
            '<p class="text-xs font-semibold uppercase leading-4 opacity-80">' + label + ' (' + state.items.length + ')</p>' +
            '<p class="truncate text-sm font-semibold leading-5">' + escapeHtml(latest.message) + '</p>' +
          '</div>' +
          '<div class="absolute right-2 top-1/2 -translate-y-1/2">' + closeControl(latest.id, latest.expiresAt - now) + '</div>';
        bindDismiss(wrap, latest.id);
        wrap.addEventListener('click', function () { state.expanded = true; render(); });
        root.appendChild(wrap);
        return;
      }

      var panel = document.createElement('div');
      panel.className = 'w-full rounded-2xl border p-2 shadow-2xl backdrop-blur ' + (theme === 'light' ? 'border-slate-300 bg-white/90' : 'border-white/15 bg-slate-900/85');
      var head = document.createElement('div');
      head.className = 'mb-2 flex items-center justify-between px-2';
      head.innerHTML = '<p class="text-xs font-semibold uppercase ' + (theme === 'light' ? 'text-slate-600' : 'text-slate-300') + '">' + label + ' (' + state.items.length + ')</p>';
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'rounded-lg px-2 py-1 text-xs ' + (theme === 'light' ? 'text-slate-700 hover:bg-slate-100' : 'text-slate-200 hover:bg-white/10');
      close.textContent = '×';
      close.addEventListener('click', function () { state.expanded = false; render(); });
      head.appendChild(close);
      panel.appendChild(head);

      state.items.slice().reverse().forEach(function (t) {
        var c2 = colors(t.kind);
        var item = document.createElement('div');
        item.className = 'mb-2 rounded-xl border px-3 py-2 ' + c2.box;
        item.innerHTML =
          '<div class="flex min-h-[24px] items-center justify-between gap-3">' +
            '<p class="text-sm leading-5">' + escapeHtml(t.message) + '</p>' +
            closeControl(t.id, t.expiresAt - now) +
          '</div>';
        bindDismiss(item, t.id);
        panel.appendChild(item);
      });

      root.appendChild(panel);
    }

    function toast(message, kind) {
      var id = Date.now() + Math.random();
      state.items.push({ id: id, message: String(message || ''), kind: kind || 'error', expiresAt: Date.now() + durationMs });
      render();
      setTimeout(function () {
        state.items = state.items.filter(function (t) { return t.id !== id; });
        if (!state.items.length) state.expanded = false;
        render();
      }, durationMs);
    }

    return { toast: toast, render: render, dismiss: dismiss };
  }

  window.createV2ToastManager = createV2ToastManager;
})();
