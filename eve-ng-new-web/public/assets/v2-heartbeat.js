(function () {
  function startV2Heartbeat(options) {
    var cfg = options || {};
    var pingUrl = cfg.pingUrl || '/api/auth/ping';
    var intervalMs = Number(cfg.intervalMs || 30000);
    var onUnauthorized = typeof cfg.onUnauthorized === 'function' ? cfg.onUnauthorized : function () {};
    var onError = typeof cfg.onError === 'function' ? cfg.onError : function () {};
    var timer = null;
    var stopped = false;
    var redirected = false;

    async function tick() {
      if (stopped) return;
      try {
        var resp = await fetch(pingUrl, { method: 'POST', credentials: 'include' });
        if (resp.status === 401) {
          if (!redirected) {
            redirected = true;
            onUnauthorized();
          }
          return;
        }
        if (!resp.ok) {
          onError(new Error('Heartbeat failed: HTTP ' + resp.status));
        }
      } catch (e) {
        onError(e);
      }
    }

    timer = setInterval(tick, intervalMs);
    tick();

    function stop() {
      stopped = true;
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }

    window.addEventListener('beforeunload', stop, { once: true });
    return stop;
  }

  window.startV2Heartbeat = startV2Heartbeat;
})();
