/*
 * Plan helpers shared by "My tasks" and "Plans & results" (phase 3):
 * time labels, the create / edit form, the details drawer, delete.
 * OMPS.plan.label(p) / .openForm(opts) / .openDetail(id, opts) / .remove(p, done)
 */
(function () {
  "use strict";
  var O = window.OMPS, t = O.t;
  var META = null;
  var LEVELS = ["long", "annual", "quarter", "month", "week", "day"];

  function meta() {
    if (META) return Promise.resolve(META);
    return O.api("GET", "/api/v1/plans/meta").then(function (m) { META = m; return m; });
  }

  /* ---------- dates and time labels ---------- */
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function d(s) { var p = String(s).split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function ymd(x) { return x.getFullYear() + "-" + pad(x.getMonth() + 1) + "-" + pad(x.getDate()); }
  function lastDay(y, m) { return new Date(y, m, 0).getDate(); }
  function isoWeek(s) {
    var x = d(s), u = new Date(Date.UTC(x.getFullYear(), x.getMonth(), x.getDate()));
    u.setUTCDate(u.getUTCDate() + 4 - (u.getUTCDay() || 7));
    var y0 = new Date(Date.UTC(u.getUTCFullYear(), 0, 1));
    return { year: u.getUTCFullYear(), week: Math.ceil(((u - y0) / 86400000 + 1) / 7) };
  }
  function today() { return META ? META.today : ymd(new Date()); }

  function exact(level, s, e) {
    if (!s || !e) return false;
    var y = +s.slice(0, 4), m = +s.slice(5, 7);
    switch (level) {
      case "long": return s.slice(5) === "01-01" && e.slice(5) === "12-31";
      case "annual": return s === y + "-01-01" && e === y + "-12-31";
      case "quarter": return [1, 4, 7, 10].indexOf(m) >= 0 && s.slice(8) === "01" && e === y + "-" + pad(m + 2) + "-" + pad(lastDay(y, m + 2));
      case "month": return s.slice(8) === "01" && e === y + "-" + pad(m) + "-" + pad(lastDay(y, m));
      case "week": return d(s).getDay() === 1 && Math.round((d(e) - d(s)) / 86400000) === 6;
      default: return s === e;
    }
  }
  /* the level follows from the dates (same rule as PlanService::levelOf) */
  function levelOf(s, e) {
    if (s === e) return "day";
    var a = isoWeek(s), b = isoWeek(e);
    if (a.year === b.year && a.week === b.week) return "week";
    if (s.slice(0, 7) === e.slice(0, 7)) return "month";
    if (s.slice(0, 4) === e.slice(0, 4)) return Math.ceil(+s.slice(5, 7) / 3) === Math.ceil(+e.slice(5, 7) / 3) ? "quarter" : "annual";
    return "long";
  }
  function autoLabel(level, s, e) {
    var ms = +s.slice(5, 7), me = +e.slice(5, 7);
    if (!exact(level, s, e) && s.slice(0, 4) === e.slice(0, 4) && s.slice(8) === "01" && e.slice(8) === pad(lastDay(+e.slice(0, 4), me)) && ms < me) {
      var mm = t("plan.tl.months", { a: ms, b: me });
      return s.slice(0, 4) === today().slice(0, 4) ? mm : t("plan.tl.year", { y: s.slice(0, 4) }) + " " + mm;
    }
    if (exact(level, s, e)) {
      if (level === "long") return s.slice(0, 4) + "–" + e.slice(0, 4);
      if (level === "annual") return t("plan.tl.year", { y: s.slice(0, 4) });
      if (level === "quarter") return t("plan.tl.q" + Math.ceil(+s.slice(5, 7) / 3));
      if (level === "month") return t("plan.tl.m", { m: +s.slice(5, 7) });
      if (level === "week") return t("plan.tl.w", { w: isoWeek(s).week });
      return s === today() ? t("plan.tl.today") : s.slice(5);
    }
    var y = today().slice(0, 4);
    if (s.slice(0, 4) === y && e.slice(0, 4) === y) return s.slice(5) + " – " + e.slice(5);
    return s + " – " + e;
  }
  /* shortcuts that fit the dates; stored as codes so they follow the language and the dates */
  function shortcuts(s, e) {
    if (!s || !e || e < s) return [];
    var ys = s.slice(0, 4), ye = e.slice(0, 4), ms = +s.slice(5, 7), me = +e.slice(5, 7), out = [];
    if (ys !== ye) return [["span", ys + "–" + ye]];
    out.push(["year", t("plan.tl.year", { y: ys })]);
    if (Math.ceil(ms / 6) === Math.ceil(me / 6)) out.push(["half", t(ms <= 6 ? "plan.tl.h1" : "plan.tl.h2")]);
    if (Math.ceil(ms / 3) === Math.ceil(me / 3)) out.push(["q", t("plan.tl.q" + Math.ceil(ms / 3))]);
    if (ms === me) out.push(["m", t("plan.tl.m", { m: ms })]);
    var ws = isoWeek(s), we = isoWeek(e);
    if (ws.year === we.year && ws.week === we.week) out.push(["w", t("plan.tl.w", { w: ws.week })]);
    return out;
  }
  function label(p) {
    if (p.tl_mode === "text" && p.tl_text) return p.tl_text;
    if (p.tl_mode && p.tl_mode !== "auto") {
      var o = shortcuts(p.start, p.end).filter(function (x) { return x[0] === p.tl_mode; })[0];
      if (o) return o[1];
    }
    return autoLabel(p.level, p.start, p.end);
  }
  /* default dates for a level: the period around today (or the parent's start), kept inside the parent */
  function defaultDates(level, parent) {
    var a = today();
    if (parent) { if (parent.start > a) a = parent.start; if (parent.end < a) a = parent.start; }
    var x = d(a), y = x.getFullYear(), mo = x.getMonth(), s, e;
    if (level === "long") { s = y + "-01-01"; e = (y + 4) + "-12-31"; }
    else if (level === "annual") { s = y + "-01-01"; e = y + "-12-31"; }
    else if (level === "quarter") { var q = Math.floor(mo / 3); s = ymd(new Date(y, q * 3, 1)); e = ymd(new Date(y, q * 3 + 3, 0)); }
    else if (level === "month") { s = ymd(new Date(y, mo, 1)); e = ymd(new Date(y, mo + 1, 0)); }
    else if (level === "week") { var w = (x.getDay() + 6) % 7; s = ymd(new Date(y, mo, x.getDate() - w)); e = ymd(new Date(y, mo, x.getDate() - w + 6)); }
    else { s = e = a; }
    if (parent) { if (s < parent.start) s = parent.start; if (e > parent.end) e = parent.end; if (e < s) { s = parent.start; e = parent.end; } }
    return [s, e];
  }
  function stateOf(p) {
    if (p.status === "done") return ["plan.state.done", "ok"];
    if (p.status === "cancelled") return ["plan.state.cancelled", ""];
    if (p.start > today()) return ["plan.state.not_started", ""];
    if (p.end < today()) return ["plan.state.ended", "warn"];
    return ["plan.state.in_progress", "info"];
  }
  function levelPill(level) { return O.pill(t("plan.level." + level), "info"); }
  function scopeTag(p) {
    if (p.level !== "long" && p.level !== "annual") return "";
    return " " + O.pill(p.dept_id ? p.dept : t("plan.unit"), p.dept_id ? "" : "info");
  }

  /* ---------- create / edit ---------- */
  function assigneeBox(selected) {
    var byDept = {};
    var order = [];
    META.candidates.forEach(function (c) {
      var k = c.dept || t("plan.no_dept");
      if (!byDept[k]) { byDept[k] = []; order.push(k); }
      byDept[k].push(c);
    });
    return '<div class="rlist" style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:3px;padding:6px 10px">' + order.map(function (k) {
      return '<div class="muted" style="font-size:12px;margin-top:4px">' + O.esc(k) + '</div><div class="opts">' + byDept[k].map(function (c) {
        return '<label><input type="checkbox" name="pl_as" value="' + c.id + '"' + (selected.indexOf(c.id) >= 0 ? " checked" : "") + "> " + O.esc(c.name) + "</label>";
      }).join("") + "</div>";
    }).join("") + "</div>";
  }

  /*
   * opts: {plan (edit) | parent (add child) | top: true (new main plan), levels, defaultSelf, onSaved}
   */
  function openForm(opts) {
    return meta().then(function () {
      var p = opts.plan || null, parent = opts.parent || null;
      var editing = !!p;
      // levels allowed here (caps.add of the parent, or any for a main plan); the default is one step below the parent
      var levels = editing ? LEVELS : (opts.levels && opts.levels.length ? opts.levels : LEVELS);
      var level = editing ? p.level : (!parent ? (levels.indexOf("annual") >= 0 ? "annual" : levels[0])
        : (levels.filter(function (l) { return LEVELS.indexOf(l) > LEVELS.indexOf(parent.level); })[0] || levels[0]));
      var dates = editing ? [p.start, p.end] : defaultDates(level, parent);
      var manager = editing ? p.caps.assign : (!parent ? true : opts.manager);
      var assigned = editing ? p.assignees.slice() : (opts.defaultSelf && META.me ? [META.me] : (parent && manager ? parent.assignees.slice() : []));
      var tlMode = editing ? p.tl_mode : "auto", tlText = editing ? p.tl_text : "";
      var box = document.createElement("div");
      var fields = [];
      fields.push({ name: "title", label: t("plan.field.title"), req: true, max: 150, span2: true });
      if (!editing && !parent && META.wide) {
        fields.push({ name: "dept_id", label: t("plan.field.scope"), type: "select", span2: true, int: true, empty: t("plan.unit_plan"), opts: META.mainDepts.map(function (x) { return [x.id, x.name]; }) });
      }
      fields.push({ name: "start", label: t("plan.field.start"), type: "date", req: true });
      fields.push({ name: "end", label: t("plan.field.end"), type: "date", req: true });
      fields.push({ name: "level_ro", label: t("plan.field.level"), type: "static", span2: true, html: '<span id="pl_lv"></span>' });
      var fields2 = [];
      if (parent || (p && p.parent_id)) fields2.push({ name: "weight", label: t("plan.field.weight"), hint: t("plan.weight_hint"), max: 6 });
      fields2.push({ name: "priority", label: t("plan.field.priority"), type: "select", opts: [["high", t("plan.priority.high")], ["medium", t("plan.priority.medium")], ["low", t("plan.priority.low")]] });
      fields2.push({ name: "goal", label: t("plan.field.goal"), type: "textarea", max: 5000 });
      fields2.push({ name: "content", label: t("plan.field.content"), type: "textarea", max: 5000 });
      fields2 = fields2.concat(O.cf.fields(META.custom || [], false));
      var vals = editing ? { title: p.title, start: p.start, end: p.end, weight: p.parent_id ? String(p.weight) : "", priority: p.priority, goal: p.goal, content: p.content }
        : { start: dates[0], end: dates[1], dept_id: "" };
      var cvals = O.cf.values(META.custom || [], editing && p.custom ? p.custom.values : {}, false);
      Object.keys(cvals).forEach(function (k) { vals[k] = cvals[k]; });
      var f1 = O.form(fields, vals), f2 = O.form(fields2, vals);
      var f = { values: function () { var a = f1.values(), b = f2.values(); Object.keys(b).forEach(function (k) { a[k] = b[k]; }); return a; }, errors: function (m) { f1.errors(m); f2.errors(m); } };
      box.appendChild(f1.el);
      if (parent) box.insertAdjacentHTML("afterbegin", '<p class="muted" style="margin-top:0">' + O.esc(t("plan.under", { title: parent.title })) + "</p>");
      if (!editing) {
        // quick picks for the dates: the parent's whole period, then one period of each allowed length
        var picks = (parent ? [["parent", t("plan.span.parent")]] : []).concat(levels.map(function (l) { return [l, t("plan.span." + l)]; }));
        var qp = document.createElement("div");
        qp.className = "fld span2";
        qp.innerHTML = "<label>" + O.esc(t("plan.span.pick")) + '</label><div class="chips">' + picks.map(function (x) {
          return '<button type="button" class="chip" data-span="' + x[0] + '">' + O.esc(x[1]) + "</button>"; }).join("") + "</div>";
        var lvFld = box.querySelector("#pl_lv").closest(".fld");
        lvFld.parentNode.insertBefore(qp, lvFld);
        qp.addEventListener("click", function (e) {
          var b = e.target.closest ? e.target.closest("[data-span]") : null;
          if (!b) return;
          var k = b.getAttribute("data-span"), dd = k === "parent" ? [parent.start, parent.end] : defaultDates(k, parent);
          box.querySelector('[name="start"]').value = dd[0];
          box.querySelector('[name="end"]').value = dd[1];
          drawTl();
        });
      }
      var tl = document.createElement("div");
      tl.className = "fld";
      tl.style.marginTop = "10px";
      box.appendChild(tl);
      function drawTl() {
        var v = f.values(), s = v.start, e = v.end, ok = s && e && e >= s, lv = ok ? levelOf(s, e) : "";
        var opts2 = shortcuts(s, e), auto = ok ? autoLabel(lv, s, e) : "";
        var lvBox = box.querySelector("#pl_lv"), bad = "";
        if (lv && !editing && levels.indexOf(lv) < 0) {
          bad = parent && LEVELS.indexOf(lv) < LEVELS.indexOf(parent.level) ? t("plan.err.longer_than_parent", { level: t("plan.level." + parent.level) }) : t("plan.err.member_span");
        }
        if (lvBox) lvBox.innerHTML = lv ? levelPill(lv) + ' <span class="' + (bad ? "em" : "muted") + '">' + O.esc(bad || t("plan.level_auto")) + "</span>" : "—";
        if (tlMode !== "text" && tlMode !== "auto" && !opts2.some(function (o) { return o[0] === tlMode; })) tlMode = "auto";
        tl.innerHTML = "<label>" + O.esc(t("plan.field.tl")) + '</label><div class="chips">' + (auto ? '<button type="button" class="chip' + (tlMode === "auto" ? " on" : "") + '" data-tl="auto">' + O.esc(t("plan.tl.auto", { label: auto })) + "</button>" : "") +
          opts2.filter(function (o) { return o[1] !== auto; }).map(function (o) { return '<button type="button" class="chip' + (tlMode === o[0] ? " on" : "") + '" data-tl="' + o[0] + '">' + O.esc(o[1]) + "</button>"; }).join("") +
          '</div><input type="text" class="inp" id="pl_tx" maxlength="20" style="margin-top:6px" placeholder="' + O.esc(t("plan.tl.own")) + '" value="' + O.esc(tlMode === "text" ? tlText : "") + '"><span class="hint">' + O.esc(t("plan.tl.hint")) + "</span>";
      }
      drawTl();
      tl.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest("[data-tl]") : null;
        if (b) { tlMode = b.getAttribute("data-tl"); tlText = ""; drawTl(); }
      });
      tl.addEventListener("input", function (e) { if (e.target.id === "pl_tx") { tlText = e.target.value.trim(); tlMode = tlText ? "text" : "auto"; } });
      box.addEventListener("change", function (e) {
        var n = e.target.getAttribute("name");
        if (n === "start" || n === "end") drawTl();
      });
      if (manager && META.candidates.length) {
        var as = document.createElement("div");
        as.className = "fld";
        as.style.marginTop = "10px";
        as.innerHTML = '<label><b class="req">*</b>' + O.esc(t("plan.field.assignees")) + "</label>" + assigneeBox(assigned) + '<span class="em" id="pl_as_err"></span>';
        box.appendChild(as);
      }
      f2.el.style.marginTop = "10px";
      box.appendChild(f2.el);
      O.modal({ title: editing ? t("plan.edit") : (parent ? t("plan.add_child") : t("plan.add_main")), width: 720, body: box, buttons: [{ t: t("common.cancel") }, { t: t("common.save"), cls: "pri", fn: function () {
        var v = f.values();
        var body = { title: v.title, start: v.start, end: v.end, priority: v.priority, goal: v.goal, content: v.content, tl_mode: tlMode, tl_text: tlMode === "text" ? tlText : "", custom: O.cf.collect(v) };
        if (v.weight !== undefined) body.weight = v.weight;
        if (!editing) { if (parent) body.parent_id = parent.id; if (v.dept_id) body.dept_id = v.dept_id; }
        if (manager && META.candidates.length) body.assignees = Array.prototype.map.call(box.querySelectorAll('input[name="pl_as"]:checked'), function (x) { return +x.value; });
        if (editing) body.version = p.version;
        var req = editing ? O.api("PUT", "/api/v1/plans/" + p.id, body) : O.api("POST", "/api/v1/plans", body);
        return req.then(function (r) {
          if (r.warnings && r.warnings.length) O.status(t("plan.saved_warn", { notes: r.warnings.join(t("common.list_sep")) }), "warn");
          else O.status(t("common.saved"));
          if (opts.onSaved) opts.onSaved(r);
        }, function (err) {
          if (err && err.fields) {
            f.errors(err.fields);
            var ae = box.querySelector("#pl_as_err");
            if (ae) ae.textContent = err.fields.assignees || "";
            O.status(err.message, "err");
            return Promise.reject(null);
          }
          return Promise.reject(err);
        });
      } }] });
    });
  }

  /* ---------- details ---------- */
  function openDetail(id, opts) {
    opts = opts || {};
    return meta().then(function () {
      return O.api("GET", "/api/v1/plans/" + id).then(function (p) {
        return (O.perf ? O.api("GET", "/api/v1/perf/plans/" + id) : Promise.resolve(null)).then(function (res) { return [p, res]; });
      }).then(function (both) {
        var p = both[0], res = both[1];
        var el = document.createElement("div");
        var st = stateOf(p);
        var rows = [
          [t("plan.field.level"), levelPill(p.level) + scopeTag(p)],
          [t("plan.field.path"), p.path.length ? p.path.map(function (x) { return O.esc(x.title); }).join(" › ") : O.esc(t("plan.top"))],
          [t("plan.field.time"), '<span class="mono">' + O.esc(p.start + " – " + p.end) + "</span>" + (label(p) !== autoLabel(p.level, p.start, p.end) ? ' <span class="muted">' + O.esc(t("plan.shown_as", { label: label(p) })) + "</span>" : "")],
          [t("plan.field.state"), O.pill(t(st[0]), st[1])],
          [t("plan.col.progress"), O.perf && p.progress !== undefined ? O.perf.progress(p.progress) : "—"],
          [t("plan.field.assignees"), O.esc(p.assignee_names.join(t("common.list_sep")) || "—")],
          [t("plan.field.owner"), O.esc(p.owner || "—")]
        ];
        if (p.parent_id) rows.push([t("plan.field.weight"), O.esc(p.weight + "%")]);
        if (p.priority) rows.push([t("plan.field.priority"), O.esc(t("plan.priority." + p.priority))]);
        if (p.goal) rows.push([t("plan.field.goal"), O.esc(p.goal).replace(/\n/g, "<br>")]);
        if (p.content) rows.push([t("plan.field.content"), O.esc(p.content).replace(/\n/g, "<br>")]);
        rows.push([t("plan.field.code"), '<span class="mono">' + O.esc(p.code) + "</span>"]);
        var wide = [t("plan.field.goal"), t("plan.field.content")];
        if (p.custom) O.cf.rows(p.custom.defs, p.custom.values).forEach(function (r) { rows.push([r[0], r[1]]); if (r[2]) wide.push(r[0]); });
        el.innerHTML = '<div class="fgrid">' + rows.map(function (r) { return '<div class="fld' + (wide.indexOf(r[0]) >= 0 ? " span2" : "") + '"><label>' + O.esc(r[0]) + '</label><div class="ro">' + r[1] + "</div></div>"; }).join("") + "</div>";
        if (p.children.length || p.caps.add.length) {
          var sumBad = p.children.length && Math.abs(p.children_weight - 100) > 0.01;
          el.insertAdjacentHTML("beforeend", '<h4 style="margin:16px 0 6px">' + O.esc(t("plan.children", { n: p.children.length })) + "</h4>" +
            (sumBad ? '<div class="note warn" style="margin-bottom:8px">' + O.esc(t("plan.warn.weights", { sum: p.children_weight })) + "</div>" : ""));
          var tb = O.table({
            cols: [
              { t: t("plan.field.title"), f: function (c) { return levelPill(c.level) + " " + O.esc(c.title); } },
              { t: t("plan.field.time"), f: function (c) { return '<span class="mono">' + O.esc(label(c)) + "</span>"; } },
              { t: t("plan.field.weight"), cls: "num", f: function (c) { return O.esc(c.weight + "%"); } },
              { t: t("plan.field.assignees"), f: function (c) { return O.esc(c.assignee_names.join(t("common.list_sep"))); } }
            ], rows: p.children, empty: t("plan.no_children")
          });
          el.appendChild(tb);
        }
        var refresh = function () { openDetail(id, opts); if (opts.onChanged) opts.onChanged(); };
        var btns = [];
        if (p.caps.del) btns.push({ t: t("common.delete"), cls: "danger", fn: function () { remove(p, function () { O.closeDrawer(); if (opts.onChanged) opts.onChanged(); }); return false; } });
        if (p.caps.add.length) btns.push({ t: t("plan.add_child"), fn: function () { openForm({ parent: p, levels: p.caps.add, manager: p.caps.assign || (META.manager && !p.dept_id), defaultSelf: !META.manager, onSaved: function () { openDetail(id, opts); if (opts.onChanged) opts.onChanged(); } }); return false; } });
        if (p.caps.edit) btns.push({ t: t("common.edit"), fn: function () { openForm({ plan: p, onSaved: refresh }); return false; } });
        if (p.caps.write) btns.push({ t: t("perf.write"), cls: "pri", fn: function () { O.perf.write(p.id, refresh); return false; } });
        btns.push({ t: t("common.close") });
        var tabs = [{ t: t("plan.detail"), el: el }];
        if (res) tabs.push({ t: t("perf.tab", { n: res.rows.filter(function (x) { return x.status !== "none"; }).length }), el: O.perf.listEl(res, refresh) });
        var dr = O.drawer({ title: p.title, sub: label(p), width: 760, tabs: tabs, buttons: btns });
        if (opts.tab && res) dr.show(1);
      });
    }).then(null, O.failed);
  }

  function remove(p, done) {
    O.api("GET", "/api/v1/plans/" + p.id + "/delete-impact").then(function (im) {
      if (im.blocked) { O.info(t("plan.delete"), "<p>" + O.esc(t("perf.err.delete_has_results")) + "</p>"); return; }
      O.confirm({ title: t("plan.delete"), msg: t("plan.delete_confirm", { title: im.title }) + (im.children ? " " + t("plan.delete_children", { n: im.children }) : "") +
        (im.results ? " " + t("plan.delete_results", { n: im.results }) : ""), ok: t("common.delete"), danger: true }).then(function (ok) {
        if (!ok) return;
        O.api("DELETE", "/api/v1/plans/" + p.id).then(function (r) { O.status(t("plan.deleted", { n: r.deleted })); if (done) done(); }, O.failed);
      });
    }, O.failed);
  }

  O.plan = { meta: meta, label: label, autoLabel: autoLabel, stateOf: stateOf, levelPill: levelPill, scopeTag: scopeTag, openForm: openForm, openDetail: openDetail, remove: remove, LEVELS: LEVELS };
})();
