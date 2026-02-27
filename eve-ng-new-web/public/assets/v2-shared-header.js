(function () {
  function renderV2Header(targetId) {
    var host = document.getElementById(targetId);
    if (!host) return;

    host.innerHTML = [
      '<section id="topbar" class="relative z-40 w-full border-b border-white/20 bg-slate-800/85 px-4 py-4 shadow-[0_20px_60px_rgba(2,6,23,0.6)] backdrop-blur-xl transition-colors duration-300">',
      '  <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-4 sm:px-2">',
      '    <nav class="flex flex-wrap items-center gap-2">',
      '      <a id="navHome" href="/main" class="rounded-xl border px-4 py-2 text-sm font-semibold transition cursor-pointer inline-flex items-center gap-2">',
      '        <i class="fa fa-home" aria-hidden="true"></i>',
      '        <span>Главная</span>',
      '      </a>',
      '      <div id="navManagement" class="min-w-[180px]"></div>',
      '      <div id="navSystem" class="min-w-[180px]"></div>',
      '    </nav>',
      '    <div class="ml-auto flex flex-wrap items-center justify-end gap-2">',
      '      <div class="w-40" data-dd="lang"></div>',
      '      <div class="w-40" data-dd="theme"></div>',
      '      <div class="flex items-center gap-2">',
      '        <button id="profileBtn" type="button" class="rounded-2xl border px-4 py-2 text-left transition cursor-pointer">',
      '          <p id="profileName" class="text-sm font-semibold">user</p>',
      '          <p id="profileRole" class="text-xs">admin</p>',
      '        </button>',
      '        <button id="logoutBtn" class="rounded-xl border px-4 py-2 text-sm font-semibold shadow-sm transition">Выход</button>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</section>'
    ].join('');
  }

  window.renderV2Header = renderV2Header;
})();
