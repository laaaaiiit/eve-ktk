(function () {
  function cls(theme, checked, indeterminate, disabled, size) {
    var sizeMap = {
      sm: 'h-4 w-4 text-[11px]',
      md: 'h-5 w-5 text-xs'
    };
    var s = sizeMap[size] || sizeMap.md;
    var base = 'inline-flex items-center justify-center rounded border transition select-none cursor-pointer focus:outline-none focus:ring-2 font-normal leading-none align-middle';
    if (disabled) {
      return base + ' ' + s + ' opacity-50 cursor-not-allowed ' + (theme === 'light'
        ? 'border-slate-300 bg-slate-200 text-slate-400 focus:ring-slate-300'
        : 'border-white/20 bg-slate-800 text-slate-500 focus:ring-slate-500');
    }
    if (checked || indeterminate) {
      return base + ' ' + s + ' text-white focus:ring-emerald-300 ' + (theme === 'light'
        ? 'border-emerald-600 bg-emerald-600 hover:bg-emerald-500'
        : 'border-emerald-500 bg-emerald-500 hover:bg-emerald-400');
    }
    return base + ' ' + s + ' focus:ring-sky-300 ' + (theme === 'light'
      ? 'border-slate-300 bg-white text-transparent hover:border-slate-400'
      : 'border-white/25 bg-slate-900/70 text-transparent hover:border-white/40');
  }

  function iconHtml(checked, indeterminate) {
    if (indeterminate) return '<span style="position:relative;top:-1px;display:block;line-height:1;">−</span>';
    if (checked) return '<span style="display:block;line-height:1;">✓</span>';
    return '<span style="display:block;line-height:1;">✓</span>';
  }

  function mountV2Checkbox(host, options) {
    if (!host) return function () {};
    var opts = options || {};
    var checked = !!opts.checked;
    var indeterminate = !!opts.indeterminate;
    var disabled = !!opts.disabled;
    var size = opts.size || 'md';
    var onChange = typeof opts.onChange === 'function' ? opts.onChange : function () {};
    var getTheme = typeof opts.getTheme === 'function' ? opts.getTheme : function () { return 'dark'; };
    var ariaLabel = opts.ariaLabel || 'checkbox';

    host.innerHTML = '';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('role', 'checkbox');
    btn.setAttribute('aria-label', ariaLabel);
    btn.setAttribute('aria-checked', indeterminate ? 'mixed' : String(checked));
    btn.className = cls(getTheme(), checked, indeterminate, disabled, size);
    btn.innerHTML = iconHtml(checked, indeterminate);
    if (!(checked || indeterminate)) btn.classList.add('text-transparent');
    if (disabled) btn.disabled = true;

    function toggle(e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      if (disabled) return;
      onChange(!checked);
    }

    btn.addEventListener('click', toggle);
    btn.addEventListener('keydown', function (e) {
      if (e.key === ' ' || e.key === 'Enter') toggle(e);
    });

    host.appendChild(btn);
    return function rerender(nextOptions) {
      var merged = Object.assign({}, opts, nextOptions || {});
      return mountV2Checkbox(host, merged);
    };
  }

  window.mountV2Checkbox = mountV2Checkbox;
})();
