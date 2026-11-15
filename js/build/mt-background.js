// modules/machine-translation/lib/wasm-worker/js/host.js
function isSupported() {
  return "undefined" !== typeof Worker && "object" === typeof WebAssembly && !!(self.crypto && self.crypto.subtle && self.crypto.randomUUID);
}
async function renewNonce(config) {
  try {
    const response = await fetch(config.nonceUrl, { credentials: "same-origin" });
    const nonce = (await response.text()).trim();
    if (response.ok && /^[a-f0-9]{10}$/.test(nonce)) {
      config.nonce = nonce;
      return true;
    }
  } catch (e) {
  }
  return false;
}
function getBrowserId() {
  try {
    const id = localStorage.getItem("ww-worker-id") || crypto.randomUUID();
    localStorage.setItem("ww-worker-id", id);
    return id;
  } catch (error) {
    return crypto.randomUUID();
  }
}
function releaseUnits(config, held) {
  const url = `${config.restUrl}release${config.restUrl.includes("?") ? "&" : "?"}_wpnonce=${encodeURIComponent(config.nonce || "")}`;
  const body = JSON.stringify({ lease: held.lease, units: held.units });
  try {
    if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: "application/json" }))) {
      return;
    }
  } catch (e) {
  }
  fetch(url, { method: "POST", keepalive: true, credentials: "same-origin", headers: { "Content-Type": "application/json", "X-WP-Nonce": config.nonce || "" }, body }).catch(() => {
  });
}
function newWorker(config, index) {
  return new Worker(config.workerUrl, { type: "classic" === config.workerType ? "classic" : "module", name: `${config.id}-${index}` });
}
function startWorkers(options) {
  const config = Object.assign(options, { concurrency: 1, stopWhenIdle: 0, onStatus: () => {
  }, ...options });
  const lockName = `ww:${config.id}`;
  let workers = [];
  let release = null;
  const idle = /* @__PURE__ */ new Map();
  const held = /* @__PURE__ */ new Map();
  const browserId = getBrowserId();
  const giveBack = (worker) => {
    if (held.has(worker)) {
      releaseUnits(config, held.get(worker));
      held.delete(worker);
    }
  };
  if ("function" === typeof addEventListener) {
    addEventListener("pagehide", () => [...held.keys()].forEach(giveBack));
  }
  const spawn = (index) => {
    const worker = newWorker(config, index);
    worker.onmessage = async (event) => {
      const message = event.data || {};
      if ("lease" === message.type) {
        if (message.lease && Array.isArray(message.units) && message.units.length) {
          held.set(worker, { lease: message.lease, units: message.units });
        } else {
          held.delete(worker);
        }
        return;
      }
      if ("need-nonce" === message.type) {
        await renewNonce(config);
        worker.postMessage({ type: "nonce", nonce: config.nonce });
        return;
      }
      if ("status" !== message.type) {
        return;
      }
      config.onStatus({ ...message, worker: index });
      idle.set(index, "idle" === message.state ? message.idle : 0);
      if (config.stopWhenIdle && workers.length && [...idle.values()].every((n) => n >= config.stopWhenIdle)) {
        controller.stop();
      }
    };
    worker.onerror = (event) => config.onStatus({ state: "error", worker: index, message: event.message || "Worker error" });
    worker.postMessage({ type: "start", restUrl: config.restUrl, nonce: config.nonce, options: config.workerOptions || {}, workerId: `${browserId.slice(0, 36)}-${index}` });
    return worker;
  };
  const run = () => {
    idle.clear();
    workers = Array.from({ length: Math.max(1, config.concurrency) }, (_, i) => spawn(i));
  };
  const controller = {
    /**
     * Starts working in this tab, once no other tab of the browser does.
     *
     * @param {boolean} wait Wait for the other tab (true) or give up right away (false).
     * @return {Promise<boolean>} False when another tab works and `wait` is false.
     */
    start(wait = false) {
      if (workers.length || release) {
        return Promise.resolve(true);
      }
      if (!isSupported()) {
        config.onStatus({ state: "unsupported" });
        return Promise.resolve(false);
      }
      if (!navigator.locks) {
        run();
        return Promise.resolve(true);
      }
      return new Promise((resolve) => {
        let acquired = false;
        navigator.locks.request(lockName, wait ? {} : { ifAvailable: true }, (lock) => {
          if (!lock) {
            config.onStatus({ state: "other-tab" });
            resolve(false);
            return null;
          }
          acquired = true;
          run();
          resolve(true);
          return new Promise((done) => {
            release = done;
          });
        });
        if (wait) {
          navigator.locks.query().then((locks) => {
            if (!acquired && (locks.held || []).some((lock) => lockName === lock.name)) {
              config.onStatus({ state: "other-tab" });
            }
          }).catch(() => {
          });
        }
      });
    },
    /**
     * Stops the workers. Each submits what it finished and gives the rest of its batch back to the queue;
     * a worker that does not answer within 2 s is ended and its units are given back from here.
     */
    stop() {
      for (const worker of workers) {
        worker.postMessage({ type: "stop" });
        setTimeout(() => {
          worker.terminate();
          giveBack(worker);
        }, 2e3);
      }
      workers = [];
      if (release) {
        release();
        release = null;
      }
      config.onStatus({ state: "stopped" });
    },
    isRunning() {
      return workers.length > 0;
    }
  };
  return controller;
}
function watchQueue(options) {
  const config = Object.assign(options, { interval: 6e4, stopWhenIdle: 3, ...options });
  const workers = startWorkers(config);
  let timer = null;
  const check = async () => {
    if (workers.isRunning()) {
      return;
    }
    try {
      const response = await fetch(config.restUrl + "waiting", { credentials: "same-origin", headers: { "X-WP-Nonce": config.nonce } });
      if (403 === response.status) {
        await renewNonce(config);
        return;
      }
      const data = await response.json();
      if (data && data.waiting > 0) {
        await workers.start(false);
      }
    } catch (e) {
    }
  };
  check();
  timer = setInterval(check, config.interval);
  return {
    workers,
    stop() {
      clearInterval(timer);
      workers.stop();
    }
  };
}

// modules/machine-translation/js/lib/host.js
function sprintf(format, ...args) {
  let i = 0;
  return String(format).replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => String(position ? args[position - 1] : args[i++]));
}
function describeStatus(status, i18n) {
  switch (status.state) {
    case "starting":
      return i18n.starting;
    case "loading":
      return sprintf(i18n.loading, status.direction || "");
    case "downloading": {
      const percent = status.total ? Math.min(100, Math.round(status.loaded / status.total * 100)) : 0;
      return sprintf(i18n.downloading, `${percent}%`);
    }
    case "translating":
      return sprintf(i18n.translating, status.wps || 0);
    case "idle":
      return i18n.idle;
    case "error":
      return sprintf(i18n.error, status.message || "");
    case "other-tab":
      return i18n.otherTab;
    case "unsupported":
      return i18n.unsupported;
    default:
      return i18n.stopped;
  }
}
function normalizer(onStatus) {
  let speed = null;
  return (status) => {
    switch (status.state) {
      case "translating":
        speed = status;
        onStatus(status);
        return;
      case "preparing":
        if (!speed) {
          onStatus({ ...status, state: "starting" });
        }
        return;
      case "working":
      case "progress":
      case "completed":
      case "unit-failed":
        onStatus({ ...speed || { wps: 0 }, state: "translating" });
        return;
      case "lease-lost":
        onStatus({ state: "idle", idle: 0 });
        return;
      default:
        onStatus(status);
    }
  };
}
function watch(config, onStatus) {
  return watchQueue({ ...config.kit, onStatus: normalizer(onStatus) });
}
function updateAdminBar(status, i18n) {
  const node = document.getElementById("wp-admin-bar-strope-mt");
  if (!node) {
    return;
  }
  const label = node.querySelector(".ab-label");
  const busy = ["starting", "loading", "downloading", "translating"].includes(status.state);
  node.classList.toggle("hidden", !busy);
  if (label && busy) {
    label.textContent = "translating" === status.state ? sprintf(i18n.adminBar, `${status.wps || 0} w/s`) : describeStatus(status, i18n);
  }
  node.title = describeStatus(status, i18n);
}

// modules/machine-translation/js/mt-background.js
(() => {
  const config = window.stropeMt;
  if (!config || !config.kit) {
    return;
  }
  watch(config, (status) => updateAdminBar(status, config.i18n));
})();
