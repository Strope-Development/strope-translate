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
function newWorker(config, index) {
  return new Worker(config.workerUrl, { type: "classic" === config.workerType ? "classic" : "module", name: `${config.id}-${index}` });
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

// modules/machine-translation/js/mt-strings.js
(() => {
  const config = window.stropeMtStrings;
  if (!config || !isSupported2()) {
    return;
  }
  const i18n = config.i18n;
  const discovered = "discovered" === config.view;
  const form = document.getElementById(discovered ? "strope-sd-translations" : "string-translation");
  const table = form && form.querySelector("table.wp-list-table");
  if (!table) {
    return;
  }
  const pattern = discovered ? /^strope_sd\[(\d+)\]\[([^\]]+)\]\[(\d+)\]$/ : /^translation\[([^\]]+)\]\[([^\]]+)\]$/;
  const storageKey = `strope-mt-source-${config.view}`;
  const filled = /* @__PURE__ */ new Set();
  let busy = false;
  const translator = createTranslator(config, (status) => {
    if (busy && ["starting", "loading", "downloading"].includes(status.state)) {
      setStatus(describeStatus(status, i18n));
    }
  });
  const select = document.createElement("select");
  select.id = `strope-mt-source-${config.view}`;
  for (const [slug, language] of Object.entries(config.languages)) {
    select.append(new Option(language.name, slug));
  }
  let source = config.defaultSource;
  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored && config.languages[stored]) {
      source = stored;
    }
  } catch (e) {
  }
  select.value = source;
  const label = document.createElement("label");
  label.htmlFor = select.id;
  label.textContent = i18n.from;
  const all = document.createElement("button");
  all.type = "button";
  all.className = "button";
  all.textContent = i18n.translateAll;
  const statusLine = document.createElement("span");
  statusLine.className = "strope-mt-strings-status";
  statusLine.setAttribute("aria-live", "polite");
  const bar = document.createElement("div");
  bar.className = "strope-mt-strings-bar";
  bar.append(label, select, all, statusLine);
  const tablenav = form.querySelector(".tablenav.top");
  (tablenav || table).before(bar);
  const setStatus = (text, isError = false) => {
    statusLine.textContent = text;
    statusLine.classList.toggle("is-error", isError);
  };
  const getFields = () => Array.from(form.querySelectorAll('.translation input[type="text"], .translation textarea')).map((el) => {
    const match = el.name && el.name.match(pattern);
    if (!match) {
      return null;
    }
    return discovered ? { el, lang: match[2], sdId: Number(match[1]), form: Number(match[3]) } : { el, lang: match[1] };
  }).filter(Boolean);
  const isTranslatable = (field) => {
    const from = config.languages[source] && config.languages[source].code;
    const to = config.languages[field.lang] && config.languages[field.lang].code;
    const route = routeOf(from, to);
    return "" !== route && field.lang !== source && isInstalled(route, config.directions) && "" === field.el.value.trim() && isTranslatableText(field.el.placeholder);
  };
  const buttons = /* @__PURE__ */ new Map();
  const refreshButtons = () => {
    let count = 0;
    for (const field of getFields()) {
      let button = buttons.get(field.el);
      if (!button) {
        button = document.createElement("button");
        button.type = "button";
        button.className = "button-link strope-mt-field-button";
        button.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';
        const name = config.languages[field.lang] ? config.languages[field.lang].name : field.lang;
        button.title = sprintf(i18n.translateOne, name);
        button.setAttribute("aria-label", button.title);
        button.addEventListener("click", () => run([field]));
        field.el.after(button);
        field.el.addEventListener("input", () => {
          if (!field.el.dataset.stropeMtFilling) {
            field.el.classList.remove("strope-mt-filled");
          }
          refreshButtons();
        });
        buttons.set(field.el, button);
      }
      const visible = isTranslatable(field);
      button.hidden = !visible;
      button.disabled = busy;
      count += visible ? 1 : 0;
    }
    all.disabled = busy || 0 === count;
  };
  select.addEventListener("change", () => {
    source = select.value;
    try {
      window.localStorage.setItem(storageKey, source);
    } catch (e) {
    }
    refreshButtons();
  });
  all.addEventListener("click", () => run(getFields().filter(isTranslatable)));
  const fill = (field, text) => {
    field.el.dataset.stropeMtFilling = "1";
    field.el.value = text;
    field.el.dispatchEvent(new Event("input", { bubbles: true }));
    field.el.dispatchEvent(new Event("change", { bubbles: true }));
    delete field.el.dataset.stropeMtFilling;
    field.el.classList.add("strope-mt-filled");
    field.el.title = i18n.filledTitle;
    filled.add(field.el);
  };
  const run = async (fields) => {
    if (busy || !fields.length) {
      return;
    }
    busy = true;
    refreshButtons();
    setStatus(i18n.working);
    const byKey = /* @__PURE__ */ new Map();
    const items = fields.map((field, index) => {
      const key = `f${index}`;
      byKey.set(key, field);
      return discovered ? { key, target: field.lang, sd_id: field.sdId, form: field.form } : { key, target: field.lang, text: field.el.placeholder };
    });
    try {
      const { done, existing, failed, unchanged } = await translateTexts(config, translator, source, items, (key, text) => fill(byKey.get(key), text));
      const notes = [sprintf(i18n.filled, done)];
      if (existing) {
        notes.push(sprintf(i18n.existing, existing));
      }
      if (failed) {
        notes.push(sprintf(i18n.failed, failed));
      }
      if (unchanged) {
        notes.push(sprintf(i18n.unchanged, unchanged));
      }
      setStatus(notes.join(" "));
    } catch (error) {
      setStatus(sprintf(i18n.error, error.message), true);
    } finally {
      busy = false;
      refreshButtons();
    }
  };
  form.addEventListener("submit", () => filled.clear());
  window.addEventListener("beforeunload", (event) => {
    if ([...filled].some((el) => el.isConnected && "" !== el.value)) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  refreshButtons();
})();
