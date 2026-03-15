(function () {
  var PROFILE_I18N = {
    ru: {
      title: 'Настройки профиля',
      close: 'Закрыть',
      save: 'Сохранить',
      saving: 'Сохранение...',
      user: 'Пользователь',
      role: 'Роль',
      lang: 'Язык',
      theme: 'Тема',
      langRu: 'Русский',
      langEn: 'English',
      themeDark: 'Темная',
      themeLight: 'Светлая',
      passwordSection: 'Смена пароля',
      currentPassword: 'Текущий пароль',
      newPassword: 'Новый пароль',
      confirmPassword: 'Подтвердите пароль',
      errProfileLoad: 'Не удалось загрузить профиль.',
      errNoChanges: 'Нет изменений для сохранения.',
      errPasswordFields: 'Для смены пароля заполните все поля.',
      errPasswordMismatch: 'Новый пароль и подтверждение не совпадают.',
      errPasswordShort: 'Новый пароль должен быть не короче 8 символов.',
      errCurrentInvalid: 'Текущий пароль указан неверно.',
      errPasswordSame: 'Новый пароль совпадает с текущим.',
      okSaved: 'Изменения сохранены.',
      okSavedReload: 'Изменения сохранены. Страница перезагрузится.',
      unknown: 'unknown'
    },
    en: {
      title: 'Profile Settings',
      close: 'Close',
      save: 'Save',
      saving: 'Saving...',
      user: 'User',
      role: 'Role',
      lang: 'Language',
      theme: 'Theme',
      langRu: 'Russian',
      langEn: 'English',
      themeDark: 'Dark',
      themeLight: 'Light',
      passwordSection: 'Change Password',
      currentPassword: 'Current password',
      newPassword: 'New password',
      confirmPassword: 'Confirm password',
      errProfileLoad: 'Failed to load profile.',
      errNoChanges: 'No changes to save.',
      errPasswordFields: 'Fill in all password fields.',
      errPasswordMismatch: 'New password and confirmation do not match.',
      errPasswordShort: 'New password must be at least 8 characters.',
      errCurrentInvalid: 'Current password is invalid.',
      errPasswordSame: 'New password matches current password.',
      okSaved: 'Changes saved.',
      okSavedReload: 'Changes saved. Reloading page.',
      unknown: 'unknown'
    }
  };

  var profileModalState = {
    initialized: false,
    saving: false,
    auth: null,
    dropdownHandlersBound: false
  };

  function profileLang() {
    var raw = String(localStorage.getItem('eve_v2_lang') || 'ru').toLowerCase();
    return raw === 'en' ? 'en' : 'ru';
  }

  function profileTheme() {
    var raw = String(localStorage.getItem('eve_v2_theme') || 'light').toLowerCase();
    return raw === 'dark' ? 'dark' : 'light';
  }

  function trProfile(key) {
    var lang = profileLang();
    var dict = PROFILE_I18N[lang] || PROFILE_I18N.ru;
    return dict[key] || key;
  }

  function profileById(id) {
    return document.getElementById(id);
  }

  function profileCloseDropdownPanels() {
    var modal = profileById('v2ProfileModal');
    if (!modal) return;
    var panels = modal.querySelectorAll('.v2-prof-dd-panel');
    for (var i = 0; i < panels.length; i += 1) {
      panels[i].classList.add('hidden');
    }
    var buttons = modal.querySelectorAll('[data-v2-prof-dd-btn=\"1\"]');
    for (var j = 0; j < buttons.length; j += 1) {
      buttons[j].setAttribute('aria-expanded', 'false');
    }
  }

  function profileBindDropdownGlobalHandlers() {
    if (profileModalState.dropdownHandlersBound) return;
    document.addEventListener('click', function (event) {
      var modal = profileById('v2ProfileModal');
      if (!modal || modal.classList.contains('hidden')) return;
      var target = event && event.target && event.target.closest ? event.target.closest('[data-v2-prof-dd-root=\"1\"]') : null;
      if (!target) {
        profileCloseDropdownPanels();
      }
    });
    profileModalState.dropdownHandlersBound = true;
  }

  function profileDropdownThemeClasses() {
    var light = profileTheme() === 'light';
    return light ? {
      button: 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition duration-200 ring-sky-300 focus:ring-2 hover:bg-slate-50 cursor-pointer inline-flex items-center justify-between gap-2',
      panel: 'v2-prof-dd-panel absolute left-0 right-0 z-[130] mt-1 hidden overflow-hidden rounded-xl border border-slate-300 bg-white shadow-xl',
      item: 'w-full cursor-pointer px-3 py-2 text-left text-sm text-slate-900 transition duration-150 hover:bg-slate-100 inline-flex items-center justify-between gap-2',
      checkActive: 'opacity-100 text-emerald-600',
      checkHidden: 'opacity-0 text-emerald-600',
      chevron: 'text-slate-500',
      rootText: 'text-slate-900'
    } : {
      button: 'w-full rounded-2xl border border-white/15 bg-slate-950/60 px-4 py-2.5 text-sm text-slate-100 outline-none transition duration-200 ring-sky-300/40 focus:ring-2 hover:bg-slate-900/70 cursor-pointer inline-flex items-center justify-between gap-2',
      panel: 'v2-prof-dd-panel absolute left-0 right-0 z-[130] mt-1 hidden overflow-hidden rounded-xl border border-white/20 bg-slate-900 shadow-xl',
      item: 'w-full cursor-pointer px-3 py-2 text-left text-sm text-slate-100 transition duration-150 hover:bg-slate-800 inline-flex items-center justify-between gap-2',
      checkActive: 'opacity-100 text-emerald-300',
      checkHidden: 'opacity-0 text-emerald-300',
      chevron: 'text-slate-300',
      rootText: 'text-slate-100'
    };
  }

  function profileRenderCustomDropdown(selectId, hostId) {
    var select = profileById(selectId);
    var host = profileById(hostId);
    if (!select || !host) return;

    var options = [];
    for (var i = 0; i < select.options.length; i += 1) {
      var opt = select.options[i];
      if (!opt) continue;
      options.push({
        value: String(opt.value || ''),
        label: String(opt.textContent || opt.label || opt.value || '')
      });
    }
    if (!options.length) return;

    var activeValue = String(select.value || options[0].value);
    var active = options[0];
    for (var j = 0; j < options.length; j += 1) {
      if (options[j].value === activeValue) {
        active = options[j];
        break;
      }
    }
    var classes = profileDropdownThemeClasses();

    host.innerHTML = '';
    host.setAttribute('data-v2-prof-dd-root', '1');
    host.className = 'mt-1 relative ' + classes.rootText;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('data-v2-prof-dd-btn', '1');
    btn.setAttribute('aria-expanded', 'false');
    btn.className = classes.button;
    btn.innerHTML = [
      '<span class=\"min-w-0 truncate\">' + active.label.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>',
      '<i class=\"fa fa-chevron-down text-xs ' + classes.chevron + '\" aria-hidden=\"true\"></i>'
    ].join('');

    var panel = document.createElement('div');
    panel.className = classes.panel;

    for (var k = 0; k < options.length; k += 1) {
      (function (opt) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = classes.item;
        var selected = opt.value === active.value;
        item.innerHTML = [
          '<span class=\"min-w-0 truncate\">' + opt.label.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>',
          '<span class=\"' + (selected ? classes.checkActive : classes.checkHidden) + '\"><i class=\"fa fa-check\" aria-hidden=\"true\"></i></span>'
        ].join('');
        item.addEventListener('click', function () {
          if (select.value !== opt.value) {
            select.value = opt.value;
            try {
              select.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (_) {}
          }
          profileRenderCustomDropdown(selectId, hostId);
          profileCloseDropdownPanels();
        });
        panel.appendChild(item);
      })(options[k]);
    }

    btn.addEventListener('click', function (event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      var isOpen = !panel.classList.contains('hidden');
      profileCloseDropdownPanels();
      if (!isOpen) {
        panel.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
      }
    });

    host.appendChild(btn);
    host.appendChild(panel);
  }

  function profileRefreshCustomDropdowns() {
    profileRenderCustomDropdown('v2ProfileLangSelect', 'v2ProfileLangSelectHost');
    profileRenderCustomDropdown('v2ProfileThemeSelect', 'v2ProfileThemeSelectHost');
  }

  function profileShowStatus(message, tone) {
    var box = profileById('v2ProfileStatus');
    if (!box) return;
    var light = profileTheme() === 'light';
    var kind = String(tone || 'info');
    box.classList.remove('hidden');
    box.textContent = String(message || '');
    if (kind === 'error') {
      box.className = light
        ? 'mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700'
        : 'mt-4 rounded-xl border border-rose-400/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200';
    } else if (kind === 'success') {
      box.className = light
        ? 'mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700'
        : 'mt-4 rounded-xl border border-emerald-400/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-200';
    } else {
      box.className = light
        ? 'mt-4 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-700'
        : 'mt-4 rounded-xl border border-sky-400/40 bg-sky-500/10 px-3 py-2 text-sm text-sky-200';
    }
  }

  function profileHideStatus() {
    var box = profileById('v2ProfileStatus');
    if (!box) return;
    box.className = 'mt-4 hidden rounded-xl border px-3 py-2 text-sm';
    box.textContent = '';
  }

  function profileMapApiError(message) {
    var key = String(message || '');
    if (key === 'new_password_too_short') return trProfile('errPasswordShort');
    if (key === 'current_password_invalid') return trProfile('errCurrentInvalid');
    if (key === 'new_password_same_as_old') return trProfile('errPasswordSame');
    return key || trProfile('errProfileLoad');
  }

  function profileFetchJson(url, options) {
    return fetch(url, Object.assign({ credentials: 'include' }, options || {}))
      .then(function (resp) {
        return resp.json().catch(function () { return {}; }).then(function (data) {
          if (!resp.ok) {
            throw new Error(String((data && data.message) || ('HTTP ' + resp.status)));
          }
          return data || {};
        });
      });
  }

  function applyProfileModalI18n() {
    var title = profileById('v2ProfileTitle');
    var closeTop = profileById('v2ProfileCloseTopText');
    var closeBottom = profileById('v2ProfileCloseBottomText');
    var saveText = profileById('v2ProfileSaveText');
    var userLabel = profileById('v2ProfileUserLabel');
    var roleLabel = profileById('v2ProfileRoleLabel');
    var langLabel = profileById('v2ProfileLangLabel');
    var themeLabel = profileById('v2ProfileThemeLabel');
    var pwdSection = profileById('v2ProfilePasswordSection');
    var currLabel = profileById('v2ProfileCurrentPasswordLabel');
    var newLabel = profileById('v2ProfileNewPasswordLabel');
    var confLabel = profileById('v2ProfileConfirmPasswordLabel');
    var langSelect = profileById('v2ProfileLangSelect');
    var themeSelect = profileById('v2ProfileThemeSelect');
    var roleValue = profileById('v2ProfileRoleValue');

    if (title) title.textContent = trProfile('title');
    if (closeTop) closeTop.textContent = trProfile('close');
    if (closeBottom) closeBottom.textContent = trProfile('close');
    if (saveText) saveText.textContent = profileModalState.saving ? trProfile('saving') : trProfile('save');
    if (userLabel) userLabel.textContent = trProfile('user');
    if (roleLabel) roleLabel.textContent = trProfile('role');
    if (langLabel) langLabel.textContent = trProfile('lang');
    if (themeLabel) themeLabel.textContent = trProfile('theme');
    if (pwdSection) pwdSection.textContent = trProfile('passwordSection');
    if (currLabel) currLabel.textContent = trProfile('currentPassword');
    if (newLabel) newLabel.textContent = trProfile('newPassword');
    if (confLabel) confLabel.textContent = trProfile('confirmPassword');
    if (langSelect) {
      var lru = langSelect.querySelector('option[value=\"ru\"]');
      var len = langSelect.querySelector('option[value=\"en\"]');
      if (lru) lru.textContent = trProfile('langRu');
      if (len) len.textContent = trProfile('langEn');
    }
    if (themeSelect) {
      var tdark = themeSelect.querySelector('option[value=\"dark\"]');
      var tlight = themeSelect.querySelector('option[value=\"light\"]');
      if (tdark) tdark.textContent = trProfile('themeDark');
      if (tlight) tlight.textContent = trProfile('themeLight');
    }
    if (roleValue && !roleValue.textContent.trim()) {
      roleValue.textContent = trProfile('unknown');
    }
    profileRefreshCustomDropdowns();
  }

  function applyProfileModalTheme() {
    var light = profileTheme() === 'light';
    var card = profileById('v2ProfileCard');
    var panel = profileById('v2ProfilePasswordPanel');
    var closeTop = profileById('v2ProfileCloseTop');
    var closeBottom = profileById('v2ProfileCloseBottom');
    var saveBtn = profileById('v2ProfileSaveBtn');
    var lbls = [
      'v2ProfileUserLabel',
      'v2ProfileRoleLabel',
      'v2ProfileLangLabel',
      'v2ProfileThemeLabel',
      'v2ProfileCurrentPasswordLabel',
      'v2ProfileNewPasswordLabel',
      'v2ProfileConfirmPasswordLabel'
    ];
    var fields = [
      'v2ProfileCurrentPassword',
      'v2ProfileNewPassword',
      'v2ProfileConfirmPassword'
    ];
    var valueBoxes = ['v2ProfileUserValue', 'v2ProfileRoleValue'];

    if (card) {
      card.className = light
        ? 'relative w-full max-w-xl rounded-2xl border border-slate-300 bg-white p-5 shadow-2xl'
        : 'relative w-full max-w-xl rounded-2xl border border-white/20 bg-slate-900 p-5 shadow-2xl';
    }
    if (panel) {
      panel.className = light
        ? 'mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3'
        : 'mt-4 rounded-2xl border border-white/15 bg-slate-950/40 p-3';
    }
    if (closeTop) {
      closeTop.className = light
        ? 'inline-flex items-center gap-2 rounded-xl border border-rose-300 bg-rose-500 px-3 py-1.5 text-xs font-semibold text-white transition duration-200 hover:bg-rose-600 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none'
        : 'inline-flex items-center gap-2 rounded-xl border border-rose-400/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 transition duration-200 hover:bg-rose-500/30 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none';
    }
    if (closeBottom) {
      closeBottom.className = light
        ? 'inline-flex items-center gap-2 rounded-xl border border-rose-300 bg-rose-500 px-3 py-1.5 text-xs font-semibold text-white transition duration-200 hover:bg-rose-600 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none'
        : 'inline-flex items-center gap-2 rounded-xl border border-rose-400/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 transition duration-200 hover:bg-rose-500/30 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none';
    }
    if (saveBtn) {
      saveBtn.className = light
        ? 'inline-flex items-center gap-2 rounded-xl border border-sky-300 bg-sky-500 px-4 py-1.5 text-xs font-semibold text-white transition duration-200 hover:bg-sky-600 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none disabled:opacity-60 disabled:cursor-not-allowed'
        : 'inline-flex items-center gap-2 rounded-xl border border-sky-400/40 bg-sky-500/20 px-4 py-1.5 text-xs font-semibold text-sky-100 transition duration-200 hover:bg-sky-500/30 hover:-translate-y-0.5 active:translate-y-0 cursor-pointer focus:outline-none disabled:opacity-60 disabled:cursor-not-allowed';
    }

    for (var i = 0; i < lbls.length; i += 1) {
      var lbl = profileById(lbls[i]);
      if (!lbl) continue;
      lbl.className = light
        ? 'mb-1 block text-xs font-semibold text-slate-600'
        : 'mb-1 block text-xs font-semibold text-slate-300';
    }

    for (var j = 0; j < fields.length; j += 1) {
      var f = profileById(fields[j]);
      if (!f) continue;
      f.className = light
        ? 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 outline-none transition ring-sky-300 focus:ring-2'
        : 'w-full rounded-2xl border border-white/15 bg-slate-950/60 px-4 py-2.5 text-sm text-slate-100 outline-none transition ring-sky-300/40 focus:ring-2';
    }

    for (var k = 0; k < valueBoxes.length; k += 1) {
      var v = profileById(valueBoxes[k]);
      if (!v) continue;
      v.className = light
        ? 'rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900'
        : 'rounded-xl border border-white/15 bg-slate-950/60 px-3 py-2 text-sm text-slate-100';
    }
    profileRefreshCustomDropdowns();
  }

  function ensureProfileModal() {
    if (profileModalState.initialized) return;
    if (!document.body) return;

    var root = document.createElement('div');
    root.id = 'v2ProfileModal';
    root.className = 'fixed inset-0 z-[120] hidden items-center justify-center p-4';
    root.innerHTML = [
      '<div id="v2ProfileBackdrop" class="absolute inset-0 bg-black/55 backdrop-blur-[1px]"></div>',
      '<div id="v2ProfileCard" class="relative w-full max-w-xl rounded-2xl border p-5 shadow-2xl">',
      '  <div class="mb-4 flex items-center justify-between gap-3">',
      '    <h2 id="v2ProfileTitle" class="text-lg font-black tracking-tight">Настройки профиля</h2>',
      '    <button id="v2ProfileCloseTop" type="button" class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-semibold transition duration-200 cursor-pointer focus:outline-none">',
      '      <i class="fa fa-times" aria-hidden="true"></i>',
      '      <span id="v2ProfileCloseTopText">Закрыть</span>',
      '    </button>',
      '  </div>',
      '  <div class="grid gap-3 sm:grid-cols-2">',
      '    <div>',
      '      <span id="v2ProfileUserLabel" class="mb-1 block text-xs font-semibold">Пользователь</span>',
      '      <div id="v2ProfileUserValue" class="rounded-xl border px-3 py-2 text-sm font-semibold">-</div>',
      '    </div>',
      '    <div>',
      '      <span id="v2ProfileRoleLabel" class="mb-1 block text-xs font-semibold">Роль</span>',
      '      <div id="v2ProfileRoleValue" class="rounded-xl border px-3 py-2 text-sm">-</div>',
      '    </div>',
      '  </div>',
      '  <div class="mt-3 grid gap-3 sm:grid-cols-2">',
      '    <label>',
      '      <span id="v2ProfileLangLabel" class="mb-1 block text-xs font-semibold">Язык</span>',
      '      <select id="v2ProfileLangSelect" class="hidden">',
      '        <option value="ru">Русский</option>',
      '        <option value="en">English</option>',
      '      </select>',
      '      <div id="v2ProfileLangSelectHost" class="mt-1"></div>',
      '    </label>',
      '    <label>',
      '      <span id="v2ProfileThemeLabel" class="mb-1 block text-xs font-semibold">Тема</span>',
      '      <select id="v2ProfileThemeSelect" class="hidden">',
      '        <option value="dark">Темная</option>',
      '        <option value="light">Светлая</option>',
      '      </select>',
      '      <div id="v2ProfileThemeSelectHost" class="mt-1"></div>',
      '    </label>',
      '  </div>',
      '  <div id="v2ProfilePasswordPanel" class="mt-4 rounded-2xl border p-3">',
      '    <p id="v2ProfilePasswordSection" class="mb-3 text-sm font-semibold">Смена пароля</p>',
      '    <div class="grid gap-3 sm:grid-cols-3">',
      '      <label>',
      '        <span id="v2ProfileCurrentPasswordLabel" class="mb-1 block text-xs font-semibold">Текущий пароль</span>',
      '        <input id="v2ProfileCurrentPassword" type="password" autocomplete="current-password">',
      '      </label>',
      '      <label>',
      '        <span id="v2ProfileNewPasswordLabel" class="mb-1 block text-xs font-semibold">Новый пароль</span>',
      '        <input id="v2ProfileNewPassword" type="password" autocomplete="new-password">',
      '      </label>',
      '      <label>',
      '        <span id="v2ProfileConfirmPasswordLabel" class="mb-1 block text-xs font-semibold">Подтвердите пароль</span>',
      '        <input id="v2ProfileConfirmPassword" type="password" autocomplete="new-password">',
      '      </label>',
      '    </div>',
      '  </div>',
      '  <div id="v2ProfileStatus" class="mt-4 hidden rounded-xl border px-3 py-2 text-sm"></div>',
      '  <div class="mt-5 flex items-center justify-end gap-2">',
      '    <button id="v2ProfileCloseBottom" type="button" class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-semibold transition duration-200 cursor-pointer focus:outline-none">',
      '      <i class="fa fa-times" aria-hidden="true"></i>',
      '      <span id="v2ProfileCloseBottomText">Закрыть</span>',
      '    </button>',
      '    <button id="v2ProfileSaveBtn" type="button" class="inline-flex items-center gap-2 rounded-xl border px-4 py-1.5 text-xs font-semibold transition duration-200 cursor-pointer focus:outline-none">',
      '      <i class="fa fa-save" aria-hidden="true"></i>',
      '      <span id="v2ProfileSaveText">Сохранить</span>',
      '    </button>',
      '  </div>',
      '</div>'
    ].join('');
    document.body.appendChild(root);
    profileBindDropdownGlobalHandlers();

    var closeTop = profileById('v2ProfileCloseTop');
    var closeBottom = profileById('v2ProfileCloseBottom');
    var backdrop = profileById('v2ProfileBackdrop');
    var saveBtn = profileById('v2ProfileSaveBtn');

    function closeModal() {
      var modal = profileById('v2ProfileModal');
      if (!modal) return;
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      profileModalState.saving = false;
      profileModalState.auth = null;
      profileHideStatus();
    }

    if (closeTop) closeTop.addEventListener('click', closeModal);
    if (closeBottom) closeBottom.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        var modal = profileById('v2ProfileModal');
        if (modal && !modal.classList.contains('hidden')) closeModal();
      }
    });

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        if (profileModalState.saving) return;

        var langSelect = profileById('v2ProfileLangSelect');
        var themeSelect = profileById('v2ProfileThemeSelect');
        var currentInput = profileById('v2ProfileCurrentPassword');
        var newInput = profileById('v2ProfileNewPassword');
        var confirmInput = profileById('v2ProfileConfirmPassword');
        if (!langSelect || !themeSelect || !currentInput || !newInput || !confirmInput) return;

        var selectedLang = String(langSelect.value || 'ru').toLowerCase() === 'en' ? 'en' : 'ru';
        var selectedTheme = String(themeSelect.value || 'light').toLowerCase() === 'dark' ? 'dark' : 'light';
        var currentPassword = String(currentInput.value || '');
        var newPassword = String(newInput.value || '');
        var confirmPassword = String(confirmInput.value || '');
        var hasPasswordPayload = (currentPassword !== '' || newPassword !== '' || confirmPassword !== '');
        var auth = profileModalState.auth || {};
        var currentLang = String((auth.lang || localStorage.getItem('eve_v2_lang') || 'ru')).toLowerCase() === 'en' ? 'en' : 'ru';
        var currentTheme = String((auth.theme || localStorage.getItem('eve_v2_theme') || 'light')).toLowerCase() === 'dark' ? 'dark' : 'light';
        var prefsChanged = selectedLang !== currentLang || selectedTheme !== currentTheme;

        profileHideStatus();

        if (!prefsChanged && !hasPasswordPayload) {
          profileShowStatus(trProfile('errNoChanges'), 'info');
          return;
        }
        if (hasPasswordPayload) {
          if (currentPassword === '' || newPassword === '' || confirmPassword === '') {
            profileShowStatus(trProfile('errPasswordFields'), 'error');
            return;
          }
          if (newPassword !== confirmPassword) {
            profileShowStatus(trProfile('errPasswordMismatch'), 'error');
            return;
          }
          if (newPassword.length < 8) {
            profileShowStatus(trProfile('errPasswordShort'), 'error');
            return;
          }
        }

        profileModalState.saving = true;
        applyProfileModalI18n();
        applyProfileModalTheme();
        saveBtn.disabled = true;

        var flow = Promise.resolve();
        if (prefsChanged) {
          flow = flow.then(function () {
            return profileFetchJson('/api/preferences', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ lang: selectedLang, theme: selectedTheme })
            });
          });
        }
        if (hasPasswordPayload) {
          flow = flow.then(function () {
            return profileFetchJson('/api/preferences/password', {
              method: 'PUT',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                current_password: currentPassword,
                new_password: newPassword
              })
            });
          });
        }

        flow.then(function () {
          localStorage.setItem('eve_v2_lang', selectedLang);
          localStorage.setItem('eve_v2_theme', selectedTheme);
          if (hasPasswordPayload) {
            currentInput.value = '';
            newInput.value = '';
            confirmInput.value = '';
          }
          if (prefsChanged) {
            profileShowStatus(trProfile('okSavedReload'), 'success');
            setTimeout(function () { window.location.reload(); }, 450);
            return;
          }
          profileShowStatus(trProfile('okSaved'), 'success');
        }).catch(function (error) {
          profileShowStatus(profileMapApiError(error && error.message ? error.message : ''), 'error');
        }).finally(function () {
          profileModalState.saving = false;
          if (saveBtn) saveBtn.disabled = false;
          applyProfileModalI18n();
          applyProfileModalTheme();
        });
      });
    }

    profileModalState.initialized = true;
    window.openV2ProfileSettingsModal = function () {
      var modal = profileById('v2ProfileModal');
      if (!modal) return;

      profileModalState.saving = false;
      profileModalState.auth = null;
      profileHideStatus();

      var currentInput = profileById('v2ProfileCurrentPassword');
      var newInput = profileById('v2ProfileNewPassword');
      var confirmInput = profileById('v2ProfileConfirmPassword');
      if (currentInput) currentInput.value = '';
      if (newInput) newInput.value = '';
      if (confirmInput) confirmInput.value = '';

      var userValue = profileById('v2ProfileUserValue');
      var roleValue = profileById('v2ProfileRoleValue');
      if (userValue) userValue.textContent = '-';
      if (roleValue) roleValue.textContent = '-';

      var langSelect = profileById('v2ProfileLangSelect');
      var themeSelect = profileById('v2ProfileThemeSelect');
      if (langSelect) langSelect.value = profileLang();
      if (themeSelect) themeSelect.value = profileTheme();

      applyProfileModalI18n();
      applyProfileModalTheme();
      modal.classList.remove('hidden');
      modal.classList.add('flex');

      profileFetchJson('/api/auth').then(function (payload) {
        var data = payload && payload.data ? payload.data : {};
        profileModalState.auth = data || {};
        if (userValue) userValue.textContent = String(data.username || '-');
        if (roleValue) roleValue.textContent = String(data.role || trProfile('unknown'));
        if (langSelect) {
          var lang = String(data.lang || profileLang()).toLowerCase() === 'en' ? 'en' : 'ru';
          langSelect.value = lang;
        }
        if (themeSelect) {
          var theme = String(data.theme || profileTheme()).toLowerCase() === 'dark' ? 'dark' : 'light';
          themeSelect.value = theme;
        }
        profileRefreshCustomDropdowns();
      }).catch(function (error) {
        profileShowStatus(
          (error && error.message) ? String(error.message) : trProfile('errProfileLoad'),
          'error'
        );
      });
    };
  }

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
      '      <div class="flex items-center gap-2">',
      '        <button id="profileBtn" type="button" class="rounded-2xl border px-4 py-2 text-left transition cursor-pointer">',
      '          <p id="profileName" class="text-sm font-semibold">user</p>',
      '          <p id="profileRole" class="text-xs">Роль: admin</p>',
      '        </button>',
      '        <button id="logoutBtn" class="rounded-xl border px-4 py-2 text-sm font-semibold shadow-sm transition">Выход</button>',
      '      </div>',
      '    </div>',
      '  </div>',
      '</section>'
    ].join('');
  }

  window.renderV2Header = renderV2Header;

  document.addEventListener('click', function (event) {
    var target = event && event.target && event.target.closest ? event.target.closest('#profileBtn') : null;
    if (!target) return;
    ensureProfileModal();
    if (typeof window.openV2ProfileSettingsModal === 'function') {
      event.preventDefault();
      event.stopPropagation();
      if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
      window.openV2ProfileSettingsModal();
    }
  }, true);
})();
