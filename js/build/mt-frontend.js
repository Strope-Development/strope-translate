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
function createDirectWorker(options) {
  const config = Object.assign(options, { onStatus: () => {
  }, ...options });
  const pending = /* @__PURE__ */ new Map();
  let worker = null;
  let sequence = 0;
  const fail = (error) => {
    pending.forEach((request) => request.reject(error));
    pending.clear();
  };
  const ensure = () => {
    if (worker) {
      return worker;
    }
    worker = newWorker(config, "direct");
    worker.onmessage = async (event) => {
      const message = event.data || {};
      if ("need-nonce" === message.type) {
        await renewNonce(config);
        worker.postMessage({ type: "nonce", nonce: config.nonce });
      } else if ("run-result" === message.type) {
        const request = pending.get(message.id);
        if (request) {
          pending.delete(message.id);
          if (message.error) {
            request.reject(new Error(message.error));
          } else {
            request.resolve(message.outcomes);
          }
        }
      } else if ("status" === message.type) {
        config.onStatus(message);
      }
    };
    worker.onerror = (event) => {
      fail(new Error(event.message || "Worker error"));
      worker.terminate();
      worker = null;
    };
    worker.postMessage({ type: "configure", restUrl: config.restUrl, nonce: config.nonce, options: config.workerOptions || {}, workerId: `${getBrowserId().slice(0, 36)}-direct` });
    return worker;
  };
  return {
    /**
     * Runs inputs of a task type.
     *
     * @param {string} type   Task type.
     * @param {Array}  inputs Inputs, as the runner gets them from `Task::payload()`.
     * @return {Promise<Object[]>} One outcome per input: `{ result }` or `{ error }`.
     */
    run(type, inputs) {
      if (!isSupported()) {
        return Promise.reject(new Error("This browser cannot run the workers."));
      }
      const id = ++sequence;
      const target = ensure();
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        target.postMessage({ type: "run", id, task: type, inputs });
      });
    },
    /**
     * Ends the worker (it starts again at the next run).
     */
    close() {
      if (worker) {
        worker.terminate();
        worker = null;
      }
      fail(new Error("The worker was closed."));
    }
  };
}

// modules/machine-translation/js/lib/host.js
function isSupported2() {
  return isSupported() && "function" === typeof WebAssembly.instantiate;
}
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
function createTranslator(config, onStatus) {
  const worker = createDirectWorker({ ...config.kit, onStatus: onStatus ? normalizer(onStatus) : () => {
  } });
  return {
    /**
     * Translates a batch.
     *
     * @param {string}   route Route, e.g. `nl-en`.
     * @param {Object[]} items Items with `id`, `text`, `html`.
     * @return {Promise<Object[]>} Results with `id` and `text` or `error`.
     */
    async translate(route, items) {
      const outcomes = await worker.run("translate-post", items.map((item) => ({ route, text: item.text, html: !!item.html })));
      return items.map((item, i) => {
        const outcome = outcomes[i] || {};
        return "result" in outcome ? { id: item.id, text: String(outcome.result) } : { id: item.id, error: outcome.error || "engine error" };
      });
    }
  };
}

// modules/machine-translation/js/lib/texts.js
function routeOf(from, to) {
  if (!from || !to || from === to) {
    return "";
  }
  return "en" === from || "en" === to ? `${from}-${to}` : `${from}-en-${to}`;
}
function isInstalled(route, directions) {
  const codes = route.match(/zh-Han[st]|[a-z]{2,3}/g) || [];
  for (let i = 0; i < codes.length - 1; i++) {
    if (!directions.includes(`${codes[i]}-${codes[i + 1]}`)) {
      return false;
    }
  }
  return codes.length > 1;
}
var FORMAT_CHAR = "(?:\\\\.|[dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcrU])";
var FORMAT = new RegExp(`^\\s*${FORMAT_CHAR}(?:[\\s:,./-]*${FORMAT_CHAR})*\\s*$`);
function isTranslatableText(text) {
  text = String(text || "");
  if (FORMAT.test(text) && !/[A-Za-z]{2}/.test(text.replace(/\\./g, ""))) {
    return false;
  }
  const words = text.replace(/%%[A-Za-z][A-Za-z0-9_]*%%/g, " ").replace(/%(?:\d+\$)?[-+0#']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/g, " ").replace(/<[^>]*>|\[[^\]]*\]|\{[^}]*\}/g, " ");
  return /\p{L}{2,}/u.test(words);
}
async function translateTexts(config, translator, source, items, onText, onUnchanged = () => {
}) {
  const counts = { done: 0, existing: 0, failed: 0, unchanged: 0 };
  const maxItems = config.maxItems || 100;
  const targets = new Map(items.map((item) => [item.key, item.target]));
  const sources = new Map(items.map((item) => [item.key, item.text]));
  const same = (a, b) => "string" === typeof b && String(a).replace(/\s+/g, " ").trim() === b.replace(/\s+/g, " ").trim();
  const give = (key, text) => {
    if (same(text, sources.get(key))) {
      counts.unchanged++;
      onUnchanged(key);
    } else {
      onText(key, text);
      counts.done++;
    }
  };
  for (let start = 0; start < items.length; start += maxItems) {
    const prepared = await api(config, "mt/strings/prepare", { source, items: items.slice(start, start + maxItems) });
    const toTranslate = [];
    for (const item of prepared.items || []) {
      if ("done" === item.status) {
        give(item.key, item.text);
      } else if ("translate" === item.status) {
        toTranslate.push(item);
      } else if ("existing" === item.reason) {
        counts.existing++;
      } else {
        counts.failed++;
      }
    }
    const routes = /* @__PURE__ */ new Map();
    for (const item of toTranslate) {
      if (!routes.has(item.route)) {
        routes.set(item.route, []);
      }
      item.segments.forEach((segment, i) => routes.get(item.route).push({ id: `${item.key}:${i}`, text: segment.text, html: 1 === segment.mode, item, i }));
    }
    for (const [route, segments] of routes) {
      for (let s = 0; s < segments.length; s += 40) {
        const batch = segments.slice(s, s + 40);
        const results = await translator.translate(route, batch.map(({ id, text, html }) => ({ id, text, html })));
        const byId = new Map(results.map((result) => [result.id, result]));
        for (const segment of batch) {
          const result = byId.get(segment.id);
          segment.item.segments[segment.i].output = result && "string" === typeof result.text ? result.text : "";
        }
      }
    }
    if (toTranslate.length) {
      const restored = await api(config, "mt/strings/restore", {
        source,
        items: toTranslate.map((item) => ({ key: item.key, target: targets.get(item.key), parts: item.parts, segments: item.segments }))
      });
      for (const item of restored.items || []) {
        if ("string" === typeof item.text) {
          give(item.key, item.text);
        } else {
          counts.failed++;
        }
      }
    }
  }
  return counts;
}

// modules/machine-translation/js/mt-frontend.js
(() => {
  const config = window.stropeMtFrontendData;
  if (!config || !config.kit || !isSupported2()) {
    return;
  }
  const i18n = config.i18n;
  let translator = null;
  let host = null;
  let listener = null;
  let hostListener = null;
  const code = (slug) => config.languages[slug] ? config.languages[slug].code : "";
  const canTranslate = (from, to) => {
    const route = routeOf(code(from), code(to));
    return "" !== route && config.allowed.includes(to) && isInstalled(route, config.directions);
  };
  const getTranslator = () => {
    if (!translator) {
      translator = createTranslator(config, (status) => {
        if (listener && ["starting", "loading", "downloading"].includes(status.state)) {
          listener(describeStatus(status, i18n));
        }
      });
    }
    return translator;
  };
  window.stropeMtFrontend = {
    i18n,
    defaultSource: config.defaultSource,
    englishSource: config.englishSource,
    canTranslate,
    isText: isTranslatableText,
    /**
     * Translates texts.
     *
     * @param {string}    from     Source language slug.
     * @param {string}    to       Target language slug.
     * @param {string[]}  texts    Texts (inline HTML or plain text).
     * @param {Function=} onStatus Called with status lines while the engine starts (first use: downloads).
     * @return {Promise<Array<string|false|null>>} The translation; false when it is the text itself
     *                                            (already in the target language?); null when it failed.
     */
    async translate(from, to, texts, onStatus) {
      const out = texts.map(() => null);
      listener = onStatus || null;
      try {
        await translateTexts(
          config,
          getTranslator(),
          from,
          texts.map((text, i) => ({ key: `t${i}`, target: to, text })),
          (key, text) => {
            out[Number(key.slice(1))] = text;
          },
          (key) => {
            out[Number(key.slice(1))] = false;
          }
        );
      } finally {
        listener = null;
      }
      return out;
    },
    /**
     * Adds a post to the translation queue, and starts working on it in this tab (another tab of the
     * browser may do it instead). The worker stops when the queue is empty.
     *
     * @param {number}    post     Source post id.
     * @param {string}    to       Target language slug.
     * @param {Function=} onStatus Called with the status lines of the worker.
     * @return {Promise<{run: number, skipped: string[]}>}
     */
    async translatePost(post, to, onStatus) {
      const result = await api(config, "mt/runs", { posts: [Number(post)], languages: [to] });
      hostListener = onStatus || null;
      if (!host) {
        host = createHost(config, (status) => {
          if (hostListener) {
            hostListener(describeStatus(status, i18n), status.state);
          }
        }, { stopWhenIdle: 3 });
      }
      host.start(false);
      return { run: Number(result.run_id), skipped: result.skipped || [] };
    },
    /**
     * Follows a run of one post: polls its translation until it is done, failed or cancelled.
     *
     * @param {number}   run      Run id.
     * @param {Function} onUpdate Called with the job: `status`, `progress`, `message`, `edit`.
     * @return {Function} Stops following.
     */
    watchRun(run, onUpdate) {
      let timer = null;
      let stopped = false;
      const poll = async () => {
        try {
          const data = await api(config, `mt/runs/${Number(run)}`);
          const job = (data.jobs || [])[0];
          if (stopped || !job) {
            return;
          }
          onUpdate(job);
          if (["done", "failed", "cancelled"].includes(job.status)) {
            return;
          }
        } catch (error) {
          if (stopped) {
            return;
          }
          onUpdate({ status: "error", message: error.message });
        }
        timer = setTimeout(poll, 3e3);
      };
      poll();
      return () => {
        stopped = true;
        clearTimeout(timer);
      };
    }
  };
})();
