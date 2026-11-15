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

// modules/machine-translation/js/lib/host.js
function sprintf(format, ...args) {
  let i = 0;
  return String(format).replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => String(position ? args[position - 1] : args[i++]));
}
async function api(config, path, body) {
  const response = await fetch(config.api + path, {
    method: body ? "POST" : "GET",
    credentials: "same-origin",
    headers: body ? { "X-WP-Nonce": config.nonce, "Content-Type": "application/json" } : { "X-WP-Nonce": config.nonce },
    body: body ? JSON.stringify(body) : void 0
  });
  const data = await response.json().catch(() => null);
  if (403 === response.status && data && "rest_cookie_invalid_nonce" === data.code && await renewNonce2(config)) {
    return api(config, path, body);
  }
  if (!response.ok) {
    throw new Error(data && data.message || `HTTP ${response.status}`);
  }
  return data;
}
async function renewNonce2(config) {
  try {
    const response = await fetch(config.nonceUrl, { credentials: "same-origin" });
    const nonce = (await response.text()).trim();
    if (response.ok && /^[a-f0-9]{10}$/.test(nonce) && nonce !== config.nonce) {
      config.nonce = nonce;
      if (config.kit) {
        config.kit.nonce = nonce;
      }
      return true;
    }
  } catch (e) {
  }
  return false;
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
function createHost(config, onStatus, options = {}) {
  return startWorkers({ ...config.kit, ...options, onStatus: normalizer(onStatus) });
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

// modules/machine-translation/js/mt-admin.js
(() => {
  const config = window.stropeMt;
  if (!config) {
    return;
  }
  const i18n = config.i18n;
  const $ = (selector, root = document) => root.querySelector(selector);
  const el = (tag, attributes = {}, children = []) => {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attributes)) {
      if ("text" === key) {
        node.textContent = value;
      } else if (key.startsWith("on")) {
        node.addEventListener(key.slice(2), value);
      } else if ("className" === key) {
        node.className = value;
      } else if (null !== value && void 0 !== value && false !== value) {
        node.setAttribute(key, true === value ? "" : value);
      }
    }
    for (const child of children) {
      node.append(child);
    }
    return node;
  };
  const statusLine = $("#strope-mt-worker-status");
  const toggle = $("#strope-mt-worker-toggle");
  const background = $("#strope-mt-background");
  let lastStatus = { state: "stopped" };
  const host = createHost(config, (status) => {
    lastStatus = status;
    if (statusLine) {
      statusLine.textContent = describeStatus(status, i18n);
      statusLine.dataset.state = status.state;
    }
    updateAdminBar(status, i18n);
    renderToggle();
    if ("translating" === status.state) {
      scheduleRefresh(1500);
    }
  });
  const renderToggle = () => {
    if (!toggle) {
      return;
    }
    const running = host.isRunning() || "other-tab" === lastStatus.state;
    toggle.textContent = running ? i18n.stop : i18n.start;
    toggle.className = running ? "button" : "button button-primary";
  };
  if (toggle) {
    toggle.addEventListener("click", () => {
      if (host.isRunning() || "other-tab" === lastStatus.state) {
        host.stop();
      } else {
        host.start(true);
      }
      renderToggle();
    });
  }
  if (background) {
    background.addEventListener("change", async () => {
      config.background = background.checked;
      try {
        await api(config, "mt/preferences", { background: background.checked });
      } catch (e) {
        background.checked = !background.checked;
      }
    });
  }
  statusLine.textContent = describeStatus(lastStatus, i18n);
  renderToggle();
  const runsBox = $("#strope-mt-runs");
  const openDetails = /* @__PURE__ */ new Set();
  let refreshTimer = null;
  let refreshing = false;
  const scheduleRefresh = (delay) => {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(refresh, delay);
  };
  const refresh = async () => {
    if (refreshing) {
      return;
    }
    refreshing = true;
    try {
      const data = await api(config, "mt/status");
      renderRuns(data.runs || []);
      renderModels(data.directions || {});
      if (config.background && data.waiting > 0 && !host.isRunning() && "other-tab" !== lastStatus.state) {
        host.start(false);
      }
      const active = (data.runs || []).some((run) => "active" === run.status);
      scheduleRefresh(active ? document.hidden ? 2e4 : 4e3 : 3e4);
    } catch (error) {
      scheduleRefresh(3e4);
    } finally {
      refreshing = false;
    }
  };
  const runAction = async (run, action) => {
    try {
      await api(config, `mt/runs/${run.id}`, { action });
    } catch (error) {
      window.alert(error.message);
    }
    refresh();
    if (("resume" === action || "retry" === action) && !host.isRunning()) {
      host.start(true);
    }
  };
  const renderRuns = (runs) => {
    if (!runsBox) {
      return;
    }
    if (!runs.length) {
      runsBox.replaceChildren(el("p", { text: i18n.noRuns }));
      return;
    }
    const rows = runs.map((run) => {
      const percent = Math.round((run.progress || 0) * 100);
      const finished = run.jobs.done + run.jobs.skipped + run.jobs.failed;
      const notes = [];
      if (run.jobs.skipped) {
        notes.push(sprintf(i18n.skippedCount, run.jobs.skipped));
      }
      if (run.jobs.failed) {
        notes.push(sprintf(i18n.failedCount, run.jobs.failed));
      }
      const actions = [];
      if (run.can_manage) {
        if ("active" === run.status) {
          actions.push(["pause", i18n.pause]);
        }
        if ("paused" === run.status) {
          actions.push(["resume", i18n.resume]);
        }
        if ("active" === run.status || "paused" === run.status) {
          actions.push(["cancel", i18n.cancel]);
        }
        if (run.jobs.failed) {
          actions.push(["retry", i18n.retry]);
        }
      }
      const details = el("div", { className: "strope-mt-details" });
      const detailsButton = el("button", {
        type: "button",
        className: "button-link",
        "aria-expanded": openDetails.has(run.id) ? "true" : "false",
        text: i18n.details,
        onclick: () => {
          if (openDetails.has(run.id)) {
            openDetails.delete(run.id);
            details.replaceChildren();
            detailsButton.setAttribute("aria-expanded", "false");
          } else {
            openDetails.add(run.id);
            detailsButton.setAttribute("aria-expanded", "true");
            loadDetails(run, details);
          }
        }
      });
      if (openDetails.has(run.id)) {
        loadDetails(run, details);
      }
      return el("tr", {}, [
        el("td", {}, [
          el("strong", { text: run.languages.join(", ") }),
          el("br"),
          el("span", { className: "description", text: `${new Date(run.created).toLocaleString()} · ${run.user}` })
        ]),
        el("td", { className: "strope-mt-progress-cell" }, [
          el("div", { className: "strope-mt-progress", role: "progressbar", "aria-valuenow": String(percent), "aria-valuemin": "0", "aria-valuemax": "100" }, [
            el("span", { style: `width:${percent}%` })
          ]),
          el("span", { className: "description", text: `${sprintf(i18n.posts, finished, run.jobs.total)}${notes.length ? " · " + notes.join(", ") : ""}` })
        ]),
        el("td", { text: i18n.statuses[run.status] || run.status }),
        el("td", { className: "strope-mt-actions" }, [
          ...actions.map(([action, label]) => el("button", { type: "button", className: "button button-small", text: label, onclick: () => runAction(run, action) })),
          detailsButton,
          details
        ])
      ]);
    });
    runsBox.replaceChildren(el("table", { className: "widefat striped strope-mt-runs" }, [el("tbody", {}, rows)]));
  };
  const loadDetails = async (run, box) => {
    try {
      const data = await api(config, `mt/runs/${run.id}`);
      box.replaceChildren(
        el("ul", {}, (data.jobs || []).map((job) => el("li", {}, [
          `${job.title} → ${job.language}: ${i18n.statuses[job.status] || job.status}`,
          job.message ? el("span", { className: "description", text: ` (${job.message})` }) : "",
          job.edit ? " " : "",
          job.edit ? el("a", { href: job.edit, text: i18n.edit }) : ""
        ])))
      );
    } catch (error) {
      box.replaceChildren(el("p", { text: error.message }));
    }
  };
  const busyModels = /* @__PURE__ */ new Set();
  const renderModels = (directions) => {
    document.querySelectorAll(".strope-mt-models tr[data-direction]").forEach((row) => {
      const direction = row.dataset.direction;
      const info = directions[direction];
      if (info) {
        row.dataset.installed = info.installed ? "1" : "0";
        row.dataset.outdated = info.outdated ? "1" : "0";
        row.dataset.available = info.available ? "1" : "0";
      }
      if (!busyModels.has(direction)) {
        renderModelActions(row);
      }
    });
  };
  const renderModelActions = (row) => {
    const cell = $(".strope-mt-model-actions", row);
    const direction = row.dataset.direction;
    const buttons = [];
    if ("1" === row.dataset.installed) {
      if ("1" === row.dataset.outdated) {
        buttons.push(el("button", { type: "button", className: "button button-small", text: i18n.update, onclick: () => installModel(row) }));
      } else {
        buttons.push(el("span", { className: "strope-mt-installed", text: i18n.installed }));
      }
      buttons.push(el("button", {
        type: "button",
        className: "button-link button-link-delete",
        text: i18n.remove,
        onclick: async () => {
          if (!window.confirm(i18n.confirmRemove)) {
            return;
          }
          await api(config, "mt/directions", { direction, action: "remove" });
          row.dataset.installed = "0";
          renderModelActions(row);
        }
      }));
    } else if ("1" === row.dataset.available) {
      buttons.push(el("button", { type: "button", className: "button button-small", text: i18n.install, onclick: () => installModel(row) }));
    } else {
      buttons.push(el("span", { className: "description", text: i18n.notAvailable }));
    }
    cell.replaceChildren(...buttons);
  };
  const installModel = async (row) => {
    const direction = row.dataset.direction;
    const cell = $(".strope-mt-model-actions", row);
    const bar = el("span");
    const label = el("span", { className: "description", text: "0%" });
    busyModels.add(direction);
    cell.replaceChildren(el("div", { className: "strope-mt-progress" }, [bar]), label);
    try {
      for (; ; ) {
        const result = await api(config, "mt/directions", { direction, action: "install" });
        const percent = result.total ? Math.round(result.done_bytes / result.total * 100) : 100;
        bar.style.width = `${percent}%`;
        label.textContent = `${percent}%`;
        if (result.complete) {
          break;
        }
      }
      row.dataset.installed = "1";
      row.dataset.outdated = "0";
    } catch (error) {
      window.alert(error.message);
    } finally {
      busyModels.delete(direction);
      renderModelActions(row);
    }
  };
  document.querySelectorAll(".strope-mt-models tr[data-direction]").forEach(renderModelActions);
  const glossaryAdd = $(".strope-mt-glossary-add");
  if (glossaryAdd) {
    const tbody = $(".strope-mt-glossary tbody");
    glossaryAdd.hidden = false;
    glossaryAdd.addEventListener("click", () => {
      const row = tbody.lastElementChild.cloneNode(true);
      const index = tbody.children.length;
      row.querySelectorAll("input").forEach((input) => {
        input.value = "";
        input.name = input.name.replace(/^glossary\[\d+\]/, `glossary[${index}]`);
      });
      tbody.append(row);
      row.querySelector("input").focus();
    });
  }
  refresh();
})();
