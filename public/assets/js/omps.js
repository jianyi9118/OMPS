/*
 * OMPS front-end core (no third-party libraries).
 * OMPS.t()  translation    OMPS.api()  JSON API with CSRF    OMPS.modal()/confirm()  dialogs
 * OMPS.form()  form builder    OMPS.table()  table with icon actions    OMPS.status()  status bar
 * All visible text comes from the language pack in #omps-boot (design §16).
 */
(function (w, d) {
  "use strict";

  var bootEl = d.getElementById("omps-boot");
  var BOOT = bootEl ? JSON.parse(bootEl.textContent || "{}") : {};
  var I18N = BOOT.i18n || {};
  var handlers = {};

  function esc(v) {
    if (v === null || v === undefined) return "";
    return String(v).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /* translation: {name} placeholders; a param {t: "key"} is itself translated */
  function t(key, params) {
    var s = Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : "⟦" + key + "⟧";
    if (params) {
      s = s.replace(/\{(\w+)\}/g, function (m, k) {
        if (!Object.prototype.hasOwnProperty.call(params, k)) return m;
        var v = params[k];
        if (Object.prototype.toString.call(v) === "[object Array]") return v.join(t("common.list_sep"));
        if (v && typeof v === "object" && v.t) return t(v.t);
        return v === null || v === undefined ? "" : String(v);
      });
    }
    return s;
  }

  function icon(name, size) {
    var s = size || 16;
    return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">' + ((w.OMPS_ICON || {})[name] || "") + "</svg>";
  }

  function fillIcons(root) {
    var list = (root || d).querySelectorAll("svg[data-icon]");
    for (var i = 0; i < list.length; i++) {
      var k = list[i].getAttribute("data-icon");
      list[i].innerHTML = (w.OMPS_ICON || {})[k] || "";
      list[i].removeAttribute("data-icon");
    }
  }

  function url(path, query) {
    path = "/" + String(path || "/").replace(/^\/+/, "");
    var qs = "";
    if (query) {
      var parts = [];
      Object.keys(query).forEach(function (k) {
        var v = query[k];
        if (v !== undefined && v !== null && v !== "") parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(v));
      });
      qs = parts.join("&");
    }
    if (BOOT.rewrite) return (BOOT.base || "") + path + (qs ? "?" + qs : "");
    return (BOOT.base || "") + "/index.php?r=" + encodeURIComponent(path) + (qs ? "&" + qs : "");
  }

  /* JSON API. Resolves with data; rejects with {status, code, message, fields}. */
  function api(method, path, body, query) {
    var opts = { method: method, credentials: "same-origin", headers: { "Accept": "application/json", "X-CSRF-Token": BOOT.csrf || "" } };
    if (body !== undefined && body !== null) {
      opts.headers["Content-Type"] = "application/json";
      opts.body = JSON.stringify(body);
    }
    return fetch(url(path, query), opts).then(function (res) {
      return res.text().then(function (txt) {
        var json = null;
        try { json = txt ? JSON.parse(txt) : null; } catch (e) { json = null; }
        if (res.ok && json && json.ok) return json.data;
        var err = { status: res.status, code: "server_error", message: t("error.server"), fields: null };
        if (json && json.error) {
          err.code = json.error.code;
          err.message = json.error.message;
          err.fields = json.error.fields || null;
        }
        if (res.status === 401 && BOOT.user) {
          w.location.href = url("/login");
        }
        throw err;
      });
    }, function () {
      throw { status: 0, code: "network", message: t("error.network"), fields: null };
    });
  }

  /* status bar message (falls back to a small toast on pages without a status bar) */
  var stTimer = null;
  function status(msg, kind) {
    var el = d.getElementById("stMsg");
    if (!el) {
      el = d.getElementById("toast");
      if (!el) {
        el = d.createElement("div");
        el.id = "toast";
        el.className = "toast";
        d.body.appendChild(el);
      }
    }
    el.textContent = msg;
    el.className = (el.id === "toast" ? "toast show " : "msg flash ") + (kind || "ok");
    clearTimeout(stTimer);
    stTimer = setTimeout(function () { el.classList.remove("flash"); if (el.id === "toast") el.classList.remove("show"); }, 4000);
  }

  /* A refused action (400 / 403 / 404 / 409 ...) is explained in a dialog, not only in the status bar,
     so the reason cannot be missed. Network and server errors go to the status bar. */
  function failed(err) {
    var msg = err && err.message ? err.message : t("error.server");
    status(msg, "err");
    if (err && err.status >= 400 && err.status < 500 && err.status !== 401 && err.status !== 422) {
      info(t("error.title_refused"), "<p>" + esc(msg) + "</p>");
    }
  }

  /* ---------- dialogs ---------- */
  var modalStack = [];
  function modal(opt) {
    var host = d.getElementById("mhost") || d.body;
    var wrap = d.createElement("div");
    var mask = d.createElement("div");
    mask.className = "mask m";
    var box = d.createElement("div");
    box.className = "modal";
    box.setAttribute("role", "dialog");
    box.setAttribute("aria-modal", "true");
    box.style.width = (opt.width || 520) + "px";
    box.innerHTML = '<div class="m-h"><span></span><button type="button" class="x" aria-label="' + esc(t("common.close")) + '">✕</button></div><div class="m-b"></div><div class="m-f"></div>';
    box.querySelector(".m-h span").textContent = opt.title || "";
    var b = box.querySelector(".m-b");
    if (typeof opt.body === "string") b.innerHTML = opt.body; else if (opt.body) b.appendChild(opt.body);
    var foot = box.querySelector(".m-f");
    var api_ = { el: box, body: b, close: close, busy: busy };
    (opt.buttons || [{ t: t("common.close") }]).forEach(function (btn) {
      var el = d.createElement("button");
      el.type = "button";
      el.className = "btn " + (btn.cls || "");
      el.textContent = btn.t;
      el.addEventListener("click", function () {
        if (!btn.fn) return close();
        var r = btn.fn(api_);
        if (r && typeof r.then === "function") {
          busy(true);
          r.then(function (v) { busy(false); if (v !== false) close(); }, function (e) { busy(false); if (e) failed(e); });
        } else if (r !== false) {
          close();
        }
      });
      foot.appendChild(el);
    });
    function busy(on) {
      var bs = foot.querySelectorAll("button");
      for (var i = 0; i < bs.length; i++) bs[i].disabled = !!on;
    }
    function close() {
      if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
      modalStack = modalStack.filter(function (m) { return m !== api_; });
      if (opt.onClose) opt.onClose();
    }
    mask.addEventListener("click", function () { if (!opt.sticky) close(); });
    box.querySelector(".x").addEventListener("click", close);
    wrap.appendChild(mask);
    wrap.appendChild(box);
    host.appendChild(wrap);
    modalStack.push(api_);
    var first = box.querySelector("input:not([type=hidden]):not([disabled]),select,textarea");
    if (first) first.focus();
    return api_;
  }

  function confirmBox(opt) {
    return new Promise(function (resolve) {
      var done = false;
      modal({
        title: opt.title, width: opt.width || 440, body: '<p>' + (opt.html || esc(opt.msg || "")) + "</p>",
        onClose: function () { if (!done) resolve(false); },
        buttons: [
          { t: t("common.cancel") },
          { t: opt.ok || t("common.ok"), cls: opt.danger ? "danger fill" : "pri", fn: function () { done = true; resolve(true); } }
        ]
      });
    });
  }

  function info(title, html) {
    return modal({ title: title, body: html, buttons: [{ t: t("common.ok"), cls: "pri" }] });
  }

  /* dropdown menu anchored to an element */
  function dropdown(anchor, items) {
    closeDropdown();
    var host = d.getElementById("ddhost") || d.body;
    var dd = d.createElement("div");
    dd.className = "dd";
    items.forEach(function (it) {
      if (it.sep) {
        var s = d.createElement("div");
        s.className = "sep";
        dd.appendChild(s);
        return;
      }
      var b = d.createElement("button");
      b.type = "button";
      b.className = "it" + (it.on ? " on" : "");
      b.innerHTML = (it.icon ? icon(it.icon) : "") + "<span></span>";
      b.querySelector("span").textContent = it.t;
      b.addEventListener("click", function () { closeDropdown(); it.fn(); });
      dd.appendChild(b);
    });
    host.appendChild(dd);
    var r = anchor.getBoundingClientRect();
    dd.style.top = (r.bottom + 4) + "px";
    var left = Math.min(r.right - dd.offsetWidth, w.innerWidth - dd.offsetWidth - 8);
    dd.style.left = Math.max(8, left) + "px";
    setTimeout(function () { d.addEventListener("click", closeDropdown, { once: true }); }, 0);
  }
  function closeDropdown() {
    var host = d.getElementById("ddhost");
    if (host) host.innerHTML = "";
  }

  /* ---------- forms ----------
   * fields: [{name, label, type: text|password|number|date|select|textarea|checkbox|radio|static|group,
   *           req, opts: [[value, label]], hint, max, span2, readonly, value}]
   */
  function form(fields, values) {
    values = values || {};
    var el = d.createElement("div");
    el.className = "fgrid";
    fields.forEach(function (f) {
      if (f.hide) return;
      if (f.type === "group") {
        var g = d.createElement("div");
        g.className = "gtitle";
        g.textContent = f.label;
        el.appendChild(g);
        return;
      }
      var wrap = d.createElement("div");
      wrap.className = "fld" + (f.span2 || f.type === "textarea" ? " span2" : "");
      wrap.setAttribute("data-f", f.name);
      var id = "f_" + f.name + "_" + Math.random().toString(36).slice(2, 7);
      var v = values[f.name] !== undefined && values[f.name] !== null ? values[f.name] : (f.value !== undefined ? f.value : "");
      var lab = '<label for="' + id + '">' + (f.req ? '<b class="req">*</b>' : "") + esc(f.label) + "</label>";
      var input = "";
      var dis = f.readonly ? " disabled" : "";
      if (f.type === "select") {
        input = '<select id="' + id + '" name="' + esc(f.name) + '"' + dis + ">" + (f.req || f.noEmpty ? "" : '<option value="">' + esc(f.empty || t("common.none")) + "</option>") +
          (f.opts || []).map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v) ? " selected" : "") + ">" + esc(o[1]) + "</option>"; }).join("") + "</select>";
      } else if (f.type === "textarea") {
        input = '<textarea id="' + id + '" name="' + esc(f.name) + '" rows="' + (f.rows || 3) + '"' + (f.max ? ' maxlength="' + f.max + '"' : "") + dis + ">" + esc(v) + "</textarea>";
      } else if (f.type === "checkbox") {
        lab = "";
        input = '<label class="opts"><input type="checkbox" id="' + id + '" name="' + esc(f.name) + '"' + (v ? " checked" : "") + dis + "> " + esc(f.label) + "</label>";
      } else if (f.type === "radio") {
        input = '<div class="opts">' + (f.opts || []).map(function (o, i) {
          return '<label><input type="radio" name="' + esc(f.name) + '" value="' + esc(o[0]) + '"' + (String(o[0]) === String(v) || (v === "" && i === 0 && f.req) ? " checked" : "") + dis + "> " + esc(o[1]) + "</label>";
        }).join("") + "</div>";
      } else if (f.type === "date") {
        input = '<input type="text" class="date" id="' + id + '" name="' + esc(f.name) + '" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="' + esc(t("common.date_format")) + '" value="' + esc(v) + '"' + dis + ">";
      } else if (f.type === "static") {
        input = '<div class="ro">' + (f.html !== undefined ? f.html : esc(v)) + "</div>";
      } else {
        input = '<input id="' + id + '" name="' + esc(f.name) + '" type="' + (f.type || "text") + '" value="' + esc(v) + '"' + (f.max ? ' maxlength="' + f.max + '"' : "") +
          (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : "") + (f.autocomplete ? ' autocomplete="' + esc(f.autocomplete) + '"' : "") + dis + ">";
      }
      wrap.innerHTML = lab + input + (f.hint ? '<span class="hint">' + esc(f.hint) + "</span>" : "") + '<span class="em"></span>';
      el.appendChild(wrap);
    });
    return {
      el: el,
      values: function () {
        var out = {};
        fields.forEach(function (f) {
          if (f.hide || f.type === "group" || f.type === "static" || f.readonly) return;
          if (f.type === "radio") {
            var c = el.querySelector('input[name="' + f.name + '"]:checked');
            out[f.name] = c ? c.value : "";
          } else {
            var inp = el.querySelector('[name="' + f.name + '"]');
            if (!inp) return;
            out[f.name] = f.type === "checkbox" ? inp.checked : inp.value.trim();
            if (f.int && out[f.name] !== "") out[f.name] = parseInt(out[f.name], 10);
            if (f.int && out[f.name] === "") out[f.name] = null;
          }
        });
        return out;
      },
      errors: function (map) {
        var all = el.querySelectorAll(".fld");
        for (var i = 0; i < all.length; i++) {
          all[i].classList.remove("err");
          all[i].querySelector(".em").textContent = "";
        }
        if (!map) return;
        Object.keys(map).forEach(function (k) {
          var f = el.querySelector('[data-f="' + k + '"]');
          if (f) {
            f.classList.add("err");
            f.querySelector(".em").textContent = map[k];
          }
        });
      }
    };
  }

  /* submit helper for modal forms: shows field errors, keeps the dialog open on failure */
  function submit(frm, promise) {
    frm.errors(null);
    return promise.then(function (v) { return v; }, function (err) {
      if (err && err.fields) {
        frm.errors(err.fields);
        status(err.message, "err");
        return Promise.reject(null);
      }
      return Promise.reject(err);
    });
  }

  /* ---------- tables ----------
   * cfg: {cols: [{t, f(row) -> html, cls}], rows, empty, acts(row) -> [{icon, tip, fn, danger, pri}] | null, slots}
   */
  function table(cfg) {
    var wrap = d.createElement("div");
    wrap.className = "tw";
    var head = cfg.cols.map(function (c) { return '<th class="' + (c.cls || "") + '">' + esc(c.t) + "</th>"; }).join("") + (cfg.acts ? '<th class="actc">' + esc(t("common.actions")) + "</th>" : "");
    var slots = cfg.slots || 0;
    var actLists = [];
    if (cfg.acts) {
      cfg.rows.forEach(function (r) {
        var a = cfg.acts(r);
        actLists.push(a);
        slots = Math.max(slots, a.length);
      });
    }
    var body = cfg.rows.length ? cfg.rows.map(function (r, i) {
      var tds = cfg.cols.map(function (c) { return '<td class="' + (c.cls || "") + '">' + (c.f ? c.f(r) : esc(r[c.k])) + "</td>"; }).join("");
      if (cfg.acts) {
        var a = actLists[i];
        var html = "";
        for (var s = 0; s < slots; s++) {
          var x = a[s];
          html += x ? '<button type="button" class="ib ' + (x.danger ? "danger " : "") + (x.pri ? "pri" : "") + '" data-row="' + i + '" data-act="' + s + '" title="' + esc(x.tip) + '" aria-label="' + esc(x.tip) + '">' + icon(x.icon) + "</button>"
            : '<span class="ib gap" aria-hidden="true"></span>';
        }
        tds += '<td class="actc"><div class="ibs">' + html + "</div></td>";
      }
      return "<tr>" + tds + "</tr>";
    }).join("") : '<tr class="empty"><td colspan="' + (cfg.cols.length + (cfg.acts ? 1 : 0)) + '">' + esc(cfg.empty || t("common.empty")) + "</td></tr>";
    wrap.innerHTML = '<table class="tbl"><thead><tr>' + head + "</tr></thead><tbody>" + body + "</tbody></table>";
    wrap.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button[data-act]") : null;
      if (!b) return;
      var row = cfg.rows[+b.getAttribute("data-row")];
      var act = actLists[+b.getAttribute("data-row")][+b.getAttribute("data-act")];
      if (act && act.fn) act.fn(row);
    });
    return wrap;
  }

  function pager(res, onPage) {
    var el = d.createElement("div");
    el.className = "pager";
    var pages = Math.max(1, Math.ceil(res.total / res.size));
    var html = esc(t("common.total", { n: res.total }));
    if (pages > 1) {
      var from = Math.max(1, res.page - 3), to = Math.min(pages, from + 6);
      if (res.page > 1) html += '<button type="button" data-p="' + (res.page - 1) + '">‹</button>';
      for (var p = from; p <= to; p++) html += '<button type="button" data-p="' + p + '"' + (p === res.page ? ' class="on"' : "") + ">" + p + "</button>";
      if (res.page < pages) html += '<button type="button" data-p="' + (res.page + 1) + '">›</button>';
    }
    el.innerHTML = html;
    el.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button[data-p]") : null;
      if (b) onPage(+b.getAttribute("data-p"));
    });
    return el;
  }

  function legend(items) {
    return '<div class="iblegend">' + items.map(function (it) { return "<span>" + icon(it[0], 14) + " " + esc(it[1]) + "</span>"; }).join("") + "</div>";
  }

  function pill(text, kind) {
    return '<span class="pill ' + (kind || "") + '">' + esc(text) + "</span>";
  }

  function has(perm) {
    return !!(BOOT.user && BOOT.user.perms && BOOT.user.perms.indexOf(perm) >= 0);
  }

  function debounce(fn, ms) {
    var tm = null;
    return function () {
      var args = arguments, self = this;
      clearTimeout(tm);
      tm = setTimeout(function () { fn.apply(self, args); }, ms || 300);
    };
  }

  /* ---------- date field: own picker, so no browser-language text appears (design §16) ---------- */
  function pad2(n) { return (n < 10 ? "0" : "") + n; }
  function ymd(dt) { return dt.getFullYear() + "-" + pad2(dt.getMonth() + 1) + "-" + pad2(dt.getDate()); }
  function today() { return ymd(new Date()); }
  function parseYmd(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ""));
    if (!m) return null;
    var dt = new Date(+m[1], +m[2] - 1, +m[3]);
    return dt.getMonth() === +m[2] - 1 ? dt : null;
  }
  var dpEl = null;
  function closeDatePicker() {
    if (dpEl && dpEl.parentNode) dpEl.parentNode.removeChild(dpEl);
    dpEl = null;
  }
  function openDatePicker(input) {
    closeDatePicker();
    var sel = parseYmd(input.value);
    var view = sel ? new Date(sel.getFullYear(), sel.getMonth(), 1) : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    dpEl = d.createElement("div");
    dpEl.className = "dp";
    dpEl.setAttribute("role", "dialog");
    function draw() {
      var y = view.getFullYear(), mo = view.getMonth();
      var first = (new Date(y, mo, 1).getDay() + 6) % 7;
      var start = new Date(y, mo, 1 - first);
      var html = '<div class="dp-h"><button type="button" data-n="-12">«</button><button type="button" data-n="-1">‹</button><b>' +
        esc(t("common.year_month", { y: y, m: mo + 1 })) + '</b><button type="button" data-n="1">›</button><button type="button" data-n="12">»</button></div><div class="dp-g">';
      for (var w = 1; w <= 7; w++) html += "<span>" + esc(t("common.wd" + (w % 7))) + "</span>";
      for (var i = 0; i < 42; i++) {
        var c = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        var v = ymd(c);
        html += '<button type="button" data-d="' + v + '" class="' + (c.getMonth() !== mo ? "o " : "") + (v === today() ? "t " : "") + (v === input.value ? "on" : "") + '">' + c.getDate() + "</button>";
      }
      html += '</div><div class="dp-f"><button type="button" class="btn link" data-d="' + today() + '">' + esc(t("common.today")) + '</button><button type="button" class="btn link" data-d="">' + esc(t("common.clear")) + "</button></div>";
      dpEl.innerHTML = html;
    }
    draw();
    dpEl.addEventListener("mousedown", function (e) { e.preventDefault(); });
    dpEl.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-n")) {
        view = new Date(view.getFullYear(), view.getMonth() + (+b.getAttribute("data-n")), 1);
        draw();
        return;
      }
      input.value = b.getAttribute("data-d");
      input.dispatchEvent(new Event("change", { bubbles: true }));
      closeDatePicker();
    });
    d.body.appendChild(dpEl);
    var r = input.getBoundingClientRect();
    var top = r.bottom + 4;
    if (top + dpEl.offsetHeight > w.innerHeight - 8) top = Math.max(8, r.top - dpEl.offsetHeight - 4);
    dpEl.style.top = top + "px";
    dpEl.style.left = Math.max(8, Math.min(r.left, w.innerWidth - dpEl.offsetWidth - 8)) + "px";
  }
  d.addEventListener("focusin", function (e) {
    if (e.target && e.target.classList && e.target.classList.contains("date") && e.target.tagName === "INPUT") openDatePicker(e.target);
  });
  d.addEventListener("focusout", function (e) {
    if (e.target && e.target.classList && e.target.classList.contains("date")) setTimeout(closeDatePicker, 150);
  });
  function dateInput(id, value, extra) {
    return '<input type="text" class="date" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="' + esc(t("common.date_format")) + '"' +
      (id ? ' id="' + esc(id) + '"' : "") + ' value="' + esc(value || "") + '"' + (extra || "") + ">";
  }

  /* ---------- side drawer with tabs ----------
   * opt: {title, sub, width, tabs: [{t, el}], buttons: [{t, cls, fn(api)}], foot}
   */
  var drawerApi = null;
  function drawer(opt) {
    closeDrawer();
    var host = d.getElementById("dhost") || d.body;
    var wrap = d.createElement("div");
    wrap.innerHTML = '<div class="mask"></div><aside class="drawer" role="dialog" aria-modal="true"><div class="d-h"><span class="dt"></span> <span class="muted ds" style="font-weight:400;font-size:13px"></span><button type="button" class="x" aria-label="' +
      esc(t("common.close")) + '">✕</button></div><div class="dtabs"></div><div class="d-b"></div><div class="d-f"><span class="l"></span></div></aside>';
    var box = wrap.querySelector(".drawer");
    box.style.width = Math.min(opt.width || 760, w.innerWidth) + "px";
    box.querySelector(".dt").textContent = opt.title || "";
    box.querySelector(".ds").textContent = opt.sub || "";
    if (opt.foot) box.querySelector(".d-f .l").textContent = opt.foot;
    var tabsEl = box.querySelector(".dtabs"), bodyEl = box.querySelector(".d-b"), footEl = box.querySelector(".d-f");
    var tabs = opt.tabs || [];
    if (tabs.length < 2) tabsEl.style.display = "none";
    tabs.forEach(function (tb, i) {
      var b = d.createElement("button");
      b.type = "button";
      b.textContent = tb.t;
      b.className = i ? "" : "on";
      b.addEventListener("click", function () { show(i); });
      tabsEl.appendChild(b);
      var pane = d.createElement("div");
      pane.hidden = i !== 0;
      pane.appendChild(tb.el);
      bodyEl.appendChild(pane);
    });
    function show(i) {
      var bs = tabsEl.querySelectorAll("button"), ps = bodyEl.children;
      for (var k = 0; k < ps.length; k++) { ps[k].hidden = k !== i; if (bs[k]) bs[k].classList.toggle("on", k === i); }
    }
    function busy(on) {
      var bs = footEl.querySelectorAll("button");
      for (var i = 0; i < bs.length; i++) bs[i].disabled = !!on;
    }
    var api_ = { el: box, close: closeDrawer, show: show, busy: busy, markTab: function (i, bad) { var b = tabsEl.querySelectorAll("button")[i]; if (b) b.classList.toggle("has-err", !!bad); } };
    (opt.buttons || []).forEach(function (btn) {
      var el = d.createElement("button");
      el.type = "button";
      el.className = "btn " + (btn.cls || "");
      el.textContent = btn.t;
      el.addEventListener("click", function () {
        if (!btn.fn) return closeDrawer();
        var r = btn.fn(api_);
        if (r && typeof r.then === "function") {
          busy(true);
          r.then(function (v) { busy(false); if (v !== false) closeDrawer(); }, function (e) { busy(false); if (e) failed(e); });
        } else if (r !== false) closeDrawer();
      });
      footEl.appendChild(el);
    });
    wrap.querySelector(".mask").addEventListener("click", closeDrawer);
    box.querySelector(".x").addEventListener("click", closeDrawer);
    host.appendChild(wrap);
    fillIcons(wrap);
    drawerApi = { wrap: wrap, onClose: opt.onClose };
    return api_;
  }
  function closeDrawer() {
    if (!drawerApi) return;
    var cb = drawerApi.onClose;
    if (drawerApi.wrap.parentNode) drawerApi.wrap.parentNode.removeChild(drawerApi.wrap);
    drawerApi = null;
    if (cb) cb();
  }

  /* ---------- editable table for repeatable records ----------
   * cfg: {cols: [{k, t, type: text|ym|date|select|bool, opts, max, req}], rows, readonly, empty, addText}
   */
  function repeat(cfg) {
    var rows = (cfg.rows || []).map(function (r) { return JSON.parse(JSON.stringify(r)); });
    var el = d.createElement("div");
    function cell(c, r, i) {
      var v = r[c.k] === undefined || r[c.k] === null ? "" : r[c.k];
      if (cfg.readonly) {
        if (c.type === "bool") return esc(v ? t("common.yes") : t("common.no"));
        if (c.type === "select") { var o = (c.opts || []).filter(function (x) { return String(x[0]) === String(v); })[0]; return esc(o ? o[1] : v); }
        return esc(v === "" ? "—" : v);
      }
      var a = ' data-i="' + i + '" data-k="' + esc(c.k) + '"';
      if (c.type === "bool") return '<input type="checkbox"' + a + (v ? " checked" : "") + ">";
      if (c.type === "select") return "<select" + a + '><option value=""></option>' + (c.opts || []).map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v) ? " selected" : "") + ">" + esc(o[1]) + "</option>"; }).join("") + "</select>";
      if (c.type === "date") return '<input type="text" class="date" inputmode="numeric" maxlength="10" placeholder="' + esc(t("common.date_format")) + '"' + a + ' value="' + esc(v) + '">';
      if (c.type === "ym") return '<input type="text" inputmode="numeric" maxlength="7" placeholder="' + esc(t("common.ym_format")) + '"' + a + ' value="' + esc(v) + '" style="min-width:90px">';
      return '<input type="text"' + a + ' value="' + esc(v) + '"' + (c.max ? ' maxlength="' + c.max + '"' : "") + ">";
    }
    function draw() {
      var head = cfg.cols.map(function (c) { return "<th>" + (c.req && !cfg.readonly ? '<b class="req">*</b>' : "") + esc(c.t) + "</th>"; }).join("") + (cfg.readonly ? "" : "<th></th>");
      var body = rows.length ? rows.map(function (r, i) {
        return "<tr>" + cfg.cols.map(function (c) { return "<td>" + cell(c, r, i) + "</td>"; }).join("") +
          (cfg.readonly ? "" : '<td class="actc"><button type="button" class="ib danger" data-del="' + i + '" title="' + esc(t("common.delete")) + '">' + icon("trash") + "</button></td>") + "</tr>";
      }).join("") : '<tr class="empty"><td colspan="' + (cfg.cols.length + 1) + '">' + esc(cfg.empty || t("common.empty")) + "</td></tr>";
      el.innerHTML = '<div class="tw"><table class="tbl sm"><thead><tr>' + head + "</tr></thead><tbody>" + body + "</tbody></table></div>" +
        (cfg.readonly ? "" : '<div style="margin-top:8px"><button type="button" class="btn sm" data-add="1">' + icon("plus", 14) + " " + esc(cfg.addText || t("common.add_row")) + "</button></div>");
    }
    function sync() {
      var ins = el.querySelectorAll("[data-k]");
      for (var j = 0; j < ins.length; j++) {
        var i = +ins[j].getAttribute("data-i"), k = ins[j].getAttribute("data-k");
        rows[i][k] = ins[j].type === "checkbox" ? ins[j].checked : ins[j].value.trim();
      }
    }
    el.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button") : null;
      if (!b) return;
      if (b.hasAttribute("data-add")) { sync(); var n = {}; cfg.cols.forEach(function (c) { n[c.k] = c.type === "bool" ? true : ""; }); rows.push(n); draw(); }
      if (b.hasAttribute("data-del")) { sync(); rows.splice(+b.getAttribute("data-del"), 1); draw(); }
    });
    draw();
    return { el: el, values: function () { if (!cfg.readonly) sync(); return rows; } };
  }

  /* ---------- uploads (own button: the browser's file button shows browser-language text) ---------- */
  function pickFiles(opt, cb) {
    var inp = d.createElement("input");
    inp.type = "file";
    if (opt.multiple) inp.multiple = true;
    if (opt.accept) inp.accept = opt.accept;
    inp.style.display = "none";
    inp.addEventListener("change", function () { var list = Array.prototype.slice.call(inp.files || []); if (inp.parentNode) inp.parentNode.removeChild(inp); if (list.length) cb(list); });
    d.body.appendChild(inp);
    inp.click();
  }
  function upload(path, files, fields) {
    var fd = new FormData();
    files.forEach(function (f) { fd.append("file[]", f, f.name); });
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url(path), { method: "POST", credentials: "same-origin", headers: { "Accept": "application/json", "X-CSRF-Token": BOOT.csrf || "" }, body: fd }).then(function (res) {
      return res.text().then(function (txt) {
        var json = null;
        try { json = txt ? JSON.parse(txt) : null; } catch (e) { json = null; }
        if (res.ok && json && json.ok) return json.data;
        var err = { status: res.status, code: "server_error", message: res.status === 413 ? t("file.err.too_big_any") : t("error.server"), fields: null };
        if (json && json.error) { err.code = json.error.code; err.message = json.error.message; err.fields = json.error.fields || null; }
        throw err;
      });
    });
  }
  /* Word template choices for a document type (built-in layout and uploaded templates, default first) */
  function docChoices(type) {
    return api("GET", "/api/v1/doc-templates/choices", null, { type: type }).then(function (r) { return r.choices; });
  }
  function fileSize(n) {
    if (n < 1024) return n + " B";
    if (n < 1048576) return (n / 1024).toFixed(0) + " KB";
    return (n / 1048576).toFixed(1) + " MB";
  }

  /* department options with indentation for selects */
  function deptOpts(list) {
    return (list || []).map(function (x) { return [x.id, new Array((x.depth || 0) + 1).join("   ") + x.name]; });
  }

  /* ---------- shell: menu, language, user menu ---------- */
  function on(name, fn) { handlers[name] = fn; }

  d.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-a]") : null;
    if (!el) return;
    var fn = handlers[el.getAttribute("data-a")];
    if (fn) {
      e.preventDefault();
      fn(el, e);
    }
  });

  on("mgrp", function (el) {
    var app = d.getElementById("app");
    if (app && app.classList.contains("collapsed") && w.innerWidth > 860) {
      w.location.href = el.getAttribute("data-href");
      return;
    }
    var kids = el.nextElementSibling;
    var open = el.classList.toggle("open");
    el.setAttribute("aria-expanded", open ? "true" : "false");
    if (kids) kids.hidden = !open;
  });
  on("collapse", function () {
    var app = d.getElementById("app");
    var c = app.classList.toggle("collapsed");
    try { w.localStorage.setItem("omps.nav", c ? "1" : "0"); } catch (e) { /* storage unavailable */ }
  });
  on("mnav", function () { d.getElementById("app").classList.toggle("mnav"); });

  function setLang(code) {
    api("POST", "/api/v1/lang", { lang: code }).then(function () { w.location.reload(); }, failed);
  }
  on("langMenu", function (el) {
    var items = [];
    var def = (BOOT.languages || []).filter(function (l) { return l.code === BOOT.defaultLang; })[0];
    if (BOOT.user) {
      items.push({ t: t("layout.lang_auto", { name: def ? def.name : BOOT.defaultLang }), on: !BOOT.userLang, fn: function () { setLang("auto"); } });
      items.push({ sep: true });
    }
    (BOOT.languages || []).forEach(function (l) {
      items.push({ t: l.name, on: BOOT.user ? BOOT.userLang === l.code : BOOT.lang === l.code, fn: function () { setLang(l.code); } });
    });
    dropdown(el, items);
  });
  /* own light / dark choice (theme) */
  on("themeMenu", function (el) {
    var cur = BOOT.theme || "auto";
    dropdown(el, ["auto", "light", "dark"].map(function (m) {
      return { t: t("theme.mode." + m), on: cur === m, fn: function () {
        api("POST", "/api/v1/me/theme", { mode: m }).then(function () {
          BOOT.theme = m;
          if (m === "auto") d.documentElement.removeAttribute("data-theme"); else d.documentElement.setAttribute("data-theme", m);
        }, failed);
      } };
    }));
  });
  on("userMenu", function (el) {
    dropdown(el, [
      { t: t("menu.profile"), icon: "user", fn: function () { w.location.href = url("/me/profile"); } },
      { t: t("pref.title"), icon: "bell", fn: prefsDialog },
      { t: t("auth.change_password"), icon: "key", fn: function () { w.location.href = url("/password"); } },
      { sep: true },
      { t: t("auth.sign_out"), icon: "logout", fn: function () {
        api("POST", "/api/v1/auth/logout", {}).then(function (r) { w.location.href = r.redirect; }, failed);
      } }
    ]);
  });

  d.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      closeDropdown();
      if (dpEl) { closeDatePicker(); return; }
      if (modalStack.length) { modalStack[modalStack.length - 1].close(); return; }
      closeDrawer();
    }
  });



  /* ---------- custom fields (phase 7): form fields, detail rows and list cells from definitions ---------- */
  var cf = {
    text: function (d, v) {
      if (v === null || v === undefined || v === "") return "";
      if (d.type === "select") { var o = d.options.filter(function (x) { return x.key === String(v); })[0]; return o ? o.label : String(v); }
      if (d.type === "bool") return t(v === true || v === 1 || v === "1" ? "common.yes" : "common.no");
      return String(v);
    },
    /* O.form field list; readonly shows the values only */
    fields: function (defs, readonly) {
      if (!defs || !defs.length) return [];
      var out = [{ type: "group", label: t("cf.group") }];
      defs.forEach(function (d) {
        var f = { name: "cf__" + d.key, label: d.label, req: d.required && !readonly };
        if (readonly) { f.type = "static"; f.span2 = d.type === "textarea"; out.push(f); return; }
        if (d.type === "textarea") { f.type = "textarea"; f.max = 20000; }
        else if (d.type === "date") f.type = "date";
        else if (d.type === "select") { f.type = "select"; f.opts = d.options.filter(function (o) { return o.enabled; }).map(function (o) { return [o.key, o.label]; }); }
        else if (d.type === "bool") { f.type = "select"; f.opts = [["1", t("common.yes")], ["0", t("common.no")]]; }
        else if (d.type === "text") f.max = 500;
        else f.max = 20;
        out.push(f);
      });
      return out;
    },
    /* initial values for the form ({cf__key: value}) */
    values: function (defs, vals, readonly) {
      var out = {};
      (defs || []).forEach(function (d) {
        var v = vals ? vals[d.key] : null;
        if (readonly) { out["cf__" + d.key] = cf.text(d, v) || "—"; return; }
        if (v === null || v === undefined) return;
        out["cf__" + d.key] = d.type === "bool" ? (v ? "1" : "0") : String(v);
      });
      return out;
    },
    /* {key: value} from form values; removes the cf__ entries */
    collect: function (formValues) {
      var out = {};
      Object.keys(formValues).forEach(function (k) {
        if (k.indexOf("cf__") === 0) { out[k.slice(4)] = formValues[k]; delete formValues[k]; }
      });
      return out;
    },
    rows: function (defs, vals) {
      return (defs || []).map(function (d) { return [d.label, esc(cf.text(d, vals ? vals[d.key] : null)) || "—", d.type === "textarea"]; });
    },
    /* table columns for fields shown in lists (rows carry .custom) */
    cols: function (defs) {
      return (defs || []).map(function (d) {
        return { t: d.label, cls: d.type === "textarea" || d.type === "text" ? "wrap" : (d.type === "int" || d.type === "decimal" ? "num" : ""),
          f: function (r) { return esc(cf.text(d, r.custom ? r.custom[d.key] : null)) || '<span class="muted">—</span>'; } };
      });
    }
  };

  /* ---------- my affairs: bell, menu counts, pop-up, urgent bar (design §13.1, §17) ----------
   * Every page asks the server once a minute (plain AJAX). Text is rendered here from the message
   * type + params, so it follows the language. */
  var baseTitle = d.title;
  var pulseData = null;
  var pulseHooks = {};
  function store(k, v) {
    try { if (v === undefined) return w.localStorage.getItem(k); w.localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ }
    return null;
  }
  function notifyText(n) { return t("notify.msg." + n.type, n.params || {}); }
  /* a stored link "/path?a=1" → URL for this installation */
  function go(link) {
    if (!link) return;
    var i = link.indexOf("?"), q = {};
    if (i >= 0) new URLSearchParams(link.slice(i + 1)).forEach(function (v, k) { q[k] = v; });
    w.location.href = url(i >= 0 ? link.slice(0, i) : link, q);
  }
  function ago(at) {
    var s = Math.max(0, (Date.now() - new Date(String(at).replace(" ", "T")).getTime()) / 1000);
    if (s < 60) return t("notify.just_now");
    if (s < 3600) return t("notify.min_ago", { n: Math.floor(s / 60) });
    if (s < 86400) return t("notify.hour_ago", { n: Math.floor(s / 3600) });
    return String(at).slice(5, 16);
  }
  function setBadge(code, n) {
    var els = d.querySelectorAll('[data-badge="' + code + '"]');
    for (var i = 0; i < els.length; i++) { els[i].textContent = n > 99 ? "99+" : String(n); els[i].hidden = !n; }
  }
  function applyPulse(p) {
    pulseData = p;
    var bn = d.getElementById("bellN");
    if (bn) bn.textContent = p.unread ? (p.unread > 99 ? "99+" : String(p.unread)) : "";
    var c = p.counts || {};
    setBadge("aff-todo", c.todo || 0);
    setBadge("aff-issues", c.issues || 0);
    setBadge("aff-notes", c.notes || 0);
    setBadge("aff-notify", c.notify || 0);
    setBadge("g-aff", (c.todo || 0) + (c.notify || 0));
    d.title = (p.unread ? "(" + p.unread + ") " : "") + baseTitle;
    var last = +(store("omps.lastNote") || 0);
    if (p.latest && p.latest.id > last) {
      store("omps.lastNote", String(p.latest.id));
      if (last && p.popup) popNote(p.latest);
    }
    drawUrge(p.urgent || []);
    Object.keys(pulseHooks).forEach(function (k) { pulseHooks[k](p); });
  }
  function pulse() {
    if (!BOOT.user) return;
    api("GET", "/api/v1/me/pulse").then(applyPulse, function () { /* try again next minute */ });
  }
  function popNote(n) {
    var el = d.createElement("button");
    el.type = "button";
    el.className = "ntoast";
    el.innerHTML = icon("bell", 16) + "<span></span>";
    el.querySelector("span").textContent = notifyText(n);
    el.addEventListener("click", function () { api("POST", "/api/v1/notifications/" + n.id + "/read", {}).then(function (r) { go(r.link); }, failed); });
    d.body.appendChild(el);
    setTimeout(function () { el.classList.add("show"); }, 20);
    setTimeout(function () { el.classList.remove("show"); setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 300); }, 5000);
  }
  /* time left until a deadline, or how long it is overdue */
  function left(due) {
    var ms = new Date(String(due).replace(" ", "T")).getTime() - Date.now();
    if (ms <= 0) return t("issue.overdue_days", { n: Math.max(1, Math.ceil(-ms / 86400000)) });
    if (ms < 3600000) return t("issue.left_min", { n: Math.max(1, Math.round(ms / 60000)) });
    if (ms < 86400000 * 2) return t("issue.left_hour", { n: Math.round(ms / 3600000) });
    return t("issue.left_day", { n: Math.floor(ms / 86400000) });
  }
  function drawUrge(list) {
    var host = d.getElementById("urgeHost");
    if (!host) return;
    var now = Date.now();
    var show = list.filter(function (x) { return +(store("omps.snooze." + x.id) || 0) < now; });
    if (!show.length) { host.innerHTML = ""; return; }
    var first = show[0];
    host.innerHTML = '<div class="urge" role="alert">' + icon("alert", 18) + '<span class="grow"><b>' + esc(t("issue.urge_title", { n: show.length })) + "</b> " +
      show.slice(0, 3).map(function (x) { return esc(t("common.quoted", { s: x.title })) + '<span class="ud">' + esc(x.overdue ? left(x.due) : t("issue.due_at", { due: x.due }) + " · " + left(x.due)) + "</span>"; }).join(" ") +
      '</span><button type="button" class="btn sm pri" data-urge="go">' + esc(t("issue.urge_go")) + '</button><button type="button" class="btn sm" data-urge="later">' + esc(t("issue.urge_later")) + "</button></div>";
    host.querySelector('[data-urge="go"]').addEventListener("click", function () { go("/affairs/issues?id=" + first.id); });
    host.querySelector('[data-urge="later"]').addEventListener("click", function () {
      show.forEach(function (x) { store("omps.snooze." + x.id, String(Date.now() + 3600000)); });
      host.innerHTML = "";
    });
  }
  on("bell", function (el) {
    api("GET", "/api/v1/notifications", null, { size: 8 }).then(function (res) {
      closeDropdown();
      var host = d.getElementById("ddhost") || d.body;
      var dd = d.createElement("div");
      dd.className = "dd bellp";
      dd.innerHTML = '<div class="dd-h"><b>' + esc(t("notify.bell")) + '</b><span class="r">' +
        (res.unread ? '<button type="button" class="btn link" data-b="all">' + esc(t("notify.read_all")) + "</button>" : "") + "</span></div>" +
        '<div class="nl"><ul class="list">' + (res.rows.length ? res.rows.map(function (n, i) {
          return '<li data-i="' + i + '" class="' + (n.read ? "" : "unread") + '"><span class="grow"><b>' + esc(notifyText(n)) + '</b><div class="sub">' + esc(ago(n.at)) + "</div></span></li>";
        }).join("") : '<li><span class="grow muted">' + esc(t("notify.none")) + "</span></li>") + "</ul></div>" +
        '<div class="sep"></div><button type="button" class="it" data-b="more"><span>' + esc(t("notify.view_all")) + "</span></button>";
      dd.addEventListener("click", function (e) {
        e.stopPropagation();
        var li = e.target.closest ? e.target.closest("li[data-i]") : null;
        var b = e.target.closest ? e.target.closest("[data-b]") : null;
        if (li) {
          var n = res.rows[+li.getAttribute("data-i")];
          api("POST", "/api/v1/notifications/" + n.id + "/read", {}).then(function (r) { closeDropdown(); if (r.link) go(r.link); else pulse(); }, failed);
        } else if (b && b.getAttribute("data-b") === "all") {
          api("POST", "/api/v1/notifications/read-all", {}).then(function () { closeDropdown(); pulse(); }, failed);
        } else if (b) {
          go("/affairs/notifications");
        }
      });
      host.appendChild(dd);
      var r = el.getBoundingClientRect();
      dd.style.top = (r.bottom + 4) + "px";
      dd.style.left = Math.max(8, Math.min(r.right - dd.offsetWidth, w.innerWidth - dd.offsetWidth - 8)) + "px";
      setTimeout(function () { d.addEventListener("click", closeDropdown, { once: true }); }, 0);
    }, failed);
  });

  /* jump to a record (design §17 跳转定位): scroll it into view, highlight it, show a bar on top */
  function focusBar(text) {
    var host = d.getElementById("page");
    if (!host) return;
    var old = d.getElementById("fbar");
    if (old) old.parentNode.removeChild(old);
    var bar = d.createElement("div");
    bar.className = "fbar";
    bar.id = "fbar";
    bar.innerHTML = icon("go", 14) + '<span class="grow"></span><button type="button" class="btn link">' + esc(t("common.close")) + "</button>";
    bar.querySelector(".grow").textContent = t("notify.located", { what: text });
    bar.querySelector("button").addEventListener("click", function () { bar.parentNode.removeChild(bar); });
    var urge = d.getElementById("urgeHost");
    host.insertBefore(bar, urge ? urge.nextSibling : host.firstChild);
  }
  function flash(el) {
    if (!el) return;
    el.classList.add("fhl", "fpulse");
    if (el.scrollIntoView) el.scrollIntoView({ block: "center", behavior: "smooth" });
    setTimeout(function () { el.classList.remove("fpulse"); }, 4500);
  }
  function qs(k) { return new URLSearchParams(w.location.search).get(k) || ""; }

  /* personal reminder preferences (user menu) */
  function prefsDialog() {
    api("GET", "/api/v1/me/prefs").then(function (p) {
      var hours = [];
      for (var m = 0; m < 1440; m += 30) hours.push([pad2(Math.floor(m / 60)) + ":" + pad2(m % 60), pad2(Math.floor(m / 60)) + ":" + pad2(m % 60)]);
      var f = form([
        { name: "popup", type: "checkbox", label: t("pref.popup"), span2: true },
        { name: "quiet_on", type: "checkbox", label: t("pref.quiet_on"), span2: true },
        { name: "quiet_from", label: t("pref.quiet_from"), type: "select", opts: hours, noEmpty: true },
        { name: "quiet_to", label: t("pref.quiet_to"), type: "select", opts: hours, noEmpty: true }
      ], { popup: p.popup === "1", quiet_on: p.quiet_on === "1", quiet_from: p.quiet_from, quiet_to: p.quiet_to });
      var box = d.createElement("div");
      box.appendChild(f.el);
      box.insertAdjacentHTML("beforeend", '<p class="muted" style="font-size:12.5px;margin:10px 0 0">' + esc(t("pref.hint")) + "</p>");
      modal({ title: t("pref.title"), width: 480, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        return submit(f, api("PUT", "/api/v1/me/prefs", f.values())).then(function () { status(t("common.saved")); });
      } }] });
    }, failed);
  }

  try {
    if (w.localStorage.getItem("omps.nav") === "1" && d.getElementById("app")) d.getElementById("app").classList.add("collapsed");
  } catch (e) { /* storage unavailable */ }
  fillIcons(d);
  if (BOOT.user && d.getElementById("bellBtn")) {
    pulse();
    setInterval(pulse, 60000);
  }

  w.OMPS = {
    boot: BOOT, t: t, esc: esc, icon: icon, url: url, api: api, status: status, failed: failed, modal: modal, confirm: confirmBox, info: info,
    dropdown: dropdown, form: form, submit: submit, table: table, pager: pager, legend: legend, pill: pill, has: has, debounce: debounce,
    deptOpts: deptOpts, on: on, fillIcons: fillIcons, today: today, dateInput: dateInput,
    drawer: drawer, closeDrawer: closeDrawer, repeat: repeat, pickFiles: pickFiles, upload: upload, fileSize: fileSize, docChoices: docChoices,
    notifyText: notifyText, go: go, ago: ago, pulse: pulse, onPulse: function (k, fn) { pulseHooks[k] = fn; if (pulseData) fn(pulseData); },
    focusBar: focusBar, flash: flash, qs: qs, left: left, cf: cf
  };
})(window, document);
