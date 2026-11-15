// modules/machine-translation/lib/wasm-worker/js/worker.js
var sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function isEngineCrash(error) {
  return "undefined" !== typeof WebAssembly && error instanceof WebAssembly.RuntimeError || /^(RuntimeError|CompileError|LinkError)$/.test(error && error.name) || /\bAborted\(|out of memory|memory access out of bounds|unreachable/i.test(String(error && error.message));
}
async function sha256(bytes) {
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes));
  return Array.from(digest, (b) => b.toString(16).padStart(2, "0")).join("");
}
function capabilities() {
  const nav = "undefined" !== typeof navigator ? navigator : {};
  return {
    webgpu: !!nav.gpu,
    cores: nav.hardwareConcurrency || 0,
    memory: nav.deviceMemory || 0,
    threads: "undefined" !== typeof SharedArrayBuffer && !!self.crossOriginIsolated,
    node: "undefined" !== typeof process && !!(process.versions && process.versions.node)
  };
}
function serve({ runners }) {
  if (!runners || !Object.keys(runners).length) {
    throw new Error("serve() needs at least one runner.");
  }
  const state = {
    config: null,
    // { restUrl, nonce, auth }
    options: {},
    // The host's `workerOptions`, given to the runners.
    running: false,
    current: null,
    // AbortController of the batch in progress.
    affinity: "",
    // Affinity of the previous batch, asked for first.
    engines: /* @__PURE__ */ new Map(),
    // Type => Promise of the prepared engine.
    progress: /* @__PURE__ */ new Map(),
    // Unit ID => fraction, sent with the renewals.
    nonceWaiters: [],
    direct: Promise.resolve(),
    // Direct runs, one at a time.
    worker: {
      id: crypto.randomUUID(),
      capabilities: capabilities(),
      agent: "undefined" !== typeof navigator && navigator.userAgent ? navigator.userAgent.slice(0, 200) : "unknown"
    }
  };
  const post = (type, data = {}) => self.postMessage({ ...data, type });
  const status = (name, data = {}) => post("status", { state: name, ...data });
  const message = (error) => String(error && error.message || error);
  const configure = (data) => {
    state.config = { restUrl: data.restUrl, nonce: data.nonce || "", auth: data.auth || "" };
    state.options = data.options && "object" === typeof data.options ? data.options : {};
    if (data.workerId) {
      state.worker.id = String(data.workerId).slice(0, 64);
    }
  };
  self.onmessage = (event) => {
    const data = event.data || {};
    if ("start" === data.type) {
      configure(data);
      if (!state.running) {
        state.running = true;
        loop();
      }
    } else if ("configure" === data.type) {
      configure(data);
    } else if ("stop" === data.type) {
      state.running = false;
      if (state.current) {
        state.current.abort();
      }
    } else if ("nonce" === data.type) {
      state.config.nonce = data.nonce;
      state.nonceWaiters.splice(0).forEach((resolve) => resolve());
    } else if ("run" === data.type) {
      state.direct = state.direct.then(() => runDirect(data));
    }
  };
  async function authFetch(url, init = {}, retry = true) {
    const own = new URL(url, state.config.restUrl).origin === new URL(state.config.restUrl).origin;
    const headers = new Headers(init.headers || {});
    if (own) {
      if (state.config.auth) {
        headers.set("Authorization", state.config.auth);
      } else {
        headers.set("X-WP-Nonce", state.config.nonce);
      }
    }
    const response = await fetch(url, { ...init, headers, credentials: own && !state.config.auth ? "same-origin" : "omit" });
    if (own && 403 === response.status && retry && !state.config.auth) {
      const data = await response.clone().json().catch(() => null);
      if (data && "rest_cookie_invalid_nonce" === data.code) {
        await new Promise((resolve) => {
          state.nonceWaiters.push(resolve);
          post("need-nonce");
          setTimeout(resolve, 3e4);
        });
        return authFetch(url, init, false);
      }
    }
    return response;
  }
  async function api(path, body) {
    const isForm = body instanceof FormData;
    const response = await authFetch(state.config.restUrl + path, {
      method: body ? "POST" : "GET",
      headers: body && !isForm ? { "Content-Type": "application/json" } : {},
      body: body ? isForm ? body : JSON.stringify(body) : void 0
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
      const error = new Error(data && data.message || `HTTP ${response.status}`);
      error.code = data && data.code;
      error.status = response.status;
      throw error;
    }
    return data;
  }
  function env(type) {
    return {
      fetch: (url, init) => authFetch(url, init),
      report: (name, data = {}) => status(String(name), { ...data, task: type }),
      options: state.options
    };
  }
  function getEngine(type) {
    if (!state.engines.has(type)) {
      const runner = runners[type];
      const promise = Promise.resolve(runner.prepare ? runner.prepare(env(type)) : null);
      promise.catch(() => state.engines.delete(type));
      state.engines.set(type, promise);
    }
    return state.engines.get(type);
  }
  async function runDirect(data) {
    const runner = runners[data.task];
    const inputs = Array.isArray(data.inputs) ? data.inputs : [];
    try {
      if (!runner || runner.supports && false === runner.supports()) {
        throw new Error(`This worker cannot run "${data.task}".`);
      }
      if (!state.config) {
        throw new Error("The worker is not configured.");
      }
      const engine = await getEngine(data.task);
      const signal = new AbortController().signal;
      const unavailable = () => Promise.reject(new Error("Not available in a direct run."));
      let outcomes;
      if (runner.runBatch) {
        outcomes = await runner.runBatch({
          ...env(data.task),
          units: inputs.map((input) => ({ input, unit: null, attempt: 1 })),
          engine,
          signal,
          progress: () => {
          }
        });
      } else {
        outcomes = [];
        for (const input of inputs) {
          try {
            outcomes.push({ result: await runner.run({ ...env(data.task), input, resume: null, artifacts: [], engine, signal, unit: null, attempt: 1, checkpoint: unavailable, upload: unavailable, progress: () => {
            } }) });
          } catch (error) {
            if (isEngineCrash(error)) {
              state.engines.delete(data.task);
            }
            outcomes.push({ error: message(error) });
          }
        }
      }
      post("run-result", { id: data.id, outcomes });
    } catch (error) {
      if (isEngineCrash(error)) {
        state.engines.delete(data.task);
      }
      post("run-result", { id: data.id, error: message(error) });
    }
  }
  async function loop() {
    let idle = 0;
    while (state.running) {
      try {
        const types = Object.keys(runners).filter((type) => !runners[type].supports || false !== runners[type].supports());
        if (!types.length) {
          status("unsupported");
          return;
        }
        const { batch } = await api("claim", { types, affinity: state.affinity, worker: state.worker });
        if (!batch) {
          idle++;
          status("idle", { idle });
          await sleep(Math.min(3e4, 2e3 * 2 ** Math.min(idle, 4)));
          continue;
        }
        idle = 0;
        state.affinity = batch.affinity || "";
        await runBatch(batch);
      } catch (error) {
        status("error", { message: message(error) });
        await sleep(1e4);
      }
    }
    status("stopped");
  }
  async function runBatch(batch) {
    const controller = new AbortController();
    const runner = runners[batch.type];
    const results = [];
    const settled = /* @__PURE__ */ new Set();
    let lost = false;
    state.current = controller;
    post("lease", { lease: batch.lease, units: batch.units.map((unit) => ({ unit_id: unit.id, epoch: unit.epoch })) });
    const renew = setInterval(async () => {
      const progress = {};
      for (const unit of batch.units) {
        if (state.progress.has(unit.id)) {
          progress[unit.id] = state.progress.get(unit.id);
        }
      }
      try {
        await api("renew", { lease: batch.lease, progress, worker: { id: state.worker.id } });
      } catch (error) {
        if ("ww_stale_lease" === error.code) {
          lost = true;
          controller.abort();
          status("lease-lost", { task: batch.type });
        }
      }
    }, Math.max(5, batch.ttl / 3) * 1e3);
    const giveBack = (unit, error) => {
      settled.add(unit.id);
      return api("fail", {
        lease: batch.lease,
        unit_id: unit.id,
        epoch: unit.epoch,
        error: String(error && error.message || error).slice(0, 500),
        retry: !(error && false === error.retry)
      }).catch(() => {
      });
    };
    try {
      let engine;
      try {
        status("preparing", { task: batch.type });
        engine = await getEngine(batch.type);
      } catch (error) {
        for (const unit of batch.units) {
          await giveBack(unit, error);
        }
        throw error;
      }
      const accept = (unit, result) => results.push({ unit_id: unit.id, epoch: unit.epoch, completion_id: crypto.randomUUID(), result: void 0 === result ? null : result });
      if (runner.runBatch) {
        status("working", { task: batch.type, count: batch.units.length });
        let outcomes;
        try {
          outcomes = await runner.runBatch({
            ...env(batch.type),
            units: batch.units.map((unit) => ({ input: unit.input, unit: { id: unit.id, key: unit.key, jobId: unit.job_id }, attempt: unit.attempt || 1 })),
            engine,
            signal: controller.signal,
            progress(fraction, text = "") {
              const value = Math.max(0, Math.min(1, Number(fraction) || 0));
              batch.units.forEach((unit) => state.progress.set(unit.id, value));
              status("progress", { task: batch.type, fraction: value, text });
            }
          });
        } catch (error) {
          if (lost || !state.running) {
            return;
          }
          if (isEngineCrash(error)) {
            state.engines.delete(batch.type);
          }
          for (const unit of batch.units) {
            await giveBack(unit, error);
          }
          throw error;
        }
        if (!Array.isArray(outcomes) || outcomes.length !== batch.units.length) {
          throw new Error(`runBatch() of "${batch.type}" must return one outcome per unit.`);
        }
        for (let i = 0; i < batch.units.length; i++) {
          const outcome = outcomes[i] || {};
          if ("error" in outcome && outcome.error) {
            const error = new Error(String(outcome.error));
            error.retry = false !== outcome.retry;
            await giveBack(batch.units[i], error);
            status("unit-failed", { task: batch.type, unit: batch.units[i].key, message: error.message });
          } else {
            accept(batch.units[i], outcome.result);
          }
        }
      } else {
        for (let i = 0; i < batch.units.length; i++) {
          const unit = batch.units[i];
          if (controller.signal.aborted) {
            break;
          }
          status("working", { task: batch.type, unit: unit.key, index: i, count: batch.units.length });
          try {
            accept(unit, await runner.run(makeContext(batch, unit, engine, controller.signal)));
          } catch (error) {
            if (lost || !state.running) {
              break;
            }
            if (isEngineCrash(error)) {
              state.engines.delete(batch.type);
              engine = await getEngine(batch.type).catch(() => null);
            }
            await giveBack(unit, error);
            status("unit-failed", { task: batch.type, unit: unit.key, message: message(error) });
            if (!engine) {
              break;
            }
          }
        }
      }
      if (results.length && !lost) {
        const receipts = await submit(batch.lease, results);
        results.forEach((item) => settled.add(item.unit_id));
        status("completed", { task: batch.type, accepted: receipts.filter((r) => r.accepted).length, rejected: receipts.filter((r) => !r.accepted).length });
      }
    } finally {
      clearInterval(renew);
      const unfinished = batch.units.filter((unit) => !settled.has(unit.id));
      if (!state.running && !lost && unfinished.length) {
        await api("release", { lease: batch.lease, units: unfinished.map((unit) => ({ unit_id: unit.id, epoch: unit.epoch })) }).catch(() => {
        });
      }
      post("lease", { lease: null, units: [] });
      state.current = null;
      for (const unit of batch.units) {
        state.progress.delete(unit.id);
      }
    }
  }
  async function submit(lease, results) {
    for (let attempt = 0; ; attempt++) {
      try {
        const { receipts } = await api("complete", { lease, results });
        return receipts || [];
      } catch (error) {
        if (error.status && error.status < 500 || attempt >= 3) {
          throw error;
        }
        await sleep(2e3 * (attempt + 1));
      }
    }
  }
  function makeContext(batch, unit, engine, signal) {
    let sequence = unit.checkpoint_seq || 0;
    return {
      ...env(batch.type),
      input: unit.input,
      resume: unit.checkpoint,
      artifacts: unit.artifacts || [],
      engine,
      signal,
      unit: { id: unit.id, key: unit.key, jobId: unit.job_id },
      async checkpoint(data) {
        signal.throwIfAborted();
        const receipt = await api("checkpoint", { lease: batch.lease, unit_id: unit.id, epoch: unit.epoch, sequence: sequence + 1, checkpoint: data });
        sequence = receipt.sequence;
        return receipt;
      },
      async upload(key, data, type = "application/octet-stream") {
        signal.throwIfAborted();
        const blob = data instanceof Blob ? data : new Blob([data], { type });
        const form = new FormData();
        form.append("lease", batch.lease);
        form.append("unit_id", String(unit.id));
        form.append("epoch", String(unit.epoch));
        form.append("key", key);
        form.append("sha256", await sha256(await blob.arrayBuffer()));
        form.append("file", blob, key.split("/").pop());
        return api("artifact", form);
      },
      progress(fraction, text = "") {
        state.progress.set(unit.id, Math.max(0, Math.min(1, Number(fraction) || 0)));
        status("progress", { task: batch.type, unit: unit.key, fraction, text });
      },
      attempt: unit.attempt || 1
    };
  }
}

// node_modules/fzstd/esm/index.mjs
var ab = ArrayBuffer;
var u8 = Uint8Array;
var u16 = Uint16Array;
var i16 = Int16Array;
var i32 = Int32Array;
var slc = function(v, s, e) {
  if (u8.prototype.slice)
    return u8.prototype.slice.call(v, s, e);
  if (s == null || s < 0)
    s = 0;
  if (e == null || e > v.length)
    e = v.length;
  var n = new u8(e - s);
  n.set(v.subarray(s, e));
  return n;
};
var fill = function(v, n, s, e) {
  if (u8.prototype.fill)
    return u8.prototype.fill.call(v, n, s, e);
  if (s == null || s < 0)
    s = 0;
  if (e == null || e > v.length)
    e = v.length;
  for (; s < e; ++s)
    v[s] = n;
  return v;
};
var cpw = function(v, t, s, e) {
  if (u8.prototype.copyWithin)
    return u8.prototype.copyWithin.call(v, t, s, e);
  if (s == null || s < 0)
    s = 0;
  if (e == null || e > v.length)
    e = v.length;
  while (s < e) {
    v[t++] = v[s++];
  }
};
var ec = [
  "invalid zstd data",
  "window size too large (>2046MB)",
  "invalid block type",
  "FSE accuracy too high",
  "match distance too far back",
  "unexpected EOF"
];
var err = function(ind, msg, nt) {
  var e = new Error(msg || ec[ind]);
  e.code = ind;
  if (Error.captureStackTrace)
    Error.captureStackTrace(e, err);
  if (!nt)
    throw e;
  return e;
};
var rb = function(d, b, n) {
  var i = 0, o = 0;
  for (; i < n; ++i)
    o |= d[b++] << (i << 3);
  return o;
};
var b4 = function(d, b) {
  return (d[b] | d[b + 1] << 8 | d[b + 2] << 16 | d[b + 3] << 24) >>> 0;
};
var rzfh = function(dat, w) {
  var n3 = dat[0] | dat[1] << 8 | dat[2] << 16;
  if (n3 == 3126568 && dat[3] == 253) {
    var flg = dat[4];
    var ss = flg >> 5 & 1, cc = flg >> 2 & 1, df = flg & 3, fcf = flg >> 6;
    if (flg & 8)
      err(0);
    var bt = 6 - ss;
    var db = df == 3 ? 4 : df;
    var di = rb(dat, bt, db);
    bt += db;
    var fsb = fcf ? 1 << fcf : ss;
    var fss = rb(dat, bt, fsb) + (fcf == 1 && 256);
    var ws = fss;
    if (!ss) {
      var wb = 1 << 10 + (dat[5] >> 3);
      ws = wb + (wb >> 3) * (dat[5] & 7);
    }
    if (ws > 2145386496)
      err(1);
    var buf = new u8((w == 1 ? fss || ws : w ? 0 : ws) + 12);
    buf[0] = 1, buf[4] = 4, buf[8] = 8;
    return {
      b: bt + fsb,
      y: 0,
      l: 0,
      d: di,
      w: w && w != 1 ? w : buf.subarray(12),
      e: ws,
      o: new i32(buf.buffer, 0, 3),
      u: fss,
      c: cc,
      m: Math.min(131072, ws)
    };
  } else if ((n3 >> 4 | dat[3] << 20) == 25481893) {
    return b4(dat, 4) + 8;
  }
  err(0);
};
var msb = function(val) {
  var bits = 0;
  for (; 1 << bits <= val; ++bits)
    ;
  return bits - 1;
};
var rfse = function(dat, bt, mal) {
  var tpos = (bt << 3) + 4;
  var al = (dat[bt] & 15) + 5;
  if (al > mal)
    err(3);
  var sz = 1 << al;
  var probs = sz, sym = -1, re = -1, i = -1, ht = sz;
  var buf = new ab(512 + (sz << 2));
  var freq = new i16(buf, 0, 256);
  var dstate = new u16(buf, 0, 256);
  var nstate = new u16(buf, 512, sz);
  var bb1 = 512 + (sz << 1);
  var syms = new u8(buf, bb1, sz);
  var nbits = new u8(buf, bb1 + sz);
  while (sym < 255 && probs > 0) {
    var bits = msb(probs + 1);
    var cbt = tpos >> 3;
    var msk = (1 << bits + 1) - 1;
    var val = (dat[cbt] | dat[cbt + 1] << 8 | dat[cbt + 2] << 16) >> (tpos & 7) & msk;
    var msk1fb = (1 << bits) - 1;
    var msv = msk - probs - 1;
    var sval = val & msk1fb;
    if (sval < msv)
      tpos += bits, val = sval;
    else {
      tpos += bits + 1;
      if (val > msk1fb)
        val -= msv;
    }
    freq[++sym] = --val;
    if (val == -1) {
      probs += val;
      syms[--ht] = sym;
    } else
      probs -= val;
    if (!val) {
      do {
        var rbt = tpos >> 3;
        re = (dat[rbt] | dat[rbt + 1] << 8) >> (tpos & 7) & 3;
        tpos += 2;
        sym += re;
      } while (re == 3);
    }
  }
  if (sym > 255 || probs)
    err(0);
  var sympos = 0;
  var sstep = (sz >> 1) + (sz >> 3) + 3;
  var smask = sz - 1;
  for (var s = 0; s <= sym; ++s) {
    var sf = freq[s];
    if (sf < 1) {
      dstate[s] = -sf;
      continue;
    }
    for (i = 0; i < sf; ++i) {
      syms[sympos] = s;
      do {
        sympos = sympos + sstep & smask;
      } while (sympos >= ht);
    }
  }
  if (sympos)
    err(0);
  for (i = 0; i < sz; ++i) {
    var ns = dstate[syms[i]]++;
    var nb = nbits[i] = al - msb(ns);
    nstate[i] = (ns << nb) - sz;
  }
  return [tpos + 7 >> 3, {
    b: al,
    s: syms,
    n: nbits,
    t: nstate
  }];
};
var rhu = function(dat, bt) {
  var i = 0, wc = -1;
  var buf = new u8(292), hb = dat[bt];
  var hw = buf.subarray(0, 256);
  var rc = buf.subarray(256, 268);
  var ri = new u16(buf.buffer, 268);
  if (hb < 128) {
    var _a = rfse(dat, bt + 1, 6), ebt = _a[0], fdt = _a[1];
    bt += hb;
    var epos = ebt << 3;
    var lb = dat[bt];
    if (!lb)
      err(0);
    var st1 = 0, st2 = 0, btr1 = fdt.b, btr2 = btr1;
    var fpos = (++bt << 3) - 8 + msb(lb);
    for (; ; ) {
      fpos -= btr1;
      if (fpos < epos)
        break;
      var cbt = fpos >> 3;
      st1 += (dat[cbt] | dat[cbt + 1] << 8) >> (fpos & 7) & (1 << btr1) - 1;
      hw[++wc] = fdt.s[st1];
      fpos -= btr2;
      if (fpos < epos)
        break;
      cbt = fpos >> 3;
      st2 += (dat[cbt] | dat[cbt + 1] << 8) >> (fpos & 7) & (1 << btr2) - 1;
      hw[++wc] = fdt.s[st2];
      btr1 = fdt.n[st1];
      st1 = fdt.t[st1];
      btr2 = fdt.n[st2];
      st2 = fdt.t[st2];
    }
    if (++wc > 255)
      err(0);
  } else {
    wc = hb - 127;
    for (; i < wc; i += 2) {
      var byte = dat[++bt];
      hw[i] = byte >> 4;
      hw[i + 1] = byte & 15;
    }
    ++bt;
  }
  var wes = 0;
  for (i = 0; i < wc; ++i) {
    var wt = hw[i];
    if (wt > 11)
      err(0);
    wes += wt && 1 << wt - 1;
  }
  var mb = msb(wes) + 1;
  var ts = 1 << mb;
  var rem = ts - wes;
  if (rem & rem - 1)
    err(0);
  hw[wc++] = msb(rem) + 1;
  for (i = 0; i < wc; ++i) {
    var wt = hw[i];
    ++rc[hw[i] = wt && mb + 1 - wt];
  }
  var hbuf = new u8(ts << 1);
  var syms = hbuf.subarray(0, ts), nb = hbuf.subarray(ts);
  ri[mb] = 0;
  for (i = mb; i > 0; --i) {
    var pv = ri[i];
    fill(nb, i, pv, ri[i - 1] = pv + rc[i] * (1 << mb - i));
  }
  if (ri[0] != ts)
    err(0);
  for (i = 0; i < wc; ++i) {
    var bits = hw[i];
    if (bits) {
      var code = ri[bits];
      fill(syms, i, code, ri[bits] = code + (1 << mb - bits));
    }
  }
  return [bt, {
    n: nb,
    b: mb,
    s: syms
  }];
};
var dllt = rfse(/* @__PURE__ */ new u8([
  81,
  16,
  99,
  140,
  49,
  198,
  24,
  99,
  12,
  33,
  196,
  24,
  99,
  102,
  102,
  134,
  70,
  146,
  4
]), 0, 6)[1];
var dmlt = rfse(/* @__PURE__ */ new u8([
  33,
  20,
  196,
  24,
  99,
  140,
  33,
  132,
  16,
  66,
  8,
  33,
  132,
  16,
  66,
  8,
  33,
  68,
  68,
  68,
  68,
  68,
  68,
  68,
  68,
  36,
  9
]), 0, 6)[1];
var doct = rfse(/* @__PURE__ */ new u8([
  32,
  132,
  16,
  66,
  102,
  70,
  68,
  68,
  68,
  68,
  36,
  73,
  2
]), 0, 5)[1];
var b2bl = function(b, s) {
  var len = b.length, bl = new i32(len);
  for (var i = 0; i < len; ++i) {
    bl[i] = s;
    s += 1 << b[i];
  }
  return bl;
};
var llb = /* @__PURE__ */ new u8((/* @__PURE__ */ new i32([
  0,
  0,
  0,
  0,
  16843009,
  50528770,
  134678020,
  202050057,
  269422093
])).buffer, 0, 36);
var llbl = /* @__PURE__ */ b2bl(llb, 0);
var mlb = /* @__PURE__ */ new u8((/* @__PURE__ */ new i32([
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  16843009,
  50528770,
  117769220,
  185207048,
  252579084,
  16
])).buffer, 0, 53);
var mlbl = /* @__PURE__ */ b2bl(mlb, 3);
var dhu = function(dat, out, hu) {
  var len = dat.length, ss = out.length, lb = dat[len - 1], msk = (1 << hu.b) - 1, eb = -hu.b;
  if (!lb)
    err(0);
  var st = 0, btr = hu.b, pos = (len << 3) - 8 + msb(lb) - btr, i = -1;
  for (; pos > eb && i < ss; ) {
    var cbt = pos >> 3;
    var val = (dat[cbt] | dat[cbt + 1] << 8 | dat[cbt + 2] << 16) >> (pos & 7);
    st = (st << btr | val) & msk;
    out[++i] = hu.s[st];
    pos -= btr = hu.n[st];
  }
  if (pos != eb || i + 1 != ss)
    err(0);
};
var dhu4 = function(dat, out, hu) {
  var bt = 6;
  var ss = out.length, sz1 = ss + 3 >> 2, sz2 = sz1 << 1, sz3 = sz1 + sz2;
  dhu(dat.subarray(bt, bt += dat[0] | dat[1] << 8), out.subarray(0, sz1), hu);
  dhu(dat.subarray(bt, bt += dat[2] | dat[3] << 8), out.subarray(sz1, sz2), hu);
  dhu(dat.subarray(bt, bt += dat[4] | dat[5] << 8), out.subarray(sz2, sz3), hu);
  dhu(dat.subarray(bt), out.subarray(sz3), hu);
};
var rzb = function(dat, st, out) {
  var _a;
  var bt = st.b;
  var b0 = dat[bt], btype = b0 >> 1 & 3;
  st.l = b0 & 1;
  var sz = b0 >> 3 | dat[bt + 1] << 5 | dat[bt + 2] << 13;
  var ebt = (bt += 3) + sz;
  if (btype == 1) {
    if (bt >= dat.length)
      return;
    st.b = bt + 1;
    if (out) {
      fill(out, dat[bt], st.y, st.y += sz);
      return out;
    }
    return fill(new u8(sz), dat[bt]);
  }
  if (ebt > dat.length)
    return;
  if (btype == 0) {
    st.b = ebt;
    if (out) {
      out.set(dat.subarray(bt, ebt), st.y);
      st.y += sz;
      return out;
    }
    return slc(dat, bt, ebt);
  }
  if (btype == 2) {
    var b3 = dat[bt], lbt = b3 & 3, sf = b3 >> 2 & 3;
    var lss = b3 >> 4, lcs = 0, s4 = 0;
    if (lbt < 2) {
      if (sf & 1)
        lss |= dat[++bt] << 4 | (sf & 2 && dat[++bt] << 12);
      else
        lss = b3 >> 3;
    } else {
      s4 = sf;
      if (sf < 2)
        lss |= (dat[++bt] & 63) << 4, lcs = dat[bt] >> 6 | dat[++bt] << 2;
      else if (sf == 2)
        lss |= dat[++bt] << 4 | (dat[++bt] & 3) << 12, lcs = dat[bt] >> 2 | dat[++bt] << 6;
      else
        lss |= dat[++bt] << 4 | (dat[++bt] & 63) << 12, lcs = dat[bt] >> 6 | dat[++bt] << 2 | dat[++bt] << 10;
    }
    ++bt;
    var buf = out ? out.subarray(st.y, st.y + st.m) : new u8(st.m);
    var spl = buf.length - lss;
    if (lbt == 0)
      buf.set(dat.subarray(bt, bt += lss), spl);
    else if (lbt == 1)
      fill(buf, dat[bt++], spl);
    else {
      var hu = st.h;
      if (lbt == 2) {
        var hud = rhu(dat, bt);
        lcs += bt - (bt = hud[0]);
        st.h = hu = hud[1];
      } else if (!hu)
        err(0);
      (s4 ? dhu4 : dhu)(dat.subarray(bt, bt += lcs), buf.subarray(spl), hu);
    }
    var ns = dat[bt++];
    if (ns) {
      if (ns == 255)
        ns = (dat[bt++] | dat[bt++] << 8) + 32512;
      else if (ns > 127)
        ns = ns - 128 << 8 | dat[bt++];
      var scm = dat[bt++];
      if (scm & 3)
        err(0);
      var dts = [dmlt, doct, dllt];
      for (var i = 2; i > -1; --i) {
        var md = scm >> (i << 1) + 2 & 3;
        if (md == 1) {
          var rbuf = new u8([0, 0, dat[bt++]]);
          dts[i] = {
            s: rbuf.subarray(2, 3),
            n: rbuf.subarray(0, 1),
            t: new u16(rbuf.buffer, 0, 1),
            b: 0
          };
        } else if (md == 2) {
          _a = rfse(dat, bt, 9 - (i & 1)), bt = _a[0], dts[i] = _a[1];
        } else if (md == 3) {
          if (!st.t)
            err(0);
          dts[i] = st.t[i];
        }
      }
      var _b = st.t = dts, mlt = _b[0], oct = _b[1], llt = _b[2];
      var lb = dat[ebt - 1];
      if (!lb)
        err(0);
      var spos = (ebt << 3) - 8 + msb(lb) - llt.b, cbt = spos >> 3, oubt = 0;
      var lst = (dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << llt.b) - 1;
      cbt = (spos -= oct.b) >> 3;
      var ost = (dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << oct.b) - 1;
      cbt = (spos -= mlt.b) >> 3;
      var mst = (dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << mlt.b) - 1;
      for (++ns; --ns; ) {
        var llc = llt.s[lst];
        var lbtr = llt.n[lst];
        var mlc = mlt.s[mst];
        var mbtr = mlt.n[mst];
        var ofc = oct.s[ost];
        var obtr = oct.n[ost];
        cbt = (spos -= ofc) >> 3;
        var ofp = 1 << ofc;
        var off = ofp + ((dat[cbt] | dat[cbt + 1] << 8 | dat[cbt + 2] << 16 | dat[cbt + 3] << 24) >>> (spos & 7) & ofp - 1);
        cbt = (spos -= mlb[mlc]) >> 3;
        var ml = mlbl[mlc] + ((dat[cbt] | dat[cbt + 1] << 8 | dat[cbt + 2] << 16) >> (spos & 7) & (1 << mlb[mlc]) - 1);
        cbt = (spos -= llb[llc]) >> 3;
        var ll = llbl[llc] + ((dat[cbt] | dat[cbt + 1] << 8 | dat[cbt + 2] << 16) >> (spos & 7) & (1 << llb[llc]) - 1);
        cbt = (spos -= lbtr) >> 3;
        lst = llt.t[lst] + ((dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << lbtr) - 1);
        cbt = (spos -= mbtr) >> 3;
        mst = mlt.t[mst] + ((dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << mbtr) - 1);
        cbt = (spos -= obtr) >> 3;
        ost = oct.t[ost] + ((dat[cbt] | dat[cbt + 1] << 8) >> (spos & 7) & (1 << obtr) - 1);
        if (off > 3) {
          st.o[2] = st.o[1];
          st.o[1] = st.o[0];
          st.o[0] = off -= 3;
        } else {
          var idx = off - (ll != 0);
          if (idx) {
            off = idx == 3 ? st.o[0] - 1 : st.o[idx];
            if (idx > 1)
              st.o[2] = st.o[1];
            st.o[1] = st.o[0];
            st.o[0] = off;
          } else
            off = st.o[0];
        }
        for (var i = 0; i < ll; ++i) {
          buf[oubt + i] = buf[spl + i];
        }
        oubt += ll, spl += ll;
        var stin = oubt - off;
        if (stin < 0) {
          var len = -stin;
          var bs = st.e + stin;
          if (len > ml)
            len = ml;
          for (var i = 0; i < len; ++i) {
            buf[oubt + i] = st.w[bs + i];
          }
          oubt += len, ml -= len, stin = 0;
        }
        for (var i = 0; i < ml; ++i) {
          buf[oubt + i] = buf[stin + i];
        }
        oubt += ml;
      }
      if (oubt != spl) {
        while (spl < buf.length) {
          buf[oubt++] = buf[spl++];
        }
      } else
        oubt = buf.length;
      if (out)
        st.y += oubt;
      else
        buf = slc(buf, 0, oubt);
    } else if (out) {
      st.y += lss;
      if (spl) {
        for (var i = 0; i < lss; ++i) {
          buf[i] = buf[spl + i];
        }
      }
    } else if (spl)
      buf = slc(buf, spl);
    st.b = ebt;
    return buf;
  }
  err(2);
};
var cct = function(bufs, ol) {
  if (bufs.length == 1)
    return bufs[0];
  var buf = new u8(ol);
  for (var i = 0, b = 0; i < bufs.length; ++i) {
    var chk = bufs[i];
    buf.set(chk, b);
    b += chk.length;
  }
  return buf;
};
function decompress(dat, buf) {
  var bufs = [], nb = +!buf;
  var bt = 0, ol = 0;
  for (; dat.length; ) {
    var st = rzfh(dat, nb || buf);
    if (typeof st == "object") {
      if (nb) {
        buf = null;
        if (st.w.length == st.u) {
          bufs.push(buf = st.w);
          ol += st.u;
        }
      } else {
        bufs.push(buf);
        st.e = 0;
      }
      for (; !st.l; ) {
        var blk = rzb(dat, st, buf);
        if (!blk)
          err(5);
        if (buf)
          st.e = st.y;
        else {
          bufs.push(blk);
          ol += blk.length;
          cpw(st.w, 0, blk.length);
          st.w.set(blk, st.w.length - blk.length);
        }
      }
      bt = st.b + st.c * 4;
    } else
      bt = st;
    dat = dat.subarray(bt);
  }
  return cct(bufs, ol);
}

// modules/machine-translation/js/lib/engine.js
var CACHE_NAME = "strope-mt-v1";
var ALIGNMENTS = { model: 256, lex: 64, vocab: 64, srcvocab: 64, trgvocab: 64 };
var MAX_MODELS = 3;
function routeDirections(route) {
  const codes = route.match(/zh-Han[st]|[a-z]{2,3}/g) || [];
  const directions = [];
  for (let i = 0; i < codes.length - 1; i++) {
    directions.push(`${codes[i]}-${codes[i + 1]}`);
  }
  return directions;
}
function countWords(text) {
  return String(text).replace(/<[^>]*>/g, " ").split(/\s+/).filter(Boolean).length;
}
function createEngine({ fetch: authFetch, report, options }) {
  const state = {
    manifest: null,
    bergamot: null,
    service: null,
    models: /* @__PURE__ */ new Map(),
    // Direction => TranslationModel, least recently used first.
    routeModels: null,
    route: "",
    lock: Promise.resolve()
    // One translation at a time: the engine is synchronous, the models shared.
  };
  const getManifest = async (refresh = false) => {
    if (!state.manifest || refresh) {
      const response = await authFetch(options.manifestUrl);
      if (!response.ok) {
        throw new Error(`The list of language models could not be loaded (HTTP ${response.status}).`);
      }
      state.manifest = await response.json();
      cleanCache(state.manifest);
    }
    return state.manifest;
  };
  const loadEngine = async () => {
    if (state.bergamot) {
      return;
    }
    report("starting");
    const manifest = await getManifest();
    if (!manifest.wasm) {
      throw new Error("The translation engine is not installed.");
    }
    if ("function" !== typeof self.loadBergamot) {
      self.importScripts(options.glueUrl);
    }
    const wasmBinary = await getFile(manifest.wasm, "engine", report);
    state.bergamot = await new Promise((resolve, reject) => {
      const bergamot = loadBergamot({
        INITIAL_MEMORY: 41943040,
        print: () => {
        },
        printErr: () => {
        },
        onAbort: () => reject(new Error("The translation engine could not start.")),
        onRuntimeInitialized: async () => {
          await Promise.resolve();
          resolve(bergamot);
        },
        wasmBinary
      });
    });
    state.service = new state.bergamot.BlockingService({ cacheSize: 0 });
  };
  const loadRoute = async (route) => {
    await loadEngine();
    if (state.route === route && state.routeModels) {
      return;
    }
    const directions = routeDirections(route);
    for (const direction of directions) {
      if (state.models.has(direction)) {
        const model = state.models.get(direction);
        state.models.delete(direction);
        state.models.set(direction, model);
        continue;
      }
      let entry = (await getManifest()).directions[direction];
      if (!entry) {
        entry = (await getManifest(true)).directions[direction];
      }
      if (!entry) {
        const error = new Error(`The ${direction} language model is not installed.`);
        error.retry = true;
        throw error;
      }
      report("loading", { direction });
      const files = {};
      for (const [type, file] of Object.entries(entry.files)) {
        files[type] = { data: await getFile(file, direction, report), name: file.name };
      }
      state.models.set(direction, buildModel(state.bergamot, direction, files));
      while (state.models.size > MAX_MODELS) {
        const oldest = state.models.keys().next().value;
        if (directions.includes(oldest)) {
          break;
        }
        state.models.get(oldest).delete();
        state.models.delete(oldest);
      }
    }
    state.routeModels = directions.map((direction) => state.models.get(direction));
    state.route = route;
  };
  const reset = async (route) => {
    state.bergamot = null;
    state.service = null;
    state.models.clear();
    state.routeModels = null;
    state.route = "";
    await loadRoute(route);
  };
  const runEngine = (items) => {
    const b = state.bergamot;
    const messages = new b.VectorString();
    const responseOptions = new b.VectorResponseOptions();
    let responses = null;
    try {
      for (const item of items) {
        messages.push_back(item.text);
        responseOptions.push_back({ qualityScores: false, alignment: !!item.html, html: !!item.html });
      }
      const models = state.routeModels;
      responses = 1 === models.length ? state.service.translate(models[0], messages, responseOptions) : state.service.translateViaPivoting(models[0], models[1], messages, responseOptions);
      const out = [];
      for (let i = 0; i < items.length; i++) {
        out.push({ text: responses.get(i).getTranslatedText() });
      }
      return out;
    } finally {
      try {
        messages.delete();
        responseOptions.delete();
        if (responses) {
          responses.delete();
        }
      } catch (e) {
      }
    }
  };
  const translate = (route, items) => {
    const run = state.lock.then(async () => {
      await loadRoute(route);
      try {
        return runEngine(items);
      } catch (error) {
        const results = [];
        await reset(route);
        for (const item of items) {
          try {
            results.push(...runEngine([item]));
          } catch (itemError) {
            results.push({ error: "engine error" });
            await reset(route);
          }
        }
        return results;
      }
    });
    state.lock = run.catch(() => {
    });
    return run;
  };
  return { translate };
}
function buildModel(b, direction, files) {
  const memory = {};
  for (const [type, file] of Object.entries(files)) {
    const aligned = new b.AlignedMemory(file.data.byteLength, ALIGNMENTS[type] || 64);
    aligned.getByteArrayView().set(file.data);
    memory[type] = aligned;
  }
  const vocabs = new b.AlignedMemoryList();
  if (memory.vocab) {
    vocabs.push_back(memory.vocab);
  } else {
    vocabs.push_back(memory.srcvocab);
    vocabs.push_back(memory.trgvocab);
  }
  const config = {
    "beam-size": "1",
    normalize: "1.0",
    "word-penalty": "0",
    "max-length-break": "128",
    "mini-batch-words": "1024",
    workspace: "128",
    "max-length-factor": "2.0",
    "skip-cost": "true",
    "cpu-threads": "0",
    quiet: "true",
    "quiet-translation": "true",
    "gemm-precision": /intgemm8\.bin/.test(files.model.name || "") ? "int8shiftAll" : "int8shiftAlphaAll",
    alignment: "soft"
  };
  const indent = "            ";
  let yaml = "\n";
  for (const [key, value] of Object.entries(config)) {
    yaml += `${indent}${key}: ${value}
`;
  }
  yaml += indent;
  const [from, to] = direction.match(/zh-Han[st]|[a-z]{2,3}/g) || ["", ""];
  return new b.TranslationModel(from, to, yaml, memory.model, memory.lex || null, vocabs, null);
}
async function getFile(file, label, report) {
  const cache = self.caches ? await self.caches.open(CACHE_NAME).catch(() => null) : null;
  let bytes = null;
  if (cache) {
    const hit = await cache.match(file.url);
    if (hit) {
      bytes = new Uint8Array(await hit.arrayBuffer());
    }
  }
  if (!bytes) {
    bytes = await download(file, label, report);
    if (cache) {
      await cache.put(file.url, new Response(bytes, { headers: { "Content-Type": "application/octet-stream" } })).catch(() => {
      });
    }
  }
  const data = decompress(bytes);
  if (file.decompressed_hash) {
    const digest = new Uint8Array(await self.crypto.subtle.digest("SHA-256", data));
    const hash = Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join("");
    if (hash !== file.decompressed_hash) {
      if (cache) {
        await cache.delete(file.url);
      }
      throw new Error("A language model file is damaged, it will be downloaded again.");
    }
  }
  return data;
}
async function download(file, label, report) {
  const response = await fetch(file.url, { credentials: "same-origin" });
  if (!response.ok || !response.body) {
    throw new Error(`The ${label} file could not be downloaded (HTTP ${response.status}).`);
  }
  const total = file.size || Number(response.headers.get("Content-Length")) || 0;
  const reader = response.body.getReader();
  const chunks = [];
  let loaded = 0;
  let last = 0;
  for (; ; ) {
    const { done, value } = await reader.read();
    if (done) {
      break;
    }
    chunks.push(value);
    loaded += value.length;
    if (Date.now() - last > 250) {
      last = Date.now();
      report("downloading", { label, loaded, total });
    }
  }
  const out = new Uint8Array(loaded);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.length;
  }
  return out;
}
async function cleanCache(manifest) {
  if (!self.caches) {
    return;
  }
  try {
    const cache = await self.caches.open(CACHE_NAME);
    const urls = /* @__PURE__ */ new Set();
    if (manifest.wasm) {
      urls.add(manifest.wasm.url);
    }
    for (const entry of Object.values(manifest.directions || {})) {
      for (const file of Object.values(entry.files)) {
        urls.add(file.url);
      }
    }
    for (const key of await cache.keys()) {
      if (!urls.has(key.url)) {
        await cache.delete(key);
      }
    }
  } catch (e) {
  }
}

// modules/machine-translation/js/mt-worker.js
var words = 0;
serve({
  runners: {
    "translate-post": {
      supports: () => "object" === typeof WebAssembly && "function" === typeof self.importScripts,
      prepare: (env) => createEngine(env),
      async runBatch({ units, engine, report, progress }) {
        const outcomes = new Array(units.length);
        const byRoute = /* @__PURE__ */ new Map();
        units.forEach((unit, index) => {
          const route = String(unit.input.route || "");
          if (!byRoute.has(route)) {
            byRoute.set(route, []);
          }
          byRoute.get(route).push(index);
        });
        let done = 0;
        for (const [route, indexes] of byRoute) {
          const items = indexes.map((index) => ({ text: String(units[index].input.text || ""), html: !!units[index].input.html }));
          const started = performance.now();
          const results = await engine.translate(route, items);
          const seconds = Math.max(performance.now() - started, 1) / 1e3;
          const count = items.reduce((total, item) => total + countWords(item.text), 0);
          indexes.forEach((index, i) => {
            const result = results[i] || { error: "engine error" };
            outcomes[index] = "string" === typeof result.text ? { result: result.text } : { error: result.error || "engine error" };
          });
          words += count;
          done += indexes.length;
          report("translating", { route, wps: Math.round(count / seconds), words });
          progress(done / units.length);
        }
        return outcomes;
      }
    }
  }
});
